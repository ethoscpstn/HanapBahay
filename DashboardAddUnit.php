<?php
session_start();

// Security constant for config
define('HANAPBAHAY_SECURE', true);

require 'mysql_connect.php';
require 'config_keys.php';
require 'includes/csrf.php';

// 🔐 Require owner login
if (!isset($_SESSION['owner_id'])) {
  header("Location: LoginModule.php");
  exit();
}

$errors = [];
$owner_id = (int)$_SESSION['owner_id'];

// 📝 Handle resubmit - pre-fill form with rejected listing data
$resubmit_data = null;
if (isset($_GET['resubmit']) && !empty($_GET['resubmit'])) {
  $resubmit_id = (int)$_GET['resubmit'];
  $stmt = $conn->prepare("SELECT * FROM tblistings WHERE id = ? AND owner_id = ? AND verification_status = 'rejected'");
  $stmt->bind_param("ii", $resubmit_id, $owner_id);
  $stmt->execute();
  $result = $stmt->get_result();
  $resubmit_data = $result->fetch_assoc();
  $stmt->close();
}

$prefill_mode = null;
if ($resubmit_data) {
  $prefill_mode = 'resubmit';
} elseif (isset($_GET['restore']) && !empty($_GET['restore'])) {
  $restore_id = (int)$_GET['restore'];
  $stmt = $conn->prepare("SELECT * FROM tblistings WHERE id = ? AND owner_id = ? AND is_archived = 1 AND is_deleted = 0");
  $stmt->bind_param("ii", $restore_id, $owner_id);
  $stmt->execute();
  $result = $stmt->get_result();
  $resubmit_data = $result->fetch_assoc();
  $stmt->close();
  if ($resubmit_data) {
    $prefill_mode = 'restore';
  }
}

// 📝 Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Verify CSRF token
  csrf_verify();

  // Basic sanitation
  $property_type = trim($_POST['property_type'] ?? '');
  $capacity      = (int)($_POST['capacity'] ?? 0);
  $total_units   = (int)($_POST['total_units'] ?? 1);
  $description   = trim($_POST['description'] ?? '');
  $price         = (float)($_POST['price'] ?? 0);
  $rental_type   = trim($_POST['rental_type'] ?? 'residential');

  $address   = trim($_POST['address'] ?? '');
  $latitude  = isset($_POST['latitude'])  ? (float)$_POST['latitude']  : null;
  $longitude = isset($_POST['longitude']) ? (float)$_POST['longitude'] : null;

  // amenities[] will be values like: wifi, parking, aircon, ...
  $amenities_arr = isset($_POST['amenities']) && is_array($_POST['amenities']) ? $_POST['amenities'] : [];
  $amenities_arr = array_map(function($a){ return strtolower(trim($a)); }, $amenities_arr);
  $amenities = implode(', ', $amenities_arr);

  // -------- Server-side validation --------
  if ($property_type === '') $errors[] = "Property Type is required.";
  if ($capacity <= 0)        $errors[] = "Capacity must be at least 1.";
  if ($total_units <= 0)     $errors[] = "Total units must be at least 1.";
  if ($price < 0)            $errors[] = "Price cannot be negative.";
  if ($address === '')       $errors[] = "Address is required.";

  // Coordinates should be present (from geocode flow)
  if ($latitude === null || $longitude === null) {
    $errors[] = "Could not determine map location for this address.";
  }

  // Government ID validation (required)
  $gov_id_path = null;
  if (empty($_FILES['gov_id']['name']) || $_FILES['gov_id']['error'] === UPLOAD_ERR_NO_FILE) {
    $errors[] = "Government ID is required for verification.";
  }

  // Legal documents validation based on rental type
  if ($rental_type === 'residential') {
    // Residential: Barangay permit required
    if (empty($_FILES['barangay_permit']['name']) || $_FILES['barangay_permit']['error'] === UPLOAD_ERR_NO_FILE) {
      $errors[] = "Barangay permit is required for residential rental properties.";
    }
  } elseif ($rental_type === 'commercial') {
    // Commercial: DTI/SEC, Business permit, and BIR permit required
    if (empty($_FILES['dti_sec_permit']['name']) || $_FILES['dti_sec_permit']['error'] === UPLOAD_ERR_NO_FILE) {
      $errors[] = "DTI or SEC permit is required for commercial rental properties.";
    }
    if (empty($_FILES['business_permit']['name']) || $_FILES['business_permit']['error'] === UPLOAD_ERR_NO_FILE) {
      $errors[] = "Mayor's/Business permit is required for commercial rental properties.";
    }
    if (empty($_FILES['bir_permit']['name']) || $_FILES['bir_permit']['error'] === UPLOAD_ERR_NO_FILE) {
      $errors[] = "BIR permit is required for commercial rental properties.";
    }
  }

  // Property photos validation (1-3 required)
  $photo_count = 0;
  if (isset($_FILES['property_photos'])) {
    foreach ($_FILES['property_photos']['error'] as $err) {
      if ($err !== UPLOAD_ERR_NO_FILE) $photo_count++;
    }
  }
  if ($photo_count < 1) {
    $errors[] = "At least 1 property photo is required.";
  } elseif ($photo_count > 3) {
    $errors[] = "Maximum 3 property photos allowed.";
  }

  // Store form data for re-population on error
  $form_data = [
    'property_type' => $property_type,
    'capacity' => $capacity,
    'total_units' => $total_units,
    'description' => $description,
    'price' => $price,
    'rental_type' => $rental_type,
    'address' => $address,
    'latitude' => $latitude,
    'longitude' => $longitude,
    'amenities' => $amenities_arr
  ];
  $_SESSION['form_data'] = $form_data;

