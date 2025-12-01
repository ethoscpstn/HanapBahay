<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
ini_set('log_errors',1);
ini_set('error_log', __DIR__.'/php-error.log');
// property_details.php — public, full property view
@session_start();
require 'mysql_connect.php';
require_once 'includes/navigation.php';

// ---- read id safely ----
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(404);
  echo "Invalid listing id.";
  exit;
}

// who is viewing?
$session_role     = $_SESSION['role'] ?? '';             // 'tenant', 'unit_owner', 'admin' (whatever you use)
$session_owner_id = (int)($_SESSION['owner_id'] ?? 0);
$is_admin         = ($session_role === 'admin');

// ---- fetch listing ----
// Only show to everyone if approved (is_verified=1).
// Allow the OWNER (match a.id to session owner_id) and ADMINS to view even if not approved.
$sql = "
  SELECT
    l.id, l.title, l.description, l.address, l.latitude, l.longitude,
    l.price, l.capacity, l.is_available, l.owner_id, l.is_verified, l.amenities,
    l.property_photos, l.bedroom, l.unit_sqm, l.kitchen, l.kitchen_type,
    l.gender_specific, l.pets,
    a.gcash_name, a.gcash_number, a.gcash_qr_path,
    a.paymaya_name, a.paymaya_number, a.paymaya_qr_path,
    a.bank_name, a.bank_account_name, a.bank_account_number
  FROM tblistings l
  JOIN tbadmin a ON a.id = l.owner_id
  WHERE l.id = ?
    AND l.is_archived = 0
    AND (
          l.is_verified = 1               -- approved (public)
          OR a.id = ?                     -- owner can see their own
          OR ? = 1                        -- admin can see all
        )
  LIMIT 1";
$stmt = $conn->prepare($sql);
$ownerIdParam = $session_owner_id;
$isAdminParam = $is_admin ? 1 : 0;
$stmt->bind_param("iii", $id, $ownerIdParam, $isAdminParam);
$stmt->execute();
$res     = $stmt->get_result();
$listing = $res->fetch_assoc();
$stmt->close();

if (!$listing) {
  http_response_code(404);
  echo "Listing not found.";
  exit;
}

// extra guard (not strictly needed because the SQL already enforced it):
$is_owner = ($session_owner_id > 0 && $session_owner_id === (int)$listing['owner_id']);
if ((int)$listing['is_verified'] !== 1 && !$is_owner && !$is_admin) {
  http_response_code(404);
  echo "Listing not found.";
  exit;
}

// session flags for header/buttons
$is_logged_in = !empty($_SESSION['user_id']) || !empty($_SESSION['owner_id']);
$role         = $session_role;
$user_id      = (int)($_SESSION['user_id'] ?? 0); // For tenant chat functionality

// Load chat threads for logged-in users
$threads = [];
if ($is_logged_in && $role === 'tenant') {
    // Load threads for tenant
    $sql = "
        SELECT t.id AS thread_id,
               l.title AS listing_title,
               ta.first_name, ta.last_name, ta.id AS owner_id,
               m.body AS last_body,
               m.created_at AS last_at
        FROM chat_threads t
        JOIN tblistings l ON l.id = t.listing_id
        JOIN chat_participants cpt ON cpt.thread_id = t.id AND cpt.user_id = ? AND cpt.role = 'tenant'
        JOIN chat_participants cpo ON cpo.thread_id = t.id AND cpo.role = 'owner'
        JOIN tbadmin ta ON ta.id = cpo.user_id
        LEFT JOIN (
            SELECT thread_id, body, created_at,
                   ROW_NUMBER() OVER (PARTITION BY thread_id ORDER BY id DESC) as rn
            FROM chat_messages
        ) m ON m.thread_id = t.id AND m.rn = 1
        ORDER BY COALESCE(m.created_at, t.created_at) DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $row['counterparty_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Owner';
        $threads[] = $row;
    }
    $stmt->close();
}

// convenience
$lat = is_null($listing['latitude'])  ? null : (float)$listing['latitude'];
$lng = is_null($listing['longitude']) ? null : (float)$listing['longitude'];

// ---- ML Price Prediction ----
$ml_prediction = null;
$price_comparison = null;
$price_interval = null;
$actual_price = (float)$listing['price'];

// Extract location from address for ML prediction
$address_parts = explode(',', $listing['address']);
$location = trim(end($address_parts)); // Get last part (city/area)

// Derive property type from title (since property_type column doesn't exist)
$title_lower = strtolower($listing['title']);
if (strpos($title_lower, 'studio') !== false) {
  $property_type = 'Studio';
} elseif (strpos($title_lower, 'apartment') !== false) {
  $property_type = 'Apartment';
} elseif (strpos($title_lower, 'condo') !== false) {
  $property_type = 'Condominium';
} elseif (strpos($title_lower, 'house') !== false || strpos($title_lower, 'boarding') !== false) {
  $property_type = 'Boarding House';
} else {
  $property_type = 'Apartment'; // default
}

// Prepare ML input
$ml_input = [
  'Capacity' => (int)($listing['capacity'] ?? 1),
  'Bedroom' => (int)($listing['bedroom'] ?? 1),
  'unit_sqm' => (float)($listing['unit_sqm'] ?? 20),
  'cap_per_bedroom' => round((int)($listing['capacity'] ?? 1) / max((int)($listing['bedroom'] ?? 1), 1), 2),
  'Type' => $property_type,
  'Kitchen' => $listing['kitchen'] ?? 'Yes',
  'Kitchen type' => $listing['kitchen_type'] ?? 'Private',
  'Gender specific' => $listing['gender_specific'] ?? 'Mixed',
  'Pets' => $listing['pets'] ?? 'Allowed',
  'Location' => $location
];

// Call ML API - Auto-detect localhost vs production
$is_localhost = ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1');
$api_url = $is_localhost
  ? 'http://localhost/public_html/api/ml_suggest_price.php'
  : 'https://' . $_SERVER['HTTP_HOST'] . '/api/ml_suggest_price.php';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['inputs' => [$ml_input]]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$ml_response = curl_exec($ch);
$curl_error = curl_error($ch);
curl_close($ch);

if ($ml_response && !$curl_error) {
  $ml_data = json_decode($ml_response, true);
  if (isset($ml_data['prediction'])) {
    $ml_prediction = $ml_data['prediction'];
    $actual_price = (float)$listing['price'];

    // Calculate price difference percentage
    $diff_percent = (($actual_price - $ml_prediction) / $ml_prediction) * 100;

    // Determine price status
    if ($diff_percent <= -10) {
      $price_comparison = ['status' => 'great', 'message' => 'Great Deal!', 'diff' => $diff_percent];
    } elseif ($diff_percent <= 10) {
      $price_comparison = ['status' => 'fair', 'message' => 'Fair Price', 'diff' => $diff_percent];
    } else {
      $price_comparison = ['status' => 'high', 'message' => 'Above Market', 'diff' => $diff_percent];
    }

    // Call Price Interval API
    $interval_url = $is_localhost
      ? 'http://localhost/public_html/api/price_interval.php'
      : 'https://' . $_SERVER['HTTP_HOST'] . '/api/price_interval.php';

    $ch_interval = curl_init();
    curl_setopt($ch_interval, CURLOPT_URL, $interval_url);
    curl_setopt($ch_interval, CURLOPT_POST, 1);
    curl_setopt($ch_interval, CURLOPT_POSTFIELDS, json_encode(['inputs' => [$ml_input]]));
    curl_setopt($ch_interval, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch_interval, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_interval, CURLOPT_TIMEOUT, 5);
    $interval_response = curl_exec($ch_interval);
    $interval_error = curl_error($ch_interval);
    curl_close($ch_interval);

    if ($interval_response && !$interval_error) {
      $interval_data = json_decode($interval_response, true);
      if (isset($interval_data['interval'])) {
        $price_interval = $interval_data['interval'];
      }
    }
  }
}