// Process government ID upload
  if (!empty($_FILES['gov_id']['name']) && $_FILES['gov_id']['error'] === UPLOAD_ERR_OK) {
    $allowed = [
      'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
      'image/gif' => 'gif', 'application/pdf' => 'pdf'
    ];
    $mime = function_exists('mime_content_type') ? mime_content_type($_FILES['gov_id']['tmp_name']) : $_FILES['gov_id']['type'];
    if (!isset($allowed[$mime])) {
      $errors[] = "Invalid government ID file type. Only JPG, PNG, WebP, GIF, or PDF allowed.";
    } elseif ($_FILES['gov_id']['size'] > 10 * 1024 * 1024) {
      $errors[] = "Government ID file too large (max 10 MB).";
    } else {
      $dir = __DIR__ . "/uploads/gov_ids/" . date('Ymd');
      if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
      $ext = $allowed[$mime];
      $safeName = "gov_" . $owner_id . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
      $abs = $dir . "/" . $safeName;
      if (@move_uploaded_file($_FILES['gov_id']['tmp_name'], $abs)) {
        $gov_id_path = "uploads/gov_ids/" . date('Ymd') . "/" . $safeName;
      } else {
        $errors[] = "Failed to save government ID.";
      }
    }
  }

  // Process legal documents based on rental type
  $barangay_permit_path = null;
  $dti_sec_permit_path = null;
  $business_permit_path = null;
  $bir_permit_path = null;

  $allowed_docs = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
    'image/gif' => 'gif', 'application/pdf' => 'pdf'
  ];

  // Process Barangay Permit (Residential)
  if (!$errors && $rental_type === 'residential' && !empty($_FILES['barangay_permit']['name']) && $_FILES['barangay_permit']['error'] === UPLOAD_ERR_OK) {
    $mime = function_exists('mime_content_type') ? mime_content_type($_FILES['barangay_permit']['tmp_name']) : $_FILES['barangay_permit']['type'];
    if (!isset($allowed_docs[$mime])) {
      $errors[] = "Invalid barangay permit file type. Only JPG, PNG, WebP, GIF, or PDF allowed.";
    } elseif ($_FILES['barangay_permit']['size'] > 10 * 1024 * 1024) {
      $errors[] = "Barangay permit file too large (max 10 MB).";
    } else {
      $dir = __DIR__ . "/uploads/legal_docs/" . date('Ymd');
      if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
      $ext = $allowed_docs[$mime];
      $safeName = "barangay_" . $owner_id . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
      $abs = $dir . "/" . $safeName;
      if (@move_uploaded_file($_FILES['barangay_permit']['tmp_name'], $abs)) {
        $barangay_permit_path = "uploads/legal_docs/" . date('Ymd') . "/" . $safeName;
      } else {
        $errors[] = "Failed to save barangay permit.";
      }
    }
  }

  // Process DTI/SEC Permit (Commercial)
  if (!$errors && $rental_type === 'commercial' && !empty($_FILES['dti_sec_permit']['name']) && $_FILES['dti_sec_permit']['error'] === UPLOAD_ERR_OK) {
    $mime = function_exists('mime_content_type') ? mime_content_type($_FILES['dti_sec_permit']['tmp_name']) : $_FILES['dti_sec_permit']['type'];
    if (!isset($allowed_docs[$mime])) {
      $errors[] = "Invalid DTI/SEC permit file type. Only JPG, PNG, WebP, GIF, or PDF allowed.";
    } elseif ($_FILES['dti_sec_permit']['size'] > 10 * 1024 * 1024) {
      $errors[] = "DTI/SEC permit file too large (max 10 MB).";
    } else {
      $dir = __DIR__ . "/uploads/legal_docs/" . date('Ymd');
      if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
      $ext = $allowed_docs[$mime];
      $safeName = "dti_sec_" . $owner_id . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
      $abs = $dir . "/" . $safeName;
      if (@move_uploaded_file($_FILES['dti_sec_permit']['tmp_name'], $abs)) {
        $dti_sec_permit_path = "uploads/legal_docs/" . date('Ymd') . "/" . $safeName;
      } else {
        $errors[] = "Failed to save DTI/SEC permit.";
      }
    }
  }

  // Process Business Permit (Commercial)
  if (!$errors && $rental_type === 'commercial' && !empty($_FILES['business_permit']['name']) && $_FILES['business_permit']['error'] === UPLOAD_ERR_OK) {
    $mime = function_exists('mime_content_type') ? mime_content_type($_FILES['business_permit']['tmp_name']) : $_FILES['business_permit']['type'];
    if (!isset($allowed_docs[$mime])) {
      $errors[] = "Invalid business permit file type. Only JPG, PNG, WebP, GIF, or PDF allowed.";
    } elseif ($_FILES['business_permit']['size'] > 10 * 1024 * 1024) {
      $errors[] = "Business permit file too large (max 10 MB).";
    } else {
      $dir = __DIR__ . "/uploads/legal_docs/" . date('Ymd');
      if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
      $ext = $allowed_docs[$mime];
      $safeName = "business_" . $owner_id . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
      $abs = $dir . "/" . $safeName;
      if (@move_uploaded_file($_FILES['business_permit']['tmp_name'], $abs)) {
        $business_permit_path = "uploads/legal_docs/" . date('Ymd') . "/" . $safeName;
      } else {
        $errors[] = "Failed to save business permit.";
      }
    }
  }

  // Process BIR Permit (Commercial)
  if (!$errors && $rental_type === 'commercial' && !empty($_FILES['bir_permit']['name']) && $_FILES['bir_permit']['error'] === UPLOAD_ERR_OK) {
    $mime = function_exists('mime_content_type') ? mime_content_type($_FILES['bir_permit']['tmp_name']) : $_FILES['bir_permit']['type'];
    if (!isset($allowed_docs[$mime])) {
      $errors[] = "Invalid BIR permit file type. Only JPG, PNG, WebP, GIF, or PDF allowed.";
    } elseif ($_FILES['bir_permit']['size'] > 10 * 1024 * 1024) {
      $errors[] = "BIR permit file too large (max 10 MB).";
    } else {
      $dir = __DIR__ . "/uploads/legal_docs/" . date('Ymd');
      if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
      $ext = $allowed_docs[$mime];
      $safeName = "bir_" . $owner_id . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
      $abs = $dir . "/" . $safeName;
      if (@move_uploaded_file($_FILES['bir_permit']['tmp_name'], $abs)) {
        $bir_permit_path = "uploads/legal_docs/" . date('Ymd') . "/" . $safeName;
      } else {
        $errors[] = "Failed to save BIR permit.";
      }
    }
  }

  // Process property photos (1-3 photos)
  $property_photos = [];
  if (!$errors && isset($_FILES['property_photos'])) {
    $allowed_img = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $dir = __DIR__ . "/uploads/property_photos/" . date('Ymd');
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    for ($i = 0; $i < count($_FILES['property_photos']['name']); $i++) {
      if ($_FILES['property_photos']['error'][$i] === UPLOAD_ERR_OK) {
        $mime = function_exists('mime_content_type') ?
                mime_content_type($_FILES['property_photos']['tmp_name'][$i]) :
                $_FILES['property_photos']['type'][$i];
        
        $isValid = true;
        if (!isset($allowed_img[$mime])) {
          $errors[] = "Photo #" . ($i + 1) . ": Invalid file type. Only JPG, PNG, WebP, or GIF allowed.";
          $isValid = false;
        }
        if ($_FILES['property_photos']['size'][$i] > 5 * 1024 * 1024) {
          $errors[] = "Photo #" . ($i + 1) . ": File too large (max 5 MB).";
          $isValid = false;
        }
        
        if ($isValid) {
          $ext = $allowed_img[$mime];
          $safeName = "photo_" . $owner_id . "_" . time() . "_" . $i . "_" . bin2hex(random_bytes(4)) . "." . $ext;
          $abs = $dir . "/" . $safeName;
          if (@move_uploaded_file($_FILES['property_photos']['tmp_name'][$i], $abs)) {
            $property_photos[] = "uploads/property_photos/" . date('Ymd') . "/" . $safeName;
          } else {
            $errors[] = "Failed to save photo #" . ($i + 1) . ".";
          }
        }
      }
    }
  }

  if (!$errors) {
    // Convert photos array to JSON
    $photos_json = json_encode($property_photos);

    // Handle kitchen options
    $kitchen = $_POST['kitchen'] ?? 'No';
    $kitchen_type = $_POST['kitchen_type'] ?? 'None';
    $kitchen_facility = $_POST['kitchen_facility'] ?? '';
    if ($kitchen === 'No') {
      $kitchen_type = 'None';
      $kitchen_facility = '';
    }

    // Convert photos array to JSON
    $photos_json = json_encode($property_photos);
    $verification_status = 'pending';

    // Insert listing with verification status = pending
    $sql = "INSERT INTO tblistings
            (title, description, address, latitude, longitude, price, capacity,
             total_units, occupied_units, amenities, owner_id, gov_id_path,
             property_photos, rental_type, barangay_permit_path, dti_sec_permit_path,
             business_permit_path, bir_permit_path, verification_status,
             kitchen, kitchen_type, kitchen_facility)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      die('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param(
      'sssdddiisisssssssssss',
      $property_type,
      $description,
      $address,
      $latitude,
      $longitude,
      $price,
      $capacity,
      $total_units,
      $amenities,
      $owner_id,
      $gov_id_path,
      $photos_json,
      $rental_type,
      $barangay_permit_path,
      $dti_sec_permit_path,
      $business_permit_path,
      $bir_permit_path,
      $verification_status,
      $kitchen,
      $kitchen_type,
      $kitchen_facility
    );

    if ($stmt->execute()) {
      $new_listing_id = $stmt->insert_id;
      $stmt->close();

      // If this submission originated from a restore/resubmit flow,
      // retire the old listing so it no longer shows up in dashboards/analytics.
      if (!empty($_POST['source_listing_id'])) {
        $source_listing_id = (int)$_POST['source_listing_id'];
        if ($source_listing_id > 0) {
          $cleanup = $conn->prepare("
            UPDATE tblistings
            SET is_deleted = 1,
                is_visible = 0,
                is_available = 0,
                is_archived = 1
            WHERE id = ? AND owner_id = ?
          ");
          if ($cleanup) {
            $cleanup->bind_param('ii', $source_listing_id, $owner_id);
            $cleanup->execute();
            $cleanup->close();
          }
        }
      }

      $conn->close();
      // Regenerate CSRF token after successful submission
      csrf_regenerate();
      $_SESSION['show_success_popup'] = true;
      $_SESSION['success_message'] = "Property submitted successfully! It is now pending admin verification.";
      // Clear form data from session after successful submission
      if (isset($_SESSION['form_data'])) {
          unset($_SESSION['form_data']);
      }
      header("Location: DashboardUO.php");
      exit();
    } else {
      $errors[] = "Database error: " . $stmt->error;
      $stmt->close();
    }
  }
}