// ---- Fetch Similar Properties for Comparison ----
$similar_properties = [];
$price_range_min = $actual_price * 0.7; // 30% below
$price_range_max = $actual_price * 1.3; // 30% above

$similar_sql = "
  SELECT id, title, address, price, capacity, bedroom, unit_sqm,
         property_photos, is_available
  FROM tblistings
  WHERE id != ?
    AND is_verified = 1
    AND is_archived = 0
    AND price BETWEEN ? AND ?
    AND capacity = ?
  ORDER BY ABS(price - ?) ASC
  LIMIT 3";

$similar_stmt = $conn->prepare($similar_sql);
$similar_stmt->bind_param("iddid", $id, $price_range_min, $price_range_max, $listing['capacity'], $actual_price);
$similar_stmt->execute();
$similar_res = $similar_stmt->get_result();
while ($row = $similar_res->fetch_assoc()) {
  $similar_properties[] = $row;
}
$similar_stmt->close();

/* ---------- Smart BACK target ---------- */
$allowed = [
  'DashboardT.php'        => 'DashboardT.php',
  'DashboardT'            => 'DashboardT.php',
  'DashboardUO.php'       => 'DashboardUO.php',
  'DashboardUO'           => 'DashboardUO.php',
  'browse_listings.php'   => 'browse_listings.php',
  'Browse.php'            => 'browse_listings.php',
  'browse.php'            => 'browse_listings.php',
  'browse_listings'       => 'browse_listings.php',
  'Browse'                => 'browse_listings.php',
  'browse'                => 'browse_listings.php',
  'index.php'             => 'index.php',
  'index'                 => 'index.php'
];

$ret = isset($_GET['ret']) ? basename($_GET['ret']) : '';
$ret = $allowed[$ret] ?? '';

if (!$ret) {
  if ($role === 'tenant') {
    $ret = 'DashboardT.php';
  } elseif (!empty($_SESSION['owner_id'])) {
    $ret = 'DashboardUO.php';
  }
}

if (!$ret && !empty($_SERVER['HTTP_REFERER'])) {
  $ref = basename(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH));
  if (isset($allowed[$ref])) {
    $ret = $allowed[$ref];
  }
}
// Decode property photos for carousel
$property_photos = json_decode($listing['property_photos'] ?? '[]', true);
if (!is_array($property_photos)) $property_photos = [];
if (!$ret) {
  $ret = 'browse_listings.php';
}

$backUrl = $ret;