if (!function_exists('hb_doc_sample_svg_data')) {
  function hb_doc_sample_svg_data($title, $subtitle = '')
  {
    $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $subtitle = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');
    $svg = <<<SVG
<svg width="160" height="100" viewBox="0 0 160 100" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="grad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#2563eb;stop-opacity:0.18"/>
      <stop offset="100%" style="stop-color:#1d4ed8;stop-opacity:0.42"/>
    </linearGradient>
  </defs>
  <rect x="1" y="1" width="158" height="98" rx="10" ry="10" fill="var(--hb-card)" stroke="var(--hb-border)" stroke-width="2"/>
  <rect x="12" y="16" width="136" height="68" rx="8" ry="8" fill="url(#grad)" opacity="0.25"/>
  <rect x="22" y="26" width="52" height="52" rx="6" ry="6" fill="var(--hb-border)"/>
  <circle cx="48" cy="42" r="12" fill="var(--hb-border)"/>
  <rect x="88" y="32" width="50" height="10" rx="4" ry="4" fill="var(--hb-border)"/>
  <rect x="88" y="48" width="40" height="8" rx="4" ry="4" fill="var(--hb-border)"/>
  <rect x="88" y="62" width="36" height="6" rx="3" ry="3" fill="var(--hb-border)"/>
  <text x="80" y="88" text-anchor="middle" font-family="Arial,sans-serif" font-size="13" fill="var(--hb-ink)">{$title}</text>
  <text x="80" y="96" text-anchor="middle" font-family="Arial,sans-serif" font-size="10" fill="var(--hb-muted)">{$subtitle}</text>
</svg>
SVG;
    return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
  }
}