// Close database connection
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($listing['title']) ?> • HanapBahay</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="darkmode.css" />
  <style>
    body { background:#fafafa; }
    .topbar { background:#8B4513; color:#fff; }
    .logo { height:28px; }
    .badge-cap { background:#f1c64f; color:#222; }
    #map { width:100%; height:360px; border-radius:12px; }
    .btn-brown { background:#8B4513; color:#fff; border:none; }
    .btn-outline-brown { color:#fff; border:1px solid #fff; background:transparent; }
    .btn-outline-brown:hover { color:#8B4513; background:#fff; }
  </style>
</head>
<body>
  <!-- Top bar -->
  <?= getNavigationForRole('property_details.php') ?>

  <main class="container my-4">
    <div class="row g-4">
      <!-- Left column -->
      <div class="col-lg-8">
        <h1 class="h4 mb-1"><?= htmlspecialchars($listing['title']) ?></h1>
        <div class="d-flex align-items-center gap-3 mb-2">
          <span class="badge badge-cap">Capacity: <?= (int)$listing['capacity'] ?></span>
          <strong>₱<?= number_format((float)$listing['price'], 2) ?></strong>
          <span class="text-muted">•</span>
          <span>
            <strong>Status:</strong> <?= ((int)$listing['is_available'] === 1 ? 'Available' : 'Unavailable') ?>
          </span>
        </div>


        <div class="text-muted mb-3">
          <i class="bi bi-geo-alt"></i>
          <?= htmlspecialchars($listing['address']) ?>
        </div>

        <!-- Property Photos -->
        <?php if (!empty($property_photos)): ?>
        <section class="card mb-3">
          <div class="card-body">
            <h2 class="h5 mb-3"><i class="bi bi-images"></i> Property Photos</h2>
            <div id="propertyCarousel" class="carousel slide" data-bs-ride="carousel">
              <div class="carousel-indicators">
                <?php foreach ($property_photos as $idx => $photo): ?>
                  <button type="button" data-bs-target="#propertyCarousel" data-bs-slide-to="<?= $idx ?>"
                          <?= $idx === 0 ? 'class="active" aria-current="true"' : '' ?>
                          aria-label="Slide <?= $idx + 1 ?>"></button>
                <?php endforeach; ?>
              </div>
              <div class="carousel-inner" style="border-radius: 8px; overflow: hidden;">
                <?php foreach ($property_photos as $idx => $photo): ?>
                  <div class="carousel-item <?= $idx === 0 ? 'active' : '' ?>" style="cursor: pointer;" onclick="enlargePropertyImage('<?= htmlspecialchars($photo) ?>')">
                    <img src="<?= htmlspecialchars($photo) ?>" class="d-block w-100"
                         alt="Property Photo <?= $idx + 1 ?>"
                         style="height: 400px; object-fit: cover;">
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if (count($property_photos) > 1): ?>
                <button class="carousel-control-prev" type="button" data-bs-target="#propertyCarousel" data-bs-slide="prev">
                  <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                  <span class="visually-hidden">Previous</span>
                </button>
                <button class="carousel-control-next" type="button" data-bs-target="#propertyCarousel" data-bs-slide="next">
                  <span class="carousel-control-next-icon" aria-hidden="true"></span>
                  <span class="visually-hidden">Next</span>
                </button>
              <?php endif; ?>
            </div>
          </div>
        </section>
        <?php endif; ?>

        <div id="map" class="mb-3"></div>

        <section class="card mb-3">
          <div class="card-body">
            <h2 class="h5">Description</h2>
            <p class="mb-0"><?= nl2br(htmlspecialchars($listing['description'] ?? '')) ?></p>
          </div>
        </section>

        <?php if (!empty($listing['amenities'])): ?>
        <section class="card">
          <div class="card-body">
            <h2 class="h5 mb-3">Amenities</h2>
            <div class="row g-2">
              <?php
              $amenities = explode(', ', $listing['amenities']);
              foreach ($amenities as $amenity):
              ?>
                <div class="col-6 col-md-4">
                  <i class="bi bi-check-circle-fill text-success"></i>
                  <span><?= htmlspecialchars(ucfirst($amenity)) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
        <?php endif; ?>

        <!-- More Details Tab Section -->
        <section class="card mb-3">
          <div class="card-header">
            <h2 class="h5 mb-0"><i class="bi bi-info-circle"></i> More Details</h2>
          </div>
          <div class="card-body">
            <ul class="nav nav-tabs" id="propertyTabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab" aria-controls="overview" aria-selected="true">
                  <i class="bi bi-house"></i> Overview
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="analytics-tab" data-bs-toggle="tab" data-bs-target="#analytics" type="button" role="tab" aria-controls="analytics" aria-selected="false">
                  <i class="bi bi-graph-up-arrow"></i> Price Analytics
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="description-tab" data-bs-toggle="tab" data-bs-target="#description" type="button" role="tab" aria-controls="description" aria-selected="false">
                  <i class="bi bi-file-text"></i> Description
                </button>
              </li>
            </ul>
            <div class="tab-content" id="propertyTabsContent">
              <div class="tab-pane fade show active" id="overview" role="tabpanel" aria-labelledby="overview-tab">
                <div class="row g-3 mt-3">
                  <div class="col-md-6">
                    <h6><i class="bi bi-info-circle"></i> Property Details</h6>
                    <ul class="list-unstyled">
                      <li><strong>Capacity:</strong> <?= (int)$listing['capacity'] ?> persons</li>
                      <li><strong>Bedrooms:</strong> <?= (int)($listing['bedroom'] ?? 1) ?></li>
                      <li><strong>Unit Size:</strong> <?= (float)($listing['unit_sqm'] ?? 0) ?> sqm</li>
                      <li><strong>Kitchen:</strong> <?= htmlspecialchars($listing['kitchen'] ?? 'Yes') ?></li>
                      <li><strong>Kitchen Type:</strong> <?= htmlspecialchars($listing['kitchen_type'] ?? 'Private') ?></li>
                      <li><strong>Gender Specific:</strong> <?= htmlspecialchars($listing['gender_specific'] ?? 'Mixed') ?></li>
                      <li><strong>Pets Allowed:</strong> <?= htmlspecialchars($listing['pets'] ?? 'Allowed') ?></li>
                    </ul>
                  </div>
                  <div class="col-md-6">
                    <h6><i class="bi bi-building"></i> Building Info</h6>
                    <ul class="list-unstyled">
                      <li><strong>Total Units:</strong> <?= (int)($listing['total_units'] ?? 1) ?></li>
                      <li><strong>Available Units:</strong> <?= max(0, (int)($listing['total_units'] ?? 1) - (int)($listing['occupied_units'] ?? 0)) ?></li>
                      <li><strong>Occupied Units:</strong> <?= (int)($listing['occupied_units'] ?? 0) ?></li>
                    </ul>
                  </div>
                </div>
              </div>
              <div class="tab-pane fade" id="analytics" role="tabpanel" aria-labelledby="analytics-tab">
                <div class="mt-3">
                  <div id="mlAnalysisContent">
                    <div class="text-center py-4">
                      <div class="spinner-border spinner-border-sm text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                      </div>
                      <p class="mt-2 small text-muted">Loading price analysis...</p>
                    </div>
                  </div>
                </div>
              </div>
              <div class="tab-pane fade" id="description" role="tabpanel" aria-labelledby="description-tab">
                <div class="mt-3">
                  <h6><i class="bi bi-file-text"></i> Property Description</h6>
                  <div class="description-content">
                    <?php if (!empty($listing['description'])): ?>
                      <p class="mb-0"><?= nl2br(htmlspecialchars($listing['description'])) ?></p>
                    <?php else: ?>
                      <p class="text-muted mb-0">No description provided for this property.</p>
                    <?php endif; ?>
                  </div>
                  
                  <?php if (!empty($listing['amenities'])): ?>
                    <hr class="my-4">
                    <h6><i class="bi bi-check-circle"></i> Amenities</h6>
                    <div class="row g-2">
                      <?php
                      $amenities = explode(', ', $listing['amenities']);
                      foreach ($amenities as $amenity):
                      ?>
                        <div class="col-6 col-md-4">
                          <i class="bi bi-check-circle-fill text-success"></i>
                          <span><?= htmlspecialchars(ucfirst($amenity)) ?></span>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>

      <!-- Right column -->
      <div class="col-lg-4">
        <div class="card">
          <div class="card-body">

            <?php if ($is_logged_in): ?>
              <!-- Logged-in actions -->
              <button class="btn btn-brown w-100 mb-2" data-bs-toggle="modal" data-bs-target="#rentModal">
                Apply / Reserve
              </button>

              <button class="btn btn-outline-secondary w-100" id="messageOwnerBtn"
                      data-listing-id="<?= (int)$listing['id'] ?>"
                      data-owner-id="<?= (int)$listing['owner_id'] ?>">
                Message Owner
              </button>

              <p class="text-muted small mt-2 mb-0">You’re logged in. You can apply or message the owner directly.</p>
            <?php else: ?>
              <!-- Guest actions -->
              <a href="LoginModule.php" class="btn btn-brown w-100 mb-2">Login to Apply</a>
              <a href="LoginModule.php" class="btn btn-outline-secondary w-100">Login to Message Owner</a>
              <p class="text-muted small mt-2 mb-0">You can browse without an account. To apply or chat, please log in or register.</p>
            <?php endif; ?>

          </div>
        </div>
      </div>
    </div>
  </main>

  <!-- ===== Reservation Modal (FULLY UPDATED) ===== -->
  <div class="modal fade" id="rentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <!-- enctype added for image upload -->
        <form id="reservationForm" action="submit_rental.php" method="POST" enctype="multipart/form-data">
          <div class="modal-header">
            <h5 class="modal-title">Reserve: <?= htmlspecialchars($listing['title']) ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p class="mb-1"><strong>Address:</strong> <?= htmlspecialchars($listing['address']) ?></p>
            <p class="mb-3"><strong>Price:</strong> ₱<?= number_format((float)$listing['price'], 2) ?></p>

            <input type="hidden" name="listing_id" value="<?= (int)$listing['id'] ?>">
            <input type="hidden" name="price" value="<?= (float)$listing['price'] ?>">

            <!-- Payment Method -->
            <div class="mb-3">
              <label class="form-label">Payment Method</label>
              <select class="form-select" name="payment_method" id="payment_method" required>
                <option value="">Select...</option>
                <option value="gcash">GCash</option>
                <option value="paymaya">PayMaya</option>
                <option value="bank_transfer">Bank Transfer</option>
              </select>
            </div>

            <!-- Payment Option -->
            <div class="mb-3">
              <label class="form-label">Payment Option</label>
              <select class="form-select" name="payment_option" id="paymentOption" required>
                <option value="">Choose...</option>
                <option value="reservation" selected>Reservation Only (50% of rent)</option>
                <option value="full_with_advance">Full Payment with Advance Deposit (2x rent)</option>
              </select>
            </div>

            <p class="mb-3"><strong>Amount to Pay:</strong> <span id="calculatedAmount">₱<?= number_format((float)$listing['price'] * 0.5, 2) ?></span></p>
            <div class="alert alert-info small">
              <i class="bi bi-info-circle"></i>
              <strong>Payment Options:</strong><br>
              • <strong>Reservation Only:</strong> 50% of monthly rent to secure your booking<br>
              • <strong>Full Payment with Advance:</strong> 2x monthly rent (includes 1 month advance deposit)<br>
              The remaining balance (if any) will be discussed with the property owner upon approval.
            </div>

            <!-- ===== GCash Payment Box ===== -->
            <div id="gcashBox" class="payment-box" style="display:none; margin-top:12px;">
              <hr class="my-3">
              <div class="text-center p-3 payment-info-box">
                <h6 class="mb-3"><i class="bi bi-wallet2 text-primary"></i> GCash Payment</h6>

                <?php if (!empty($listing['gcash_qr_path'])): ?>
                  <div class="mb-3">
                    <img src="<?= htmlspecialchars($listing['gcash_qr_path']) ?>"
                         alt="GCash QR Code" class="img-fluid"
                         style="max-width:280px; border:2px solid #007bff; border-radius:12px; padding:10px; background:#fff; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                  </div>
                  <div class="alert alert-info small mb-2">
                    <i class="bi bi-info-circle"></i> Open your GCash app and scan this QR code
                  </div>
                <?php else: ?>
                  <div class="alert alert-warning small mb-2">
                    <i class="bi bi-exclamation-triangle"></i> Owner hasn't set up GCash QR code yet
                  </div>
                <?php endif; ?>

                <div class="text-start mt-3" style="font-size:0.9rem;">
                  <div class="mb-1"><strong>Pay to:</strong> <?= htmlspecialchars($listing['gcash_name'] ?? 'N/A') ?></div>
                  <div class="mb-2"><strong>Number:</strong> <?= htmlspecialchars($listing['gcash_number'] ?? 'N/A') ?></div>
                </div>
              </div>

              <div style="margin-top:16px;">
                <label class="form-label fw-bold">
                  <i class="bi bi-upload"></i> Upload Payment Screenshot
                </label>
                <input type="file" name="receipt_image" id="receipt_image_gcash" accept="image/*" class="form-control receipt-input" required>
                <small class="text-muted d-block mt-1">Screenshot your successful GCash payment (JPG/PNG, max 5 MB)</small>
                <div id="receiptPreviewGcash" class="receipt-preview" style="display:none;margin-top:12px; text-align:center;">
                  <p class="small text-muted mb-2">Preview:</p>
                  <img class="receipt-preview-img" src="" alt="Receipt preview"
                       style="max-width:100%; max-height:200px; border-radius:8px; border:1px solid #ddd;">
                </div>
              </div>
            </div>
            <!-- ===== /GCash Box ===== -->

            <!-- ===== PayMaya Payment Box ===== -->
            <div id="paymayaBox" class="payment-box" style="display:none; margin-top:12px;">
              <hr class="my-3">
              <div class="text-center p-3 payment-info-box">
                <h6 class="mb-3"><i class="bi bi-wallet text-success"></i> PayMaya Payment</h6>

                <?php if (!empty($listing['paymaya_qr_path'])): ?>
                  <div class="mb-3">
                    <img src="<?= htmlspecialchars($listing['paymaya_qr_path']) ?>"
                         alt="PayMaya QR Code" class="img-fluid"
                         style="max-width:280px; border:2px solid #28a745; border-radius:12px; padding:10px; background:#fff; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                  </div>
                  <div class="alert alert-success small mb-2">
                    <i class="bi bi-info-circle"></i> Open your PayMaya app and scan this QR code
                  </div>
                <?php else: ?>
                  <div class="alert alert-warning small mb-2">
                    <i class="bi bi-exclamation-triangle"></i> Owner hasn't set up PayMaya QR code yet
                  </div>
                <?php endif; ?>

                <div class="text-start mt-3" style="font-size:0.9rem;">
                  <div class="mb-1"><strong>Pay to:</strong> <?= htmlspecialchars($listing['paymaya_name'] ?? 'N/A') ?></div>
                  <div class="mb-2"><strong>Number:</strong> <?= htmlspecialchars($listing['paymaya_number'] ?? 'N/A') ?></div>
                </div>
              </div>

              <div style="margin-top:16px;">
                <label class="form-label fw-bold">
                  <i class="bi bi-upload"></i> Upload Payment Screenshot
                </label>
                <input type="file" name="receipt_image" id="receipt_image_paymaya" accept="image/*" class="form-control receipt-input" required>
                <small class="text-muted d-block mt-1">Screenshot your successful PayMaya payment (JPG/PNG, max 5 MB)</small>
                <div id="receiptPreviewPaymaya" class="receipt-preview" style="display:none;margin-top:12px; text-align:center;">
                  <p class="small text-muted mb-2">Preview:</p>
                  <img class="receipt-preview-img" src="" alt="Receipt preview"
                       style="max-width:100%; max-height:200px; border-radius:8px; border:1px solid #ddd;">
                </div>
              </div>
            </div>
            <!-- ===== /PayMaya Box ===== -->

            <!-- ===== Bank Transfer Box ===== -->
            <div id="bankBox" class="payment-box" style="display:none; margin-top:12px;">
              <hr class="my-3">
              <div class="p-3 payment-info-box">
                <h6 class="mb-3"><i class="bi bi-bank text-info"></i> Bank Transfer Details</h6>

                <?php if (!empty($listing['bank_name']) && !empty($listing['bank_account_number'])): ?>
                  <div class="alert alert-info small mb-3">
                    <i class="bi bi-info-circle"></i> Transfer the amount to this bank account
                  </div>
                  <table class="table table-sm table-borderless mb-0">
                    <tr>
                      <td width="120"><strong>Bank Name:</strong></td>
                      <td><?= htmlspecialchars($listing['bank_name'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                      <td><strong>Account Name:</strong></td>
                      <td><?= htmlspecialchars($listing['bank_account_name'] ?? 'N/A') ?></td>
                    </tr>
                    <tr>
                      <td><strong>Account Number:</strong></td>
                      <td><code><?= htmlspecialchars($listing['bank_account_number'] ?? 'N/A') ?></code></td>
                    </tr>
                  </table>
                <?php else: ?>
                  <div class="alert alert-warning small mb-2">
                    <i class="bi bi-exclamation-triangle"></i> Owner hasn't set up bank transfer details yet
                  </div>
                <?php endif; ?>
              </div>

              <div style="margin-top:16px;">
                <label class="form-label fw-bold">
                  <i class="bi bi-upload"></i> Upload Bank Transfer Receipt
                </label>
                <input type="file" name="receipt_image" id="receipt_image_bank" accept="image/*" class="form-control receipt-input" required>
                <small class="text-muted d-block mt-1">Upload proof of bank transfer (JPG/PNG, max 5 MB)</small>
                <div id="receiptPreviewBank" class="receipt-preview" style="display:none;margin-top:12px; text-align:center;">
                  <p class="small text-muted mb-2">Preview:</p>
                  <img class="receipt-preview-img" src="" alt="Receipt preview"
                       style="max-width:100%; max-height:200px; border-radius:8px; border:1px solid #ddd;">
                </div>
              </div>
            </div>
            <!-- ===== /Bank Transfer Box ===== -->

          </div>
          <div class="modal-footer">
            <button class="btn btn-brown" type="submit">Confirm Reservation</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <!-- ===== /Reservation Modal ===== -->


  <!-- ============ FLOATING CHAT WIDGET ============ -->
  <?php if ($is_logged_in && $role === 'tenant'): ?>
  <div id="hb-chat-widget" class="hb-chat-widget">
    <div id="hb-chat-header" class="hb-chat-header-bar">
      <span><i class="bi bi-chat-dots"></i> Messages</span>
      <button id="hb-toggle-btn" class="hb-btn-ghost">_</button>
    </div>
    <div id="hb-chat-body-container" class="hb-chat-body-container">
      <div class="d-flex align-items-center justify-content-between mb-2 px-2 pt-2">
        <select id="hb-thread-select" class="form-select form-select-sm" style="min-width:240px;">
          <option value="0" selected>Select a conversation…</option>
          <?php foreach ($threads as $t): ?>
            <?php
              $label = ($t['counterparty_name'] ?: 'Owner') . ' — ' . ($t['listing_title'] ?: 'Listing');
              $dataName = htmlspecialchars($t['counterparty_name'] ?: 'Owner', ENT_QUOTES);
            ?>
            <option value="<?= (int)$t['thread_id'] ?>" data-name="<?= $dataName ?>"><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-secondary" id="hb-clear-chat">Clear</button>
      </div>

      <div id="hb-chat" class="hb-chat-container">
        <div class="hb-chat-header">
          <div class="hb-chat-title">
            <span class="hb-dot" title="Owner status unknown"></span>
            <strong id="hb-counterparty">Owner</strong>
          </div>
        </div>
        <div id="hb-chat-body" class="hb-chat-body">
          <div id="hb-history-sentinel" class="hb-history-sentinel">
            Select a conversation to view messages
          </div>
          <div id="hb-messages" class="hb-messages" aria-live="polite"></div>

          <!-- Quick Replies -->
          <div id="hb-quick-replies" class="hb-quick-replies" aria-label="Suggested questions"></div>
        </div>
        <form id="hb-send-form" class="hb-chat-input" autocomplete="off">
          <textarea id="hb-input" rows="1" placeholder="Type a message… (Press Enter to send)" required disabled></textarea>
          <button id="hb-send" type="submit" class="hb-btn" disabled>Send</button>
        </form>
      </div>
    </div>
  </div>

  <style>
    .hb-chat-widget {
      position: fixed;
      bottom: 20px;
      right: 20px;
      width: 380px;
      max-height: 72vh;
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 10px 25px rgba(0,0,0,.18);
      z-index: 9999;
      display: flex;
      flex-direction: column;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }
    .hb-chat-widget.collapsed { 
      height: 42px; 
      width: 260px; 
    }
    .hb-chat-widget.collapsed .hb-chat-body-container { 
      display: none; 
    }
    
    /* Thread selection and clear button styling */
    .hb-chat-body-container .d-flex {
      border-bottom: 1px solid #e5e7eb;
      margin-bottom: 0;
    }
    
    .hb-chat-body-container .form-select-sm {
      font-size: 0.875rem;
      padding: 0.375rem 0.75rem;
    }
    
    .hb-chat-body-container .btn-sm {
      font-size: 0.75rem;
      padding: 0.25rem 0.5rem;
    }
    
    /* Dark mode adjustments for thread controls */
    [data-theme="dark"] .hb-chat-body-container .d-flex {
      border-bottom-color: var(--border-color);
    }
    
    [data-theme="dark"] .hb-chat-body-container .form-select {
      background-color: var(--input-bg);
      border-color: var(--border-color);
      color: var(--text-primary);
    }
    
    [data-theme="dark"] .hb-chat-body-container .btn-outline-secondary {
      border-color: var(--border-color);
      color: var(--text-secondary);
    }
    
    [data-theme="dark"] .hb-chat-body-container .btn-outline-secondary:hover {
      background-color: var(--bg-tertiary);
      border-color: var(--hb-brown);
      color: var(--hb-brown);
    }
    
    .hb-chat-header-bar {
      background: #8B4513;
      color: #fff;
      padding: 8px 12px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      cursor: pointer;
    }
    .hb-btn-ghost {
      background: none;
      border: none;
      color: white;
      cursor: pointer;
      font-size: 18px;
      padding: 0;
      width: 24px;
      height: 24px;
    }
    /* Chat internals (matching dashboard styling) */
    .hb-chat-container {
      --hb-border: #e5e7eb;
      --hb-bg: #fff;
      --hb-bg-2: #f9fafb;
      --hb-text: #111827;
      --hb-muted: #6b7280;
      --hb-mine: #DCFCE7;
      --hb-their: #F3F4F6;
      --hb-accent: #8B4513;
      border-top: 1px solid var(--hb-border);
      display: flex;
      flex-direction: column;
      max-width: 100%;
      min-height: 320px;
      height: 50vh;
      overflow: hidden;
      background: var(--hb-bg);
    }

    .hb-chat-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 8px 10px;
      background: var(--hb-bg-2);
      border-bottom: 1px solid var(--hb-border);
    }

    .hb-chat-title {
      display: flex;
      align-items: center;
      gap: 8px;
      color: var(--hb-text);
      font-size: 14px;
    }

    .hb-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #22c55e;
    }

    .hb-chat-body {
      flex: 1;
      position: relative;
      overflow: auto;
      padding: 10px;
      background: linear-gradient(0deg, var(--hb-bg), var(--hb-bg-2) 120%);
    }

    .hb-history-sentinel {
      text-align: center;
      color: var(--hb-muted);
      font-size: 12px;
      padding: 6px 0;
    }

    .hb-messages {
      display: flex;
      flex-direction: column;
      gap: 8px;
      padding-bottom: 8px;
    }

    .hb-msg {
      max-width: 78%;
      border-radius: 14px;
      padding: 8px 10px;
      line-height: 1.35;
      word-wrap: break-word;
      white-space: pre-wrap;
    }

    .hb-msg.mine {
      margin-left: auto;
      background: var(--hb-mine);
      color: #0b3a1e;
    }

    .hb-msg.their {
      margin-right: auto;
      background: var(--hb-their);
      color: #111827;
    }

    .hb-meta {
      display: block;
      text-align: right;
      font-size: 11px;
      color: var(--hb-muted);
      margin-top: 4px;
    }

    .hb-from {
      font-weight: 600;
      font-size: 12px;
      margin-bottom: 2px;
      color: var(--hb-text);
      opacity: .9;
    }

    .hb-quick-replies {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin-top: 8px;
    }

    .hb-quick-reply-btn {
      background: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 16px;
      padding: 4px 12px;
      font-size: 12px;
      cursor: pointer;
      transition: all 0.2s;
    }

    .hb-quick-reply-btn:hover {
      background: #8B4513;
      color: white;
      border-color: #8B4513;
    }

    .hb-chat-input {
      display: flex;
      gap: 8px;
      padding: 10px;
      border-top: 1px solid var(--hb-border);
      background: var(--hb-bg-2);
    }

    .hb-chat-input textarea {
      flex: 1;
      resize: none;
      border: 1px solid var(--hb-border);
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 14px;
      background: var(--hb-bg);
      color: var(--hb-text);
      max-height: 140px;
      overflow: auto;
    }

    .hb-btn {
      background: var(--hb-accent);
      color: #fff;
      border: none;
      padding: 10px 14px;
      border-radius: 10px;
      font-weight: 600;
      cursor: pointer;
      min-width: 80px;
    }

    .hb-btn:disabled {
      background: #ccc;
      cursor: not-allowed;
    }
  </style>
  <?php endif; ?>
  <!-- ============================================== -->

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://js.pusher.com/8.2/pusher.min.js"></script>
  <script src="js/chat.js?v=20250215"></script>

  <script>
    // Payment calculation + Payment method toggles + receipt preview
    document.addEventListener('DOMContentLoaded', function () {
      const price = <?= json_encode((float)$listing['price']) ?>;
      const methodSel = document.getElementById('payment_method');
      const optionSel = document.getElementById('paymentOption');
      const amountEl  = document.getElementById('calculatedAmount');

      // Payment boxes
      const gcashBox = document.getElementById('gcashBox');
      const paymayaBox = document.getElementById('paymayaBox');
      const bankBox = document.getElementById('bankBox');

      // Receipt inputs
      const receiptInputs = {
        gcash: document.getElementById('receipt_image_gcash'),
        paymaya: document.getElementById('receipt_image_paymaya'),
        bank: document.getElementById('receipt_image_bank')
      };

      // Calculate amount based on payment option
      function recalc() {
        const opt = optionSel?.value || '';
        let amt = 0;
        if (opt === 'reservation') {
          amt = price * 0.5; // 50% reservation fee
        } else if (opt === 'full_with_advance') {
          amt = price * 2; // 2x rent (1 month rent + 1 month advance deposit)
        }
        amountEl.textContent = '₱' + amt.toLocaleString(undefined, { minimumFractionDigits: 2 });
      }

      // Toggle payment method sections
      function togglePaymentMethod() {
        const method = (methodSel?.value || '').toLowerCase();

        // Hide all payment boxes
        gcashBox.style.display = 'none';
        paymayaBox.style.display = 'none';
        bankBox.style.display = 'none';

        // Disable all receipt inputs
        Object.values(receiptInputs).forEach(input => {
          if (input) {
            input.required = false;
            input.disabled = true;
            input.name = '';
          }
        });

        // Show selected payment box and enable its receipt input
        if (method === 'gcash') {
          gcashBox.style.display = 'block';
          if (receiptInputs.gcash) {
            receiptInputs.gcash.required = true;
            receiptInputs.gcash.disabled = false;
            receiptInputs.gcash.name = 'receipt_image';
          }
        } else if (method === 'paymaya') {
          paymayaBox.style.display = 'block';
          if (receiptInputs.paymaya) {
            receiptInputs.paymaya.required = true;
            receiptInputs.paymaya.disabled = false;
            receiptInputs.paymaya.name = 'receipt_image';
          }
        } else if (method === 'bank_transfer') {
          bankBox.style.display = 'block';
          if (receiptInputs.bank) {
            receiptInputs.bank.required = true;
            receiptInputs.bank.disabled = false;
            receiptInputs.bank.name = 'receipt_image';
          }
        }
      }

      methodSel?.addEventListener('change', togglePaymentMethod);
      optionSel?.addEventListener('change', recalc);

      // Initialize defaults
      togglePaymentMethod();
      recalc();

      // Receipt preview handlers (for all payment methods)
      document.querySelectorAll('.receipt-input').forEach(input => {
        input.addEventListener('change', function() {
          const preview = this.parentElement.querySelector('.receipt-preview');
          const previewImg = preview?.querySelector('.receipt-preview-img');
          const f = this.files?.[0];

          if (!f) {
            if (preview) preview.style.display = 'none';
            return;
          }

          // Validate file type
          if (!/^image\/(jpeg|png|webp|gif)$/i.test(f.type)) {
            alert('Please upload an image (JPG/PNG/WebP/GIF).');
            this.value = '';
            if (preview) preview.style.display = 'none';
            return;
          }

          // Validate file size (5MB max)
          if (f.size > 5 * 1024 * 1024) {
            alert('File size must be less than 5 MB.');
            this.value = '';
            if (preview) preview.style.display = 'none';
            return;
          }

          // Show preview
          if (preview && previewImg) {
            const url = URL.createObjectURL(f);
            previewImg.src = url;
            preview.style.display = 'block';
          }
        });
      });

      // Form validation and AJAX submission
      const rentForm = document.querySelector('#reservationForm');
      rentForm?.addEventListener('submit', async function(e) {
        e.preventDefault();

        const method = methodSel?.value || '';

        if (!method) {
          alert('Please select a payment method.');
          return false;
        }

        const activeInput = Object.entries(receiptInputs).find(([key, input]) =>
          input && !input.disabled && input.required
        )?.[1];

        if (activeInput && !activeInput.files?.[0]) {
          alert('Please upload your payment receipt/screenshot.');
          return false;
        }

        // Submit form via AJAX with FormData (for file upload)
        const submitBtn = rentForm.querySelector('button[type="submit"]');
        const originalText = submitBtn?.textContent;
        if (submitBtn) submitBtn.disabled = true;
        if (submitBtn) submitBtn.textContent = 'Submitting...';

        try {
          const formData = new FormData(rentForm);
          const response = await fetch(rentForm.action, {
            method: 'POST',
            body: formData,
            headers: {
              'X-Requested-With': 'XMLHttpRequest'
            }
          });

          const contentType = response.headers.get('content-type');
          let result;

          // Try to parse response
          try {
            if (contentType && contentType.includes('application/json')) {
              result = await response.json();
            } else {
              // Try to parse as JSON anyway, fallback to text
              const text = await response.text();
              try {
                result = JSON.parse(text);
              } catch {
                result = { success: false, error: 'Invalid server response', raw: text };
                console.error('Server returned non-JSON response:', text);
              }
            }
          } catch (parseError) {
            console.error('Failed to parse response:', parseError);
            result = { success: false, error: 'Failed to parse server response' };
          }

          // Check if successful
          if (response.ok && result.success === true) {
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('rentModal'));
            modal?.hide();

            // Show success message
            const successMsg = result.message || 'Reservation submitted successfully! The owner will review your payment.';
            alert('✅ ' + successMsg);

            // Reset form
            rentForm.reset();

            // Redirect to application status page
            setTimeout(() => {
              window.location.href = 'rental_request.php';
            }, 1500);
          } else {
            const errorMsg = result.error || result.message || 'Failed to submit reservation. Please try again.';
            alert('❌ ' + errorMsg);
            if (submitBtn) submitBtn.disabled = false;
            if (submitBtn) submitBtn.textContent = originalText;
          }
        } catch (error) {
          console.error('Submission error:', error);
          alert('❌ Network error. Please check your connection and try again.');
          if (submitBtn) submitBtn.disabled = false;
          if (submitBtn) submitBtn.textContent = originalText;
        }
      });
    });
  </script>

  <?php if ($is_logged_in && $role === 'tenant'): ?>
  <script>
    // ---------- Collapse/expand chat widget ----------
    document.addEventListener("DOMContentLoaded", () => {
      const widget = document.getElementById("hb-chat-widget");
      const toggleBtn = document.getElementById("hb-toggle-btn");
      const header = document.getElementById("hb-chat-header");

      // Start collapsed by default
      widget.classList.add("collapsed");
      if (toggleBtn) toggleBtn.textContent = "▴";

      if (header) {
        header.addEventListener("click", (e)=>{
          if (e.target && e.target.id === 'hb-toggle-btn') return; // button handles itself
          widget.classList.toggle("collapsed");
          if (toggleBtn) toggleBtn.textContent = widget.classList.contains("collapsed") ? "▴" : "_";
        });
      }
      if (toggleBtn) {
        toggleBtn.addEventListener("click", (e) => {
          e.stopPropagation();
          widget.classList.toggle("collapsed");
          toggleBtn.textContent = widget.classList.contains("collapsed") ? "▴" : "_";
        });
      }

      // Function to expand chat when new message arrives
      window.expandChatOnNewMessage = function() {
        if (widget.classList.contains("collapsed")) {
          widget.classList.remove("collapsed");
          if (toggleBtn) toggleBtn.textContent = "_";
        }
      };
    });

    // Load existing threads on page load (now handled server-side)
    async function loadExistingThreads(preserveSelection = false) {
      // Threads are now loaded server-side, but keep this function for compatibility
      // and to handle any dynamic updates
      try {
        const response = await fetch('/api/chat/list_threads.php', { credentials: 'include' });
        const data = await response.json();
        
        if (data.threads && Array.isArray(data.threads)) {
          const threadSelect = document.getElementById('hb-thread-select');
          
          // Preserve current selection if requested
          const currentValue = preserveSelection ? threadSelect.value : '0';
          
          // Clear existing options except the first one
          threadSelect.innerHTML = '<option value="0" selected>Select a conversation…</option>';
          
          // Add existing threads
          data.threads.forEach(thread => {
            const option = document.createElement('option');
            option.value = thread.thread_id;
            option.textContent = `${thread.counterparty_name} — ${thread.last_body ? thread.last_body.substring(0, 30) + '...' : 'No messages yet'}`;
            threadSelect.appendChild(option);
          });
          
          // Restore selection if it was preserved
          if (preserveSelection && currentValue !== '0') {
            threadSelect.value = currentValue;
          }
        }
      } catch (error) {
        console.warn('Failed to load existing threads:', error);
        // Fallback: try to load threads from server-side data if available
        if (window.HB_THREADS && Array.isArray(window.HB_THREADS)) {
          const threadSelect = document.getElementById('hb-thread-select');
          const currentValue = preserveSelection ? threadSelect.value : '0';
          
          threadSelect.innerHTML = '<option value="0" selected>Select a conversation…</option>';
          
          window.HB_THREADS.forEach(thread => {
            const option = document.createElement('option');
            option.value = thread.thread_id;
            option.textContent = `${thread.counterparty_name} — ${thread.last_body ? thread.last_body.substring(0, 30) + '...' : 'No messages yet'}`;
            threadSelect.appendChild(option);
          });
          
          // Restore selection if it was preserved
          if (preserveSelection && currentValue !== '0') {
            threadSelect.value = currentValue;
          }
        }
      }
    }

    // Initialize chat with the new module
    (() => {
      initializeChat({
        threadId: 0, // Will be set when conversation is selected
        counterparty: 'Owner',
        bodyEl: document.getElementById('hb-chat-body'),
        msgsEl: document.getElementById('hb-messages'),
        inputEl: document.getElementById('hb-input'),
        formEl: document.getElementById('hb-send-form'),
        sendBtn: document.getElementById('hb-send'),
        sentinel: document.getElementById('hb-history-sentinel'),
        counterpartyEl: document.getElementById('hb-counterparty'),
        threadSelect: document.getElementById('hb-thread-select'),
        clearBtn: document.getElementById('hb-clear-chat'),
        role: 'tenant'
      });
      
      // Load existing threads after initialization
      loadExistingThreads();
      
      // Add Enter key functionality to send messages
      const inputEl = document.getElementById('hb-input');
      const formEl = document.getElementById('hb-send-form');
      
      if (inputEl && formEl) {
        inputEl.addEventListener('keydown', function(e) {
          // Check if Enter key is pressed (without Shift)
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault(); // Prevent default behavior (new line)
            
            // Only send if input has content and is not disabled
            if (inputEl.value.trim() && !inputEl.hasAttribute('disabled')) {
              console.log('Sending message via Enter key (property details)');
              formEl.dispatchEvent(new Event('submit'));
            }
          }
        });
        
        // Also handle Ctrl+Enter as alternative send (common in chat apps)
        inputEl.addEventListener('keydown', function(e) {
          if (e.key === 'Enter' && e.ctrlKey) {
            e.preventDefault();
            if (inputEl.value.trim() && !inputEl.hasAttribute('disabled')) {
              console.log('Sending message via Ctrl+Enter (property details)');
              formEl.dispatchEvent(new Event('submit'));
            }
          }
        });
        
        console.log('Enter key functionality added to chat input (property details)');
      }
    })();

    // ---------- Message Owner button integration ----------
    document.addEventListener('DOMContentLoaded', function() {
      const messageOwnerBtn = document.getElementById('messageOwnerBtn');
      const chatWidget = document.getElementById('hb-chat-widget');
      const threadSelect = document.getElementById('hb-thread-select');

      if (messageOwnerBtn) {
        messageOwnerBtn.addEventListener('click', async function(e) {
          e.preventDefault();
        const listingId = this.dataset.listingId;
        const ownerId = this.dataset.ownerId;

        try {
            // Expand chat widget immediately
          chatWidget.classList.remove('collapsed');
          const toggleBtn = document.getElementById('hb-toggle-btn');
          if (toggleBtn) toggleBtn.textContent = '_';
          
          // Ensure chat widget is visible and positioned properly
          chatWidget.style.display = 'flex';
          chatWidget.style.zIndex = '9999';

          // Start or get existing chat
          const response = await fetch('start_chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `listing_id=${listingId}&ajax=1`
          });

          if (!response.ok) throw new Error('Failed to start chat');

          const data = await response.json();
            if (data.thread_id) {
              // Refresh the thread list to get the latest threads
              await loadExistingThreads(true); // Preserve selection
              
              // Select the new thread and trigger change event
              threadSelect.value = data.thread_id;
              
              // Trigger the change event to load messages
              const changeEvent = new Event('change', { bubbles: true });
              threadSelect.dispatchEvent(changeEvent);
              
              // Force the chat to switch to the new thread
              setTimeout(() => {
                // Try multiple methods to ensure the chat switches
                if (window.hbChatInstance && window.hbChatInstance.switchThread) {
                  window.hbChatInstance.switchThread(data.thread_id);
                }
                
                // Also try to trigger the chat initialization
                const chatBody = document.getElementById('hb-chat-body');
                const messagesEl = document.getElementById('hb-messages');
                if (chatBody && messagesEl) {
                  // Clear any existing messages and show loading
                  messagesEl.innerHTML = '';
                  const sentinel = document.getElementById('hb-history-sentinel');
                  if (sentinel) sentinel.textContent = 'Loading...';
                  
                  // Trigger a manual refresh
                  if (window.hbChatInstance && window.hbChatInstance.refreshMessages) {
                    window.hbChatInstance.refreshMessages();
                  }
                }
              }, 200);
            } else {
              throw new Error(data.error || 'Unknown error');
            }
        } catch (error) {
          console.error('Error starting chat:', error);
          alert('Failed to start chat. Please try again.');
        }
        });
      }
    });

    // ---------- Quick Replies Logic ----------
    (() => {
      const quickRepliesEl = document.getElementById('hb-quick-replies');
      let quickReplies = [];

      // Load quick replies from server
      async function loadQuickReplies() {
        try {
          const res = await fetch('/api/chat/get_quick_replies.php', { credentials: 'include' });
          const data = await res.json();
          if (data.ok && Array.isArray(data.quick_replies)) {
            quickReplies = data.quick_replies;
            renderQuickReplies();
          }
        } catch (e) {
          console.warn('Failed to load quick replies:', e);
        }
      }

      // Render quick reply buttons
      function renderQuickReplies() {
        if (!quickRepliesEl) return;

        quickRepliesEl.innerHTML = '';

        // Only show quick replies when a thread is selected and user is tenant
        const threadSelect = document.getElementById('hb-thread-select');
        if (!threadSelect || !threadSelect.value || threadSelect.value === '0') {
          return;
        }

        // Debug logging
        console.log('Rendering quick replies:', quickReplies);

        quickReplies.forEach(reply => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'hb-quick-reply-btn';
          
          // Handle both object and string formats
          const messageText = reply.message || reply;
          btn.textContent = messageText;
          
          btn.addEventListener('click', () => {
            const input = document.getElementById('hb-input');
            if (input) {
              input.value = messageText;
              input.dispatchEvent(new Event('input', { bubbles: true }));
            }
          });
          quickRepliesEl.appendChild(btn);
        });
      }

      // Load quick replies on page load
      loadQuickReplies();

      // Re-render when thread selection changes
      const threadSelect = document.getElementById('hb-thread-select');
      if (threadSelect) {
        threadSelect.addEventListener('change', renderQuickReplies);
      }
    })();
  </script>
  <?php endif; ?>

  <script>
    // Google Map using stored lat/lng. Wheel zoom without Ctrl (gestureHandling: 'greedy')
    function initMap(){
      const hasCoords = <?= ($lat !== null && $lng !== null) ? 'true' : 'false' ?>;
      const defaultCenter = { lat: 14.5995, lng: 120.9842 }; // Manila

      const center = hasCoords ? { lat: <?= $lat ?? 'null' ?>, lng: <?= $lng ?? 'null' ?> } : defaultCenter;

      const map = new google.maps.Map(document.getElementById('map'), {
        center,
        zoom: hasCoords ? 15 : 12,
        mapTypeControl: false,
        streetViewControl: true,
        fullscreenControl: true,
        clickableIcons: false,
        gestureHandling: 'greedy' // allow wheel zoom without Ctrl
      });

      if (hasCoords) {
        const marker = new google.maps.Marker({
          map,
          position: center,
          title: <?= json_encode($listing['title']) ?>,
          icon: {
            url: (<?= (int)$listing['is_available'] ?> === 1)
              ? "http://maps.google.com/mapfiles/ms/icons/green-dot.png"
              : "http://maps.google.com/mapfiles/ms/icons/red-dot.png"
          }
        });

        const info = new google.maps.InfoWindow({
          content: `
            <div style="max-width:220px;">
              <h6 class="mb-1"><?= htmlspecialchars($listing['title']) ?></h6>
              <p class="mb-1"><strong>Address:</strong> <?= htmlspecialchars($listing['address']) ?></p>
              <p class="mb-1"><strong>Price:</strong> ₱<?= number_format((float)$listing['price'], 2) ?></p>
              <p class="mb-0"><strong>Status:</strong> <?= ((int)$listing['is_available'] === 1 ? 'Available' : 'Unavailable') ?></p>
            </div>
          `
        });
        marker.addEventListener('click', () => info.open(map, marker));
      }
    }
  </script>
  <script src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode('AIzaSyCrKcnAX9KOdNp_TNHwWwzbLSQodgYqgnU') ?>&callback=initMap" async defer></script>

  <!-- Image Enlarge Modal -->
  <div class="modal fade" id="imageEnlargeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-body p-0 position-relative">
          <button type="button" class="btn-close position-absolute top-0 end-0 m-2 bg-white" data-bs-dismiss="modal"></button>
          <img id="enlargedImage" src="" class="w-100" style="max-height: 80vh; object-fit: contain;" alt="Enlarged photo" />
        </div>
      </div>
    </div>
  </div>

  <script>
    function enlargePropertyImage(src) {
      document.getElementById('enlargedImage').src = src;
      const modal = new bootstrap.Modal(document.getElementById('imageEnlargeModal'));
      modal.show();
    }

    // Load ML analysis when analytics tab is clicked
    document.addEventListener('DOMContentLoaded', function() {
      const analyticsTab = document.getElementById('analytics-tab');
      const mlAnalysisContent = document.getElementById('mlAnalysisContent');
      let mlAnalysisLoaded = false;

      if (analyticsTab && mlAnalysisContent) {
        analyticsTab.addEventListener('click', async function() {
          if (mlAnalysisLoaded) return;
          
          mlAnalysisLoaded = true;
          
          try {
            // Prepare ML input data
            const mlInput = {
              'Capacity': <?= (int)$listing['capacity'] ?>,
              'Bedroom': <?= (int)($listing['bedroom'] ?? 1) ?>,
              'unit_sqm': <?= (float)($listing['unit_sqm'] ?? 20) ?>,
              'cap_per_bedroom': <?= (int)$listing['capacity'] ?> / <?= max(1, (int)($listing['bedroom'] ?? 1)) ?>,
              'Type': '<?= addslashes($listing['title'] ?? 'Apartment') ?>',
              'Kitchen': '<?= addslashes($listing['kitchen'] ?? 'Yes') ?>',
              'Kitchen type': '<?= addslashes($listing['kitchen_type'] ?? 'Private') ?>',
              'Gender specific': '<?= addslashes($listing['gender_specific'] ?? 'Mixed') ?>',
              'Pets': '<?= addslashes($listing['pets'] ?? 'Allowed') ?>',
              'Location': '<?= addslashes($listing['address'] ?? 'Metro Manila') ?>'
            };

            // Fetch ML prediction and price interval
            const [response, intervalResponse] = await Promise.all([
              fetch('api/ml_suggest_price.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ inputs: [mlInput] })
              }),
              fetch('api/price_interval.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ inputs: [mlInput] })
              })
            ]);

            const data = await response.json();
            const intervalData = await intervalResponse.json();

            if (data.prediction) {
              const actualPrice = parseFloat(<?= (float)$listing['price'] ?>);
              const mlPrice = data.prediction;
              const diffPercent = ((actualPrice - mlPrice) / mlPrice) * 100;

              let statusClass, statusIcon, message;
              if (diffPercent <= -10) {
                statusClass = 'success';
                statusIcon = 'bi-check-circle-fill';
                message = 'Great Deal!';
              } else if (diffPercent <= 10) {
                statusClass = 'info';
                statusIcon = 'bi-info-circle-fill';
                message = 'Fair Price';
              } else {
                statusClass = 'warning';
                statusIcon = 'bi-exclamation-triangle-fill';
                message = 'Above Market';
              }

              // Build price interval HTML if available
              let intervalHtml = '';
              if (intervalData && intervalData.interval) {
                const interval = intervalData.interval;
                intervalHtml = `
                  <div class="mb-3 p-3 payment-info-box border-left-info">
                    <h6 class="mb-2">
                      <i class="bi bi-graph-up"></i> Expected Price Range (${interval.confidence}% Confidence)
                    </h6>
                    <div class="d-flex align-items-center gap-2 mb-2">
                      <small class="text-muted">₱${interval.min.toLocaleString()}</small>
                      <div class="flex-grow-1">
                        <div class="progress" style="height: 12px;">
                          <div class="progress-bar bg-info" style="width: 100%"></div>
                        </div>
                      </div>
                      <small class="text-muted">₱${interval.max.toLocaleString()}</small>
                    </div>
                    <small class="text-muted d-block" style="font-size: 0.75rem;">
                      Expected range based on similar properties (±${interval.variance_factor}%)
                    </small>
                  </div>`;
              }

              mlAnalysisContent.innerHTML = `
                <div class="row g-3">
                  <div class="col-md-6">
                    <div class="card border-${statusClass}">
                      <div class="card-body text-center">
                        <i class="bi ${statusIcon} text-${statusClass} fs-1 mb-2"></i>
                        <h5 class="text-${statusClass}">${message}</h5>
                        <p class="mb-0">Your Price vs Market Analysis</p>
                      </div>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="card">
                      <div class="card-body">
                        <h6 class="mb-3">Price Comparison</h6>
                        <div class="d-flex justify-content-between mb-2">
                          <span>Your Price:</span>
                          <strong>₱${actualPrice.toLocaleString()}</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                          <span>ML Predicted:</span>
                          <strong class="text-primary">₱${mlPrice.toLocaleString()}</strong>
                        </div>
                        <div class="d-flex justify-content-between">
                          <span>Difference:</span>
                          <strong class="text-${statusClass}">${diffPercent > 0 ? '+' : ''}${diffPercent.toFixed(1)}%</strong>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                ${intervalHtml}
                <div class="alert alert-info">
                  <i class="bi bi-info-circle"></i>
                  <strong>Note:</strong> This analysis is based on similar properties in the area. 
                  Consider factors like location, amenities, and market conditions when making decisions.
                </div>`;
            } else {
              mlAnalysisContent.innerHTML = `
                <div class="alert alert-warning">
                  <i class="bi bi-exclamation-triangle"></i>
                  <strong>Analysis Unavailable</strong><br>
                  Unable to generate price analysis at this time. Please try again later.
                </div>`;
            }
          } catch (error) {
            console.error('ML Analysis Error:', error);
            mlAnalysisContent.innerHTML = `
              <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Error Loading Analysis</strong><br>
                There was an error loading the price analysis. Please try again later.
              </div>`;
          }
        });
      }
    });
  </script>
  <script src="darkmode.js"></script>
</body>
</html>