if (!function_exists('hb_doc_sample_src')) {
  /**
   * Returns a sample document thumbnail.
   * Drop PNG/JPG files under Assets/doc_samples/ and pass the filename here.
   * Falls back to an inline SVG hint if the sample file is missing.
   */
  function hb_doc_sample_src($filename, $title, $subtitle = '')
  {
    $filename = ltrim((string)$filename, '/');
    if ($filename !== '') {
      $baseDir = __DIR__ . '/Assets/doc_samples';
      $absPath = $baseDir . '/' . $filename;
      if (is_file($absPath)) {
        $version = filemtime($absPath) ?: time();
        return 'Assets/doc_samples/' . $filename . '?v=' . $version;
      }
    }
    return hb_doc_sample_svg_data($title, $subtitle);
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>HanapBahay - Add Unit</title>
  <link rel="icon" type="image/png" sizes="16x16" href="Assets/HanapBahayTablogo.png?v=2">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="add_property.css?v=41">
  <link rel="stylesheet" href="darkmode.css">
  <style>
    .dashboard-header{
      display:flex; align-items:center; justify-content:space-between;
      padding:14px 16px; background:#fff; border-bottom:1px solid #e5e7eb;
      position:sticky; top:0; z-index:10;
    }
    .dashboard-header .logo{ height:36px; }
    .btn-hb-back{ background:#8B4513; color:#fff; }
    .btn-hb-back:hover{ color:#fff; opacity:.92; }
    
    .form-help{ font-size:.825rem; color:#6b7280; margin-top:.35rem; }
    .amenities-grid{
      display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:.35rem 1rem;
    }
    @media (max-width: 576px){
      .amenities-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    .doc-sample{
      display:flex; align-items:center; gap:.75rem; margin-top:.5rem;
      padding:.75rem; border:1px dashed #d1d5db; border-radius:.75rem;
      background:#f9fafb;
    }
    .doc-sample img{
      width:96px; height:72px; object-fit:cover; border-radius:.5rem;
      box-shadow:0 4px 12px rgba(15, 23, 42, 0.12); background:#fff;
    }
    .doc-sample-trigger{
      border:none; background:transparent; padding:0; line-height:0; cursor:pointer;
    }
    .doc-sample-trigger:focus-visible{
      outline:2px solid #2563eb; outline-offset:2px;
    }
    .doc-sample-text{
      font-size:.82rem; color:#4b5563; line-height:1.3;
    }
    .doc-sample-modal{
      position:fixed; inset:0; background:rgba(17,24,39,0.62);
      display:flex; align-items:center; justify-content:center;
      z-index:1050; padding:24px; backdrop-filter: blur(2px);
      visibility:hidden; opacity:0; transition:opacity .2s ease;
    }
    .doc-sample-modal.active{
      visibility:visible; opacity:1;
    }
    .doc-sample-modal .doc-sample-dialog{
      background:#fff; border-radius:12px; padding:16px;
      max-width:640px; width:100%; box-shadow:0 20px 45px rgba(15,23,42,0.25);
      position:relative;
    }
    .doc-sample-modal img{
      width:100%; height:auto; border-radius:8px; display:block;
    }
    .doc-sample-modal h5{
      margin-top:12px; margin-bottom:4px; font-weight:700;
    }
    .doc-sample-modal p{
      margin:0; color:#4b5563; font-size:.9rem;
    }
    .doc-sample-close{
      position:absolute; inset:12px 12px auto auto;
      border:none; background:#111827; color:#fff;
      width:32px; height:32px; border-radius:50%;
      display:flex; align-items:center; justify-content:center;
      cursor:pointer; font-size:18px; line-height:1;
    }
  </style>
</head>
<body class="dashboard-bg">
  <div class="d-flex" id="dashboardWrapper">
    <!-- Page Content -->
    <div id="pageContent" class="flex-grow-1">
      <header class="dashboard-header">
        <img src="Assets/Logo1.png" alt="HanapBahay Logo" class="logo" />
        <a href="DashboardUO.php" class="btn btn-hb-back">
          <i class="bi bi-arrow-left"></i> Back to Dashboard
        </a>
      </header>

      <!-- Errors -->
      <?php if (!empty($errors)): ?>
        <div class="container mt-3">
          <div class="alert alert-danger">
            <strong>There were problems with your submission:</strong>
            <ul class="mb-0">
              <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      <?php endif; ?>

      <!-- Add Listing Form -->
      <section class="mb-4 p-4">
        <div class="container">
          <form method="POST" action="" enctype="multipart/form-data" onsubmit="return geocodeAndSubmit(event)" class="bg-white p-4 rounded shadow-sm">
            <?php echo csrf_field(); ?>

            <!-- Rental Type -->
            <div class="mb-3">
              <label class="form-label">Rental Type <span class="text-danger">*</span></label>
              <select name="rental_type" id="rentalType" class="form-select" required>
                <?php
                $selected_rental = isset($_SESSION['form_data']) ? $_SESSION['form_data']['rental_type'] : 
                                ($resubmit_data ? $resubmit_data['rental_type'] : '');
                ?>
                <option value="residential" <?= $selected_rental === 'residential' ? 'selected' : '' ?>>Residential (for rental only)</option>
                <option value="commercial" <?= $selected_rental === 'commercial' ? 'selected' : '' ?>>Commercial (apartment rental business)</option>
              </select>
              <div class="form-help">Select the type of rental to determine required legal documents.</div>
            </div>

            <!-- Property Type -->
            <div class="mb-3">
              <label class="form-label">Property Type <span class="text-danger">*</span></label>
              <?php if ($resubmit_data): ?>
                <div class="alert alert-warning mb-2">
                  <i class="bi bi-info-circle"></i>
                  <?php if ($prefill_mode === 'resubmit'): ?>
                    Resubmitting rejected listing: <strong><?= htmlspecialchars($resubmit_data['title']) ?></strong><br>
                    <small>Rejection reason: <?= htmlspecialchars($resubmit_data['rejection_reason'] ?? 'Not specified') ?></small>
                  <?php else: ?>
                    Restoring archived listing: <strong><?= htmlspecialchars($resubmit_data['title']) ?></strong><br>
                    <small>You can review the details below and resubmit updated documents for verification.</small>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <select name="property_type" id="propertyType" class="form-select" required>
                <option value="">-- Select Type --</option>
                <option value="Apartment" <?= ($resubmit_data && $resubmit_data['title'] === 'Apartment') ? 'selected' : '' ?>>Apartment</option>
                <option value="Condominium" <?= ($resubmit_data && $resubmit_data['title'] === 'Condominium') ? 'selected' : '' ?>>Condominium</option>
                <option value="House" <?= ($resubmit_data && $resubmit_data['title'] === 'House') ? 'selected' : '' ?>>House</option>
                <option value="Studio" <?= ($resubmit_data && $resubmit_data['title'] === 'Studio') ? 'selected' : '' ?>>Studio</option>
              </select>
            </div>

            <!-- Capacity and Total Units - Side by Side -->
            <div class="row mb-3">
              <div class="col-md-6">
                <label class="form-label">Capacity (per unit) <span class="text-danger">*</span></label>
                <input type="number" name="capacity" class="form-control" min="1" step="1" value="<?= $resubmit_data ? (int)$resubmit_data['capacity'] : '' ?>" required>
                <small class="text-muted">Number of people per unit</small>
              </div>
              <div class="col-md-6">
                <label class="form-label">Total Units Available <span class="text-danger">*</span></label>
                <input type="number" name="total_units" id="totalUnitsInput" class="form-control" min="1" step="1" value="<?= $resubmit_data ? (int)$resubmit_data['total_units'] : '1' ?>" required>
                <small class="text-muted">How many units can be rented?</small>
              </div>
            </div>

            <!-- Bedrooms and Unit Size - Side by Side -->
            <div class="row mb-3">
              <div class="col-md-6">
                <label class="form-label">Number of Bedrooms</label>
                <input type="number" name="bedroom" id="bedroomInput" class="form-control" min="0" step="1" value="<?= $resubmit_data ? (int)$resubmit_data['bedroom'] : '1' ?>">
                <small class="text-muted">Set to 0 for studio units with no separate bedroom</small>
              </div>
              <div class="col-md-6">
                <label class="form-label">Unit Size (sqm)</label>
                <input type="number" name="unit_sqm" id="sqmInput" class="form-control" min="1" step="0.1" value="<?= $resubmit_data ? (float)$resubmit_data['unit_sqm'] : '20' ?>">
              </div>
            </div>

            <!-- Kitchen Details - Side by Side -->
            <div class="row mb-3">
              <div class="col-md-6">
                <label class="form-label">Kitchen Available</label>
                <select name="kitchen" id="kitchenInput" class="form-select">
                  <option value="Yes" <?= ($resubmit_data && $resubmit_data['kitchen'] === 'Yes') ? 'selected' : '' ?>>Yes</option>
                  <option value="No" <?= ($resubmit_data && $resubmit_data['kitchen'] === 'No') ? 'selected' : '' ?>>No</option>
                </select>
              </div>
              <div class="col-md-6" id="kitchenTypeContainer">
                <label class="form-label">Kitchen Access</label>
                <select name="kitchen_type" id="kitchenTypeInput" class="form-select">
                  <option value="Private" <?= ($resubmit_data && $resubmit_data['kitchen_type'] === 'Private') ? 'selected' : '' ?>>Private</option>
                  <option value="Shared" <?= ($resubmit_data && $resubmit_data['kitchen_type'] === 'Shared') ? 'selected' : '' ?>>Shared</option>
                </select>
                <select name="kitchen_facility" id="kitchenFacilityInput" class="form-select mt-2">
                  <option value="">-- Select Kitchen Facility --</option>
                  <option value="Gas" <?= ($resubmit_data && $resubmit_data['kitchen_facility'] === 'Gas') ? 'selected' : '' ?>>Gas</option>
                  <option value="Electric" <?= ($resubmit_data && $resubmit_data['kitchen_facility'] === 'Electric') ? 'selected' : '' ?>>Electric</option>
                  <option value="Both" <?= ($resubmit_data && $resubmit_data['kitchen_facility'] === 'Both') ? 'selected' : '' ?>>Both Gas and Electric</option>
                </select>
              </div>
            </div>

            <!-- Policies - Side by Side -->
            <div class="row mb-3">
              <div class="col-md-6">
                <label class="form-label">Gender Restriction</label>
                <select name="gender_specific" id="genderInput" class="form-select">
                  <option value="Mixed" <?= ($resubmit_data && $resubmit_data['gender_specific'] === 'Mixed') ? 'selected' : '' ?>>Mixed</option>
                  <option value="Male" <?= ($resubmit_data && $resubmit_data['gender_specific'] === 'Male') ? 'selected' : '' ?>>Male Only</option>
                  <option value="Female" <?= ($resubmit_data && $resubmit_data['gender_specific'] === 'Female') ? 'selected' : '' ?>>Female Only</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Pet Policy</label>
                <select name="pets" id="petsInput" class="form-select">
                  <option value="Allowed" <?= ($resubmit_data && $resubmit_data['pets'] === 'Allowed') ? 'selected' : '' ?>>Allowed</option>
                  <option value="Not Allowed" <?= ($resubmit_data && $resubmit_data['pets'] === 'Not Allowed') ? 'selected' : '' ?>>Not Allowed</option>
                </select>
              </div>
            </div>

            <!-- Description -->
            <div class="mb-3">
              <label class="form-label">Description</label>
              <textarea name="description" class="form-control" rows="4"><?= $resubmit_data ? htmlspecialchars($resubmit_data['description']) : '' ?></textarea>
            </div>

            <!-- Amenities -->
            <div class="mb-2">
              <label class="form-label">Amenities</label>
              <div class="amenities-grid">
                <?php
                  $ALL_AMENITIES = [
                    'wifi' => 'Wi-Fi',
                    'parking' => 'Parking',
                    'aircon' => 'Air Conditioning',
                    'laundry' => 'Laundry',
                    'furnished' => 'Furnished',
                    'elevator' => 'Elevator',
                    'security' => 'Security/CCTV',
                    'balcony' => 'Balcony',
                    'gym' => 'Gym',
                    'pool' => 'Pool',
                    'bathroom' => 'Bathroom',
                    'sink' => 'Sink',
                    'electricity' => 'Electricity (Submeter)',
                    'water' => 'Water (Submeter)'
                  ];

                  // Parse existing amenities for resubmit
                  $selected_amenities = [];
                  if ($resubmit_data && !empty($resubmit_data['amenities'])) {
                    $selected_amenities = array_map('trim', explode(',', $resubmit_data['amenities']));
                  }
                ?>
                <?php foreach ($ALL_AMENITIES as $key => $label): ?>
                  <label class="form-check">
                    <input class="form-check-input" type="checkbox" name="amenities[]" value="<?= $key ?>" <?= in_array($key, $selected_amenities) ? 'checked' : '' ?>>
                    <span class="form-check-label"><?= htmlspecialchars($label) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Address -->
            <div class="mb-3">
              <label class="form-label">Address <span class="text-danger">*</span></label>
              <input type="text" id="addressInput" name="address" class="form-control" placeholder="Start typing and pick from suggestions" value="<?= $resubmit_data ? htmlspecialchars($resubmit_data['address']) : '' ?>" required>
            </div>

            <!-- Price with Suggest Button -->
            <div class="mb-3">
              <label class="form-label">Price (₱) <span class="text-danger">*</span></label>
              <div class="d-flex gap-2">
                <input type="number" name="price" id="priceInput" class="form-control" min="0" step="1" inputmode="numeric" pattern="[0-9]*" value="<?= $resubmit_data ? round($resubmit_data['price']) : '' ?>" required>
                <button type="button" class="btn btn-outline-primary" id="btnSuggestPrice">
                  <span class="btn-text">Suggest Price</span>
                  <span class="btn-loading" style="display:none;">
                    <span class="spinner-border spinner-border-sm" role="status"></span>
                    Loading...
                  </span>
                </button>
              </div>
              <div class="form-help" id="priceHint">Click "Suggest Price" to get a model-based estimate.</div>
            </div>

            <hr class="my-4">
            <h5 class="mb-3">Verification Documents</h5>

            <?php if ($resubmit_data): ?>
              <div class="alert alert-info mb-3">
                <i class="bi bi-info-circle"></i> <strong>Note:</strong> You must re-upload all required documents (Government ID and legal documents) for verification.
              </div>
            <?php endif; ?>

            <!-- Government ID -->
            <div class="mb-3">
              <label class="form-label">Government ID <span class="text-danger">*</span></label>
              <input type="file" name="gov_id" id="govIdInput" class="form-control" accept="image/*,.pdf" required>
              <div class="form-help">Upload a valid government ID (e.g., driver's license, passport). Accepted: JPG, PNG, WebP, GIF, PDF (max 10 MB).</div>
              <div id="govIdError" class="text-danger small mt-1" style="display: none;"></div>
            <div class="doc-sample">
              <button type="button"
                      class="doc-sample-trigger"
                      data-sample-src="<?= htmlspecialchars(hb_doc_sample_src('gov-id-sample.png', 'Valid ID', 'Front side & readable details'), ENT_QUOTES) ?>"
                      data-sample-title="Valid ID"
                      data-sample-desc="Front side & readable details">
                <img src="<?= htmlspecialchars(hb_doc_sample_src('gov-id-sample.png', 'Valid ID', 'Front side & readable details'), ENT_QUOTES) ?>" alt="Sample government ID preview">
              </button>
              <div class="doc-sample-text">
                <strong>Sample View</strong><br>
                Capture the entire ID, remove glare, and ensure text and photo are clear.
              </div>
            </div>
            </div>

            <!-- Legal Documents for Residential -->
            <div id="residentialDocs" class="legal-docs-section">
              <h6 class="mb-2">Residential Rental Documents</h6>
              <div class="mb-3">
                <label class="form-label">Barangay Permit <span class="text-danger">*</span></label>
                <input type="file" name="barangay_permit" id="barangayPermitInput" class="form-control" accept="image/*,.pdf">
                <div class="form-help">Required for residential rental properties. Accepted: JPG, PNG, WebP, GIF, PDF (max 10 MB).</div>
                <div id="barangayPermitError" class="text-danger small mt-1" style="display: none;"></div>
                <div class="doc-sample">
                  <button type="button"
                          class="doc-sample-trigger"
                          data-sample-src="<?= htmlspecialchars(hb_doc_sample_src('barangay-permit-sample.png', 'Barangay Permit', 'Include Barangay seal & signature'), ENT_QUOTES) ?>"
                          data-sample-title="Barangay Permit"
                          data-sample-desc="Include Barangay seal & signature">
                    <img src="<?= htmlspecialchars(hb_doc_sample_src('barangay-permit-sample.png', 'Barangay Permit', 'Include Barangay seal & signature'), ENT_QUOTES) ?>" alt="Sample barangay permit preview">
                  </button>
                  <div class="doc-sample-text">
                    <strong>Sample View</strong><br>
                    Upload the most recent permit showing the Barangay seal, signature, and issuance date.
                  </div>
                </div>
              </div>
            </div>

            <!-- Legal Documents for Commercial -->
            <div id="commercialDocs" class="legal-docs-section" style="display: none;">
              <h6 class="mb-2">Commercial Rental Documents</h6>
              <div class="mb-3">
                <label class="form-label">DTI or SEC Permit <span class="text-danger">*</span></label>
                <input type="file" name="dti_sec_permit" id="dtiSecPermitInput" class="form-control" accept="image/*,.pdf">
                <div class="form-help">Department of Trade and Industry or Securities and Exchange Commission permit. Accepted: JPG, PNG, WebP, GIF, PDF (max 10 MB).</div>
                <div id="dtiSecPermitError" class="text-danger small mt-1" style="display: none;"></div>
                <div class="doc-sample">
                  <button type="button"
                          class="doc-sample-trigger"
                          data-sample-src="<?= htmlspecialchars(hb_doc_sample_src('dti-sec-sample.png', 'DTI / SEC', 'Show registration number clearly'), ENT_QUOTES) ?>"
                          data-sample-title="DTI / SEC Permit"
                          data-sample-desc="Show registration number clearly">
                    <img src="<?= htmlspecialchars(hb_doc_sample_src('dti-sec-sample.png', 'DTI / SEC', 'Show registration number clearly'), ENT_QUOTES) ?>" alt="Sample DTI or SEC permit preview">
                  </button>
                  <div class="doc-sample-text">
                    <strong>Sample View</strong><br>
                    Ensure the registration number, business name, and validity dates are visible.
                  </div>
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">Mayor's / Business Permit <span class="text-danger">*</span></label>
                <input type="file" name="business_permit" id="businessPermitInput" class="form-control" accept="image/*,.pdf">
                <div class="form-help">Mayor's permit or business permit. Accepted: JPG, PNG, WebP, GIF, PDF (max 10 MB).</div>
                <div id="businessPermitError" class="text-danger small mt-1" style="display: none;"></div>
                <div class="doc-sample">
                  <button type="button"
                          class="doc-sample-trigger"
                          data-sample-src="<?= htmlspecialchars(hb_doc_sample_src('business-permit-sample.png', 'Business Permit', 'Highlight current year & official seal'), ENT_QUOTES) ?>"
                          data-sample-title="Mayor's / Business Permit"
                          data-sample-desc="Highlight current year & official seal">
                    <img src="<?= htmlspecialchars(hb_doc_sample_src('business-permit-sample.png', 'Business Permit', 'Highlight current year & official seal'), ENT_QUOTES) ?>" alt="Sample business permit preview">
                  </button>
                  <div class="doc-sample-text">
                    <strong>Sample View</strong><br>
                    Use the current permit that shows the LGU seal and signatures without cropping.
                  </div>
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">BIR Permit <span class="text-danger">*</span></label>
                <input type="file" name="bir_permit" id="birPermitInput" class="form-control" accept="image/*,.pdf">
                <div class="form-help">Bureau of Internal Revenue permit. Accepted: JPG, PNG, WebP, GIF, PDF (max 10 MB).</div>
                <div id="birPermitError" class="text-danger small mt-1" style="display: none;"></div>
                <div class="doc-sample">
                  <button type="button"
                          class="doc-sample-trigger"
                          data-sample-src="<?= htmlspecialchars(hb_doc_sample_src('bir-permit-sample.png', 'BIR Certificate', 'Include TIN & official signature'), ENT_QUOTES) ?>"
                          data-sample-title="BIR Permit"
                          data-sample-desc="Include TIN & official signature">
                    <img src="<?= htmlspecialchars(hb_doc_sample_src('bir-permit-sample.png', 'BIR Certificate', 'Include TIN & official signature'), ENT_QUOTES) ?>" alt="Sample BIR permit preview">
                  </button>
                  <div class="doc-sample-text">
                    <strong>Sample View</strong><br>
                    Provide the full certificate showing the TIN, form number, and authorizing signature.
                  </div>
                </div>
            </div>
            </div>

            <!-- Property Photos -->
            <div class="mb-3">
              <label class="form-label">Property Photos <span class="text-danger">*</span></label>
              <div id="photoPreviewContainer" class="d-flex flex-wrap gap-3 mb-3"></div>
              <input type="file" name="property_photos[]" id="propertyPhotosInput" class="form-control d-none" accept="image/*">
              <button type="button" id="addPhotoBtn" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-plus-circle"></i> Add Photo (Max 3)
              </button>
              <div class="form-help mt-2">Upload 1-3 clear photos of the property. Accepted: JPG, PNG, WebP, GIF (max 5 MB each).</div>
            </div>

            <div class="alert alert-info">
              <i class="bi bi-info-circle"></i> Your listing will be submitted for admin verification before it becomes visible to tenants.
            </div>

            <!-- Hidden coordinates -->
            <?php if ($resubmit_data && $prefill_mode): ?>
              <input type="hidden" name="prefill_mode" value="<?= htmlspecialchars($prefill_mode) ?>">
              <input type="hidden" name="source_listing_id" value="<?= (int)$resubmit_data['id'] ?>">
            <?php endif; ?>
            <input type="hidden" id="latField" name="latitude" value="<?= $resubmit_data ? $resubmit_data['latitude'] : '' ?>">
            <input type="hidden" id="lngField" name="longitude" value="<?= $resubmit_data ? $resubmit_data['longitude'] : '' ?>">

            <button type="submit" class="btn btn-primary">Submit for Verification</button>
          </form>
        </div>
      </section>
    </div>
  </div>

  <!-- Document Sample Modal -->
  <div id="docSampleLightbox" class="doc-sample-modal" aria-hidden="true" role="dialog">
    <div class="doc-sample-dialog">
      <button type="button" class="doc-sample-close" aria-label="Close preview">&times;</button>
      <img src="" alt="Document sample preview">
      <h5 class="doc-sample-title">Document Sample</h5>
      <p class="doc-sample-desc text-muted"></p>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="darkmode.js"></script>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const modal = document.getElementById('docSampleLightbox');
      if (!modal) return;

      const modalImg = modal.querySelector('img');
      const modalTitle = modal.querySelector('.doc-sample-title');
      const modalDesc = modal.querySelector('.doc-sample-desc');
      const closeBtn = modal.querySelector('.doc-sample-close');

      const hideModal = () => {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
      };

      const showModal = (src, title, desc) => {
        modalImg.src = src || '';
        modalImg.alt = title || desc || 'Document sample preview';
        modalTitle.textContent = title || 'Document Sample';
        modalDesc.textContent = desc || '';
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
      };

      document.querySelectorAll('.doc-sample-trigger').forEach(btn => {
        btn.addEventListener('click', () => {
          const src = btn.dataset.sampleSrc || '';
          const title = btn.dataset.sampleTitle || '';
          const desc = btn.dataset.sampleDesc || '';
          showModal(src, title, desc);
        });
      });

      closeBtn?.addEventListener('click', hideModal);
      modal.addEventListener('click', (event) => {
        if (event.target === modal) hideModal();
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('active')) hideModal();
      });
    });
  </script>

  <!-- Property Photos Management -->
  <script>
    (function() {
      const addPhotoBtn = document.getElementById('addPhotoBtn');
      const photoInput = document.getElementById('propertyPhotosInput');
      const previewContainer = document.getElementById('photoPreviewContainer');
      let photoFiles = [];
      const MAX_PHOTOS = 3;

      function syncPhotoInput() {
        const dataTransfer = new DataTransfer();
        photoFiles.forEach(file => dataTransfer.items.add(file));
        photoInput.files = dataTransfer.files;
      }

      function ensurePhotosBeforeSubmit() {
        if (photoFiles.length === 0) {
          alert('Please add at least 1 property photo.');
          return false;
        }
        syncPhotoInput();
        return true;
      }

      addPhotoBtn.addEventListener('click', () => {
        if (photoFiles.length >= MAX_PHOTOS) {
          alert(`Maximum ${MAX_PHOTOS} photos allowed`);
          return;
        }
        photoInput.click();
      });

      photoInput.addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (!file) return;

        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!allowedTypes.includes(file.type)) {
          alert('Invalid file type. Only JPG, PNG, WebP, GIF allowed.');
          photoInput.value = '';
          return;
        }

        // Validate file size (5MB)
        if (file.size > 5 * 1024 * 1024) {
          alert('File too large. Maximum 5 MB per photo.');
          photoInput.value = '';
          return;
        }

        if (photoFiles.length < MAX_PHOTOS) {
          photoFiles.push(file);
          renderPreviews();
        }

        photoInput.value = ''; // Reset input
      });

      function renderPreviews() {
        previewContainer.innerHTML = '';

        photoFiles.forEach((file, index) => {
          const reader = new FileReader();
          reader.onload = (e) => {
            const previewDiv = document.createElement('div');
            previewDiv.className = 'position-relative';
            previewDiv.style.width = '150px';
            previewDiv.innerHTML = `
              <img src="${e.target.result}" class="img-thumbnail" style="width: 150px; height: 150px; object-fit: cover;">
              <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1" onclick="removePhoto(${index})">
                <i class="bi bi-x"></i>
              </button>
              <small class="d-block text-center mt-1">Photo ${index + 1}</small>
            `;
            previewContainer.appendChild(previewDiv);
          };
          reader.readAsDataURL(file);
        });

        // Update button text
        addPhotoBtn.innerHTML = `<i class="bi bi-plus-circle"></i> Add Photo (${photoFiles.length}/${MAX_PHOTOS})`;
        addPhotoBtn.disabled = photoFiles.length >= MAX_PHOTOS;
        syncPhotoInput();
      }

      window.removePhoto = function(index) {
        photoFiles.splice(index, 1);
        renderPreviews();
      };

      const form = document.querySelector('form');
      form.addEventListener('submit', (e) => {
        if (!ensurePhotosBeforeSubmit()) {
          e.preventDefault();
          return;
        }
      });

      window.HBPhotoManager = {
        ensureBeforeSubmit: ensurePhotosBeforeSubmit,
        syncInput: syncPhotoInput,
        getCount: () => photoFiles.length
      };
    })();
  </script>

  <!-- Toggle kitchen access based on kitchen availability -->
  <script>
    (function() {
      const kitchenInput = document.getElementById('kitchenInput');
      const kitchenTypeContainer = document.getElementById('kitchenTypeContainer');
      const kitchenTypeInput = document.getElementById('kitchenTypeInput');
      const kitchenFacilityInput = document.getElementById('kitchenFacilityInput');

      function toggleKitchenAccess() {
        const hasKitchen = kitchenInput.value;

        if (hasKitchen === 'No') {
          kitchenTypeContainer.style.display = 'none';
          kitchenTypeInput.value = 'None';
          kitchenFacilityInput.value = '';
        } else {
          kitchenTypeContainer.style.display = 'block';
          // If it was set to 'None', reset to a valid option
          if (kitchenTypeInput.value === 'None') {
            kitchenTypeInput.value = 'Private';
          }
          // Make sure a kitchen facility is selected
          if (!kitchenFacilityInput.value) {
            kitchenFacilityInput.value = 'Gas';
          }
        }
      }

      kitchenInput.addEventListener('change', toggleKitchenAccess);
      toggleKitchenAccess(); // Initialize on page load
    })();
  </script>

  <!-- Toggle legal documents based on rental type -->
  <script>
    (function() {
      const rentalType = document.getElementById('rentalType');
      const residentialDocs = document.getElementById('residentialDocs');
      const commercialDocs = document.getElementById('commercialDocs');

      const barangayPermit = document.getElementById('barangayPermitInput');
      const dtiSecPermit = document.getElementById('dtiSecPermitInput');
      const businessPermit = document.getElementById('businessPermitInput');
      const birPermit = document.getElementById('birPermitInput');

      function toggleDocuments() {
        const type = rentalType.value;

        if (type === 'residential') {
          residentialDocs.style.display = 'block';
          commercialDocs.style.display = 'none';
          barangayPermit.required = true;
          dtiSecPermit.required = false;
          businessPermit.required = false;
          birPermit.required = false;
        } else if (type === 'commercial') {
          residentialDocs.style.display = 'none';
          commercialDocs.style.display = 'block';
          barangayPermit.required = false;
          dtiSecPermit.required = true;
          businessPermit.required = true;
          birPermit.required = true;
        }
      }

      rentalType.addEventListener('change', toggleDocuments);
      toggleDocuments(); // Initialize on page load
    })();
  </script>

  <!-- File upload validation -->
  <script>
    (function() {
      const allowedTypes = {
        'image/jpeg': 'jpg',
        'image/png': 'png', 
        'image/webp': 'webp',
        'image/gif': 'gif',
        'application/pdf': 'pdf'
      };
      
      const maxSize = 10 * 1024 * 1024; // 10MB
      
      function validateFile(file, errorElementId) {
        const errorElement = document.getElementById(errorElementId);
        
        // Check file type
        if (!allowedTypes[file.type]) {
          errorElement.textContent = `Unsupported file type: ${file.type}. Please upload JPG, PNG, WebP, GIF, or PDF files only.`;
          errorElement.style.display = 'block';
          return false;
        }
        
        // Check file size
        if (file.size > maxSize) {
          errorElement.textContent = `File too large: ${(file.size / 1024 / 1024).toFixed(1)}MB. Maximum size is 10MB.`;
          errorElement.style.display = 'block';
          return false;
        }
        
        // Clear error if valid
        errorElement.style.display = 'none';
        return true;
      }
      
      // Add validation to all file inputs
      const fileInputs = [
        { input: 'govIdInput', error: 'govIdError' },
        { input: 'barangayPermitInput', error: 'barangayPermitError' },
        { input: 'dtiSecPermitInput', error: 'dtiSecPermitError' },
        { input: 'businessPermitInput', error: 'businessPermitError' },
        { input: 'birPermitInput', error: 'birPermitError' }
      ];
      
      fileInputs.forEach(({ input, error }) => {
        const inputElement = document.getElementById(input);
        if (inputElement) {
          inputElement.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
              validateFile(file, error);
            }
          });
        }
      });
    })();
  </script>

  <!-- Price input guard -->
  <script>
        (function guardPrice(){
          const priceEl = document.getElementById('priceInput');
          if (!priceEl) return;
          const clamp = () => {
            let v = parseFloat(priceEl.value);
            if (isNaN(v) || v < 0) priceEl.value = 0;
            priceEl.value = String(Math.floor(parseFloat(priceEl.value || 0)));
          };
          priceEl.addEventListener('input', clamp);
          priceEl.addEventListener('blur', clamp);
        })();
      </script>
    
      <!-- Google Places Autocomplete + Geocoding -->
      <script>
        let autocomplete, geocoder, pickedPlace = null;
    
        function initPlaces() {
          const input = document.getElementById('addressInput');
          geocoder = new google.maps.Geocoder();
    
          autocomplete = new google.maps.places.Autocomplete(input, {
            fields: ['formatted_address', 'geometry'],
            componentRestrictions: { country: 'ph' }
          });
    
          autocomplete.addListener('place_changed', () => {
            pickedPlace = autocomplete.getPlace();
            if (pickedPlace && pickedPlace.geometry && pickedPlace.geometry.location) {
              const lat = pickedPlace.geometry.location.lat();
              const lng = pickedPlace.geometry.location.lng();
              document.getElementById('latField').value = lat;
              document.getElementById('lngField').value = lng;
    
              if (pickedPlace.formatted_address) {
                input.value = pickedPlace.formatted_address;
              }
            } else {
              pickedPlace = null;
            }
          });
        }
    
        async function geocodeAndSubmit(e){
          e.preventDefault();
          const form = e.target;
          const input = document.getElementById('addressInput');
          const latEl = document.getElementById('latField');
          const lngEl = document.getElementById('lngField');
          const photoManager = window.HBPhotoManager || null;
          const ensurePhotos = () => {
            if (photoManager && !photoManager.ensureBeforeSubmit()) {
              return false;
            }
            return true;
          };
    
          const alreadyHasCoords = latEl.value && lngEl.value;
          if (alreadyHasCoords) {
            if (!ensurePhotos()) { return false; }
            form.submit();
            return false;
          }
    
          const addr = (input.value || '').trim();
          if (!addr) { alert('Please enter the address.'); return false; }
    
          // Try Google Geocoder
          try {
            const g = await geocoder.geocode({ address: addr + ', Philippines' });
            if (g.status === 'OK' && g.results && g.results[0]) {
              const best = g.results[0];
              latEl.value = best.geometry.location.lat();
              lngEl.value = best.geometry.location.lng();
              input.value = best.formatted_address || addr;
              if (!ensurePhotos()) { return false; }
              form.submit();
              return false;
            } else {
              // Show validation prompt for invalid address
              const errorMsg = g.status === 'ZERO_RESULTS' 
                ? 'Address not found. Please enter a valid Philippine address.'
                : 'Unable to validate address. Please check the address and try again.';
              
              // Create and show validation modal
              showAddressValidationModal(errorMsg, addr);
              return false;
            }
          } catch (err) {
            // Show validation prompt for network errors
            showAddressValidationModal('Unable to validate address due to network error. Please try again.', addr);
            return false;
          }
    
          // Fallback to Nominatim
          try {
            const url = 'https://nominatim.openstreetmap.org/search?format=json&addressdetails=0&limit=1&q='
                        + encodeURIComponent(addr + ', Philippines');
            const resp = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const data = await resp.json();
            if (Array.isArray(data) && data.length > 0) {
              latEl.value = data[0].lat;
              lngEl.value = data[0].lon;
              if (!/philippines/i.test(input.value)) input.value = (data[0].display_name || addr);
              if (!ensurePhotos()) { return false; }
              form.submit();
              return false;
            }
          } catch (e2) {
            // Ignore
          }
    
          alert("Could not geocode the address. Please check the address or try a nearby landmark.");
          return false;
        }
        
        function showAddressValidationModal(message, address) {
          // Create modal HTML
          const modalHtml = `
            <div class="modal fade" id="addressValidationModal" tabindex="-1" aria-labelledby="addressValidationModalLabel" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                  <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="addressValidationModalLabel">
                      <i class="bi bi-exclamation-triangle"></i> Address Validation
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <div class="alert alert-warning">
                      <strong>Invalid Address:</strong> ${message}
                    </div>
                    <p><strong>Entered address:</strong> "${address}"</p>
                    <div class="mt-3">
                      <h6>Suggestions:</h6>
                      <ul>
                        <li>Make sure the address is in the Philippines</li>
                        <li>Include city/province name</li>
                        <li>Use complete street address</li>
                        <li>Check for spelling errors</li>
                      </ul>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="retryAddressValidation()">Try Again</button>
                  </div>
                </div>
              </div>
            </div>
          `;
          
          // Remove existing modal if any
          const existingModal = document.getElementById('addressValidationModal');
          if (existingModal) {
            existingModal.remove();
          }
          
          // Add modal to body
          document.body.insertAdjacentHTML('beforeend', modalHtml);
          
          // Show modal
          const modal = new bootstrap.Modal(document.getElementById('addressValidationModal'));
          modal.show();
        }
        
        function retryAddressValidation() {
          // Close modal
          const modal = bootstrap.Modal.getInstance(document.getElementById('addressValidationModal'));
          modal.hide();
          
          // Focus on address input
          setTimeout(() => {
            const addressInput = document.getElementById('addressInput');
            if (addressInput) {
              addressInput.focus();
              addressInput.select();
            }
          }, 300);
        }
    
        window.initPlaces = initPlaces;
      </script>
    
      <!-- Load Google Maps -->
      <script src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode(GOOGLE_MAPS_API_KEY) ?>&libraries=places&callback=initPlaces" async defer></script>
      
      <!-- ML Price Suggestion -->
        <script>
        (function () {
          const $ = (sel) => document.querySelector(sel);
        
          function guessCityFromAddress(addr){
            const cities = ["Quezon City","Manila","Makati","Pasig","Taguig","Caloocan","Mandaluyong","Marikina","Parañaque","Las Piñas","Valenzuela","Pasay","San Juan"];
            for (const c of cities){ 
              if (addr && addr.toLowerCase().includes(c.toLowerCase())) return c; 
            }
            return "NCR";
          }
        
          async function suggestPrice(){
            console.log('=== Suggest Price Clicked ===');

            // Show loading state
            const btn = document.getElementById('btnSuggestPrice');
            const btnText = btn?.querySelector('.btn-text');
            const btnLoading = btn?.querySelector('.btn-loading');
            if (btn) btn.disabled = true;
            if (btnText) btnText.style.display = 'none';
            if (btnLoading) btnLoading.style.display = 'inline-block';

            // Get elements
            const capacityEl  = $('[name="capacity"]');
            const bedroomEl   = $('#bedroomInput');
            const sqmEl       = $('#sqmInput');
            const typeEl      = $('#propertyType');
            const kitchenEl   = $('#kitchenInput');
            const kitchenTyEl = $('#kitchenTypeInput');
            const genderEl    = $('#genderInput');
            const petsEl      = $('#petsInput');
            const addressEl   = $('#addressInput');
            
            // Validate elements exist
            if (!capacityEl || !bedroomEl || !sqmEl || !typeEl || !addressEl) {
              alert('Please ensure all required fields are filled');
              console.error('Missing form elements');
              return;
            }
            
            // Get values with proper defaults
            const capacity  = Number(capacityEl.value) || 1;
            const bedroom   = Number(bedroomEl.value) >= 0 ? Number(bedroomEl.value) : 1;
            const sqm       = Number(sqmEl.value) || 20;
            const typeLabel = (typeEl.value || 'Apartment').trim();
            const kitchen   = (kitchenEl?.value || 'Yes').trim();
            const kitchenTy = (kitchenTyEl?.value || 'Private').trim();
            const gender    = (genderEl?.value || 'Mixed').trim();
            const pets      = (petsEl?.value || 'Allowed').trim();
            const address   = (addressEl.value || '').trim();
            const location  = guessCityFromAddress(address);

            // Get selected amenities
            const selectedAmenities = {};
            const amenityCheckboxes = document.querySelectorAll('input[name="amenities[]"]:checked');
            amenityCheckboxes.forEach(checkbox => {
              // Map checkbox values to display names that ML model expects
              const amenityMap = {
                'wifi': 'Wi-Fi',
                'parking': 'Parking',
                'aircon': 'Air Conditioning',
                'laundry': 'Laundry',
                'furnished': 'Furnished',
                'elevator': 'Elevator',
                'security': 'Security/CCTV',
                'balcony': 'Balcony',
                'gym': 'Gym',
                'pool': 'Pool',
                'bathroom': 'Bathroom',
                'sink': 'Sink',
                'electricity': 'Electricity (Submeter)',
                'water': 'Water (Submeter)'
              };
              const amenityName = amenityMap[checkbox.value] || checkbox.value;
              selectedAmenities[amenityName] = true;
            });

            console.log('Values:', {
              capacity, bedroom, sqm, typeLabel, kitchen, kitchenTy,
              gender, pets, address, location, selectedAmenities
            });

            // Validation
            if (!typeLabel || typeLabel === '') {
              alert('Please select a Property Type first');
              return;
            }
            if (!address) {
              alert('Please enter an Address first');
              return;
            }

            // Build payload
            const payload = {
              inputs: [{
                "Capacity":        capacity,
                "Bedroom":         bedroom,
                "unit_sqm":        sqm,
                "cap_per_bedroom": bedroom > 0 ? (capacity / bedroom) : capacity,
                "Type":            typeLabel,
                "Kitchen":         kitchen,
                "Kitchen type":    kitchenTy === 'None' ? 'Private' : kitchenTy,
                "Gender specific": gender,
                "Pets":            pets,
                "Location":        location,
                ...selectedAmenities  // Add selected amenities to payload
              }]
            };
        
            console.log('Payload:', JSON.stringify(payload, null, 2));
        
            const hint = document.getElementById('priceHint');
            if (hint) hint.textContent = 'Fetching suggestion…';
        
            try {
              // Auto-detect correct API path for localhost vs production
              const isLocalhost = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
              const apiPath = isLocalhost ? '/public_html/api/ml_suggest_price.php' : '/api/ml_suggest_price.php';

              console.log('Sending POST to', apiPath);

              const res = await fetch(apiPath, {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify(payload)
              });
              
              console.log('Response status:', res.status);
              
              // Get raw text first to see the error
              const text = await res.text();
              console.log('Raw response:', text);
              
              // Try to parse as JSON
              let data;
              try {
                data = JSON.parse(text);
              } catch (e) {
                if (hint) hint.textContent = 'Server error - check console';
                console.error('Response is not JSON:', text);
                return;
              }
              
              console.log('Response data:', data);
              
              if (data && data.prediction){
                const priceInput = document.getElementById('priceInput');

                // Use the amenity-adjusted price from interval if available
                const finalPrice = data.interval && data.interval.pred
                  ? Math.round(data.interval.pred)
                  : Math.round(data.prediction);

                if (priceInput) {
                  priceInput.value = finalPrice;
                }

                if (hint) {
                  if (data.interval){
                    const l = Math.round(data.interval.low).toLocaleString();
                    const p = Math.round(data.interval.pred).toLocaleString();
                    const h = Math.round(data.interval.high).toLocaleString();

                    // Count selected amenities
                    const amenityCount = Object.keys(selectedAmenities).length;
                    const amenityText = amenityCount > 0 ? ` (with ${amenityCount} amenity/amenities)` : '';

                    hint.textContent = `Suggested: ₱${p}${amenityText} (range: ₱${l}–₱${h})`;
                  } else {
                    hint.textContent = `Suggested: ₱${Math.round(data.prediction).toLocaleString()}`;
                  }
                }
              } else if (data && data.error) {
                if (hint) hint.textContent = `Error: ${data.error}`;
                console.error('ML API Error:', data);
              } else {
                if (hint) hint.textContent = 'No suggestion available.';
                console.error('Unexpected response:', data);
              }
            } catch (err) {
              if (hint) hint.textContent = 'Could not contact ML service.';
              console.error('Fetch error:', err);
            } finally {
              // Hide loading state
              if (btn) btn.disabled = false;
              if (btnText) btnText.style.display = 'inline';
              if (btnLoading) btnLoading.style.display = 'none';
            }
          }
        
          const btn = document.getElementById('btnSuggestPrice');
          if (btn) {
            console.log('Button found, attaching listener');
            btn.addEventListener('click', suggestPrice);
          } else {
            console.error('Suggest Price button NOT found!');
          }
        })();
    </script>

</body>
</html>
