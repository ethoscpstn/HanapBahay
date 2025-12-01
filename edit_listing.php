<?php
// Enable error display for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');

ob_start(); // Buffer output to prevent header errors
session_start();
require 'mysql_connect.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$owner_id = isset($_SESSION['owner_id']) ? (int)$_SESSION['owner_id'] : 0;

// Redirect if no valid ID or session
if ($id <= 0 || $owner_id <= 0) {
    header("Location: LoginModule.php");
    exit;
}

// Fetch listing data (before POST so we can show current values/QR)
$stmt = $conn->prepare("SELECT * FROM tblistings WHERE id = ? AND owner_id = ?");
$stmt->bind_param("ii", $id, $owner_id);
$stmt->execute();
$result = $stmt->get_result();
$listing = $result->fetch_assoc();
$stmt->close();

if (!$listing) {
    echo "<p class='text-danger'>Listing not found or unauthorized access.</p>";
    exit;
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title          = trim($_POST['title']);
    $address        = trim($_POST['address']);
    $price          = (float)$_POST['price'];
    $capacity       = (int)$_POST['capacity'];
    $description    = trim($_POST['description']);
    $bedroom        = isset($_POST['bedroom']) ? (int)$_POST['bedroom'] : 1;
    $unit_sqm       = isset($_POST['unit_sqm']) ? (float)$_POST['unit_sqm'] : 20;
    $kitchen        = isset($_POST['kitchen']) ? trim($_POST['kitchen']) : 'Yes';
    $kitchen_type   = isset($_POST['kitchen_type']) ? trim($_POST['kitchen_type']) : 'Private';
    $gender_specific = isset($_POST['gender_specific']) ? trim($_POST['gender_specific']) : 'Mixed';
    $pets           = isset($_POST['pets']) ? trim($_POST['pets']) : 'Allowed';

    // Handle amenities
    $amenities_arr = isset($_POST['amenities']) && is_array($_POST['amenities']) ? $_POST['amenities'] : [];
    $amenities_arr = array_map(function($a){ return strtolower(trim($a)); }, $amenities_arr);
    $amenities = implode(', ', $amenities_arr);

    // Handle existing photos and deletions
    $existing_photos = json_decode($listing['property_photos'] ?? '[]', true);
    if (!is_array($existing_photos)) $existing_photos = [];

    // Remove deleted photos
    $photos_to_delete = isset($_POST['delete_photos']) ? $_POST['delete_photos'] : [];
    foreach ($photos_to_delete as $photo_path) {
        $key = array_search($photo_path, $existing_photos);
        if ($key !== false) {
            unset($existing_photos[$key]);
            // Delete file from server
            $full_path = __DIR__ . '/' . $photo_path;
            if (file_exists($full_path)) {
                @unlink($full_path);
            }
        }
    }
    $existing_photos = array_values($existing_photos); // Re-index array

    // Handle new photo uploads
    if (isset($_FILES['new_photos']) && !empty($_FILES['new_photos']['name'][0])) {
        $allowed_img = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $dir = __DIR__ . "/uploads/property_photos/" . date('Ymd');
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

        for ($i = 0; $i < count($_FILES['new_photos']['name']); $i++) {
            if ($_FILES['new_photos']['error'][$i] === UPLOAD_ERR_OK && count($existing_photos) < 3) {
                $mime = function_exists('mime_content_type') ?
                        mime_content_type($_FILES['new_photos']['tmp_name'][$i]) :
                        $_FILES['new_photos']['type'][$i];

                if (isset($allowed_img[$mime]) && $_FILES['new_photos']['size'][$i] <= 5 * 1024 * 1024) {
                    $ext = $allowed_img[$mime];
                    $safeName = "photo_" . $owner_id . "_" . time() . "_" . $i . "_" . bin2hex(random_bytes(4)) . "." . $ext;
                    $abs = $dir . "/" . $safeName;
                    if (@move_uploaded_file($_FILES['new_photos']['tmp_name'][$i], $abs)) {
                        $existing_photos[] = "uploads/property_photos/" . date('Ymd') . "/" . $safeName;
                    }
                }
            }
        }
    }

    $photos_json = json_encode($existing_photos);

    // Prepare UPDATE query
    $sql = "UPDATE tblistings
        SET title=?, address=?, price=?, capacity=?, description=?, bedroom=?, unit_sqm=?, kitchen=?, kitchen_type=?, gender_specific=?, pets=?, amenities=?, property_photos=?
        WHERE id=? AND owner_id=?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }

    $bind_result = $stmt->bind_param(
        "ssdisidssssssii",
        $title,
        $address,
        $price,
        $capacity,
        $description,
        $bedroom,
        $unit_sqm,
        $kitchen,
        $kitchen_type,
        $gender_specific,
        $pets,
        $amenities,
        $photos_json,
        $id,
        $owner_id
    );

    if (!$bind_result) {
        die("Binding parameters failed: " . $stmt->error);
    }

    if ($stmt->execute()) {
        $stmt->close();
        ob_end_clean(); // Clean output buffer before redirect
        header("Location: DashboardUO.php?updated=1");
        exit;
    } else {
        $error = "Error updating listing: " . $stmt->error . " | " . $conn->error;
        error_log("UPDATE ERROR: " . $error);
        $stmt->close();
        // Display error to user
        echo "<div class='alert alert-danger'>$error</div>";
    }
}
ob_end_flush(); // Send buffered output
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Edit Listing</title>
  <link rel="icon" type="image/png" sizes="16x16" href="Assets/HanapBahayTablogo.png?v=2">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="add_property.css?v=41">
  <link rel="stylesheet" href="darkmode.css">
  <style>
    body { padding: 24px 14px; }
    .form-container { max-width: 800px; margin: 0 auto; }
  </style>
</head>
<body class="dashboard-bg">
<div class="container form-container">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">Edit Property Listing</h3>
    <a href="DashboardUO.php" class="btn btn-outline-secondary">
      <i class="bi bi-arrow-left"></i> Back
    </a>
  </div>

  <?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <strong>Error:</strong> <?= htmlspecialchars($error) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" class="bg-white p-4 shadow rounded">
    <!-- Property Type -->
    <div class="mb-3">
      <label class="form-label">Property Type <span class="text-danger">*</span></label>
      <select name="title" id="propertyType" class="form-select" required>
        <option value="">-- Select Type --</option>
        <option value="Apartment" <?= $listing['title'] === 'Apartment' ? 'selected' : '' ?>>Apartment</option>
        <option value="Condominium" <?= $listing['title'] === 'Condominium' ? 'selected' : '' ?>>Condominium</option>
        <option value="House" <?= $listing['title'] === 'House' ? 'selected' : '' ?>>House</option>
        <option value="Studio" <?= $listing['title'] === 'Studio' ? 'selected' : '' ?>>Studio</option>
      </select>
    </div>

    <!-- Capacity -->
    <div class="mb-3">
      <label class="form-label">Capacity <span class="text-danger">*</span></label>
      <input type="number" name="capacity" id="capacityInput" min="1" class="form-control" required value="<?= (int)$listing['capacity'] ?>">
    </div>

    <!-- Bedrooms and Unit Size - Side by Side -->
    <div class="row mb-3">
      <div class="col-md-6">
        <label class="form-label">Number of Bedrooms</label>
        <input type="number" name="bedroom" id="bedroomInput" class="form-control" min="0" step="1" value="<?= isset($listing['bedroom']) ? (int)$listing['bedroom'] : 1 ?>">
        <small class="text-muted">Set to 0 for studio units with no separate bedroom</small>
      </div>
      <div class="col-md-6">
        <label class="form-label">Unit Size (sqm)</label>
        <input type="number" name="unit_sqm" id="sqmInput" class="form-control" min="1" step="0.1" value="<?= isset($listing['unit_sqm']) ? (float)$listing['unit_sqm'] : 20 ?>">
      </div>
    </div>

    <!-- Kitchen Details - Side by Side -->
    <div class="row mb-3">
      <div class="col-md-6">
        <label class="form-label">Kitchen Available</label>
        <select name="kitchen" id="kitchenInput" class="form-select">
          <option value="Yes" <?= (isset($listing['kitchen']) && $listing['kitchen'] === 'Yes') ? 'selected' : '' ?>>Yes</option>
          <option value="No" <?= (isset($listing['kitchen']) && $listing['kitchen'] === 'No') ? 'selected' : '' ?>>No</option>
        </select>
      </div>
      <div class="col-md-6" id="kitchenTypeContainer">
        <label class="form-label">Kitchen Access</label>
        <select name="kitchen_type" id="kitchenTypeInput" class="form-select">
          <option value="None" <?= (isset($listing['kitchen_type']) && $listing['kitchen_type'] === 'None') ? 'selected' : '' ?>>None</option>
          <option value="Private" <?= (isset($listing['kitchen_type']) && $listing['kitchen_type'] === 'Private') ? 'selected' : '' ?>>Private</option>
          <option value="Shared" <?= (isset($listing['kitchen_type']) && $listing['kitchen_type'] === 'Shared') ? 'selected' : '' ?>>Shared</option>
        </select>
      </div>
    </div>

    <!-- Policies - Side by Side -->
    <div class="row mb-3">
      <div class="col-md-6">
        <label class="form-label">Gender Restriction</label>
        <select name="gender_specific" id="genderInput" class="form-select">
          <option value="Mixed" <?= (isset($listing['gender_specific']) && $listing['gender_specific'] === 'Mixed') ? 'selected' : '' ?>>Mixed</option>
          <option value="Male" <?= (isset($listing['gender_specific']) && $listing['gender_specific'] === 'Male') ? 'selected' : '' ?>>Male Only</option>
          <option value="Female" <?= (isset($listing['gender_specific']) && $listing['gender_specific'] === 'Female') ? 'selected' : '' ?>>Female Only</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Pet Policy</label>
        <select name="pets" id="petsInput" class="form-select">
          <option value="Allowed" <?= (isset($listing['pets']) && $listing['pets'] === 'Allowed') ? 'selected' : '' ?>>Allowed</option>
          <option value="Not Allowed" <?= (isset($listing['pets']) && $listing['pets'] === 'Not Allowed') ? 'selected' : '' ?>>Not Allowed</option>
        </select>
      </div>
    </div>

    <!-- Description -->
    <div class="mb-3">
      <label class="form-label">Description</label>
      <textarea name="description" class="form-control" rows="4"><?= htmlspecialchars($listing['description']) ?></textarea>
    </div>

    <!-- Amenities -->
    <div class="mb-3">
      <label class="form-label">Amenities</label>
      <?php
        $ALL_AMENITIES = [
          'wifi' => 'Wi-Fi', 'parking' => 'Parking', 'aircon' => 'Air Conditioning',
          'kitchen' => 'Kitchen', 'laundry' => 'Laundry', 'furnished' => 'Furnished',
          'elevator' => 'Elevator', 'security' => 'Security/CCTV', 'balcony' => 'Balcony',
          'gym' => 'Gym', 'pool' => 'Pool', 'pet_friendly' => 'Pet Friendly',
          'bathroom' => 'Bathroom', 'sink' => 'Sink',
          'electricity' => 'Electricity (Submeter)', 'water' => 'Water (Submeter)'
        ];

        // Parse existing amenities
        $existing_amenities = [];
        if (!empty($listing['amenities'])) {
          $existing_amenities = array_map('trim', explode(',', $listing['amenities']));
        }
      ?>
      <div class="amenities-grid">
        <?php foreach ($ALL_AMENITIES as $key => $label): ?>
          <label class="form-check">
            <input class="form-check-input" type="checkbox" name="amenities[]" value="<?= $key ?>"
              <?= in_array($key, $existing_amenities) ? 'checked' : '' ?>>
            <span class="form-check-label"><?= htmlspecialchars($label) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Property Photos -->
    <div class="mb-3">
      <label class="form-label">Property Photos</label>
      <div id="existingPhotosContainer" class="d-flex flex-wrap gap-3 mb-3">
        <?php
        $photos = json_decode($listing['property_photos'] ?? '[]', true);
        if (!is_array($photos)) $photos = [];
        foreach ($photos as $index => $photo):
        ?>
          <div class="position-relative" data-photo="<?= htmlspecialchars($photo) ?>">
            <img src="<?= htmlspecialchars($photo) ?>" class="img-thumbnail" style="width: 150px; height: 150px; object-fit: cover;">
            <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1" onclick="markPhotoForDeletion(this, '<?= htmlspecialchars($photo) ?>')">
              <i class="bi bi-x"></i>
            </button>
            <small class="d-block text-center mt-1">Photo <?= $index + 1 ?></small>
          </div>
        <?php endforeach; ?>
      </div>

      <div id="newPhotoPreviewContainer" class="d-flex flex-wrap gap-3 mb-3"></div>

      <input type="file" name="new_photos[]" id="newPhotosInput" class="form-control d-none" accept="image/*">
      <button type="button" id="addNewPhotoBtn" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-plus-circle"></i> Add Photo
      </button>
      <div class="form-help mt-2">Max 3 photos total. JPG, PNG, WebP, GIF (max 5 MB each).</div>

      <div id="deletePhotosContainer"></div>
    </div>

    <!-- Address -->
    <div class="mb-3">
      <label class="form-label">Address <span class="text-danger">*</span></label>
      <input type="text" name="address" id="addressInput" class="form-control" required value="<?= htmlspecialchars($listing['address']) ?>">
    </div>

    <!-- Price with Suggest Button -->
    <div class="mb-3">
      <label class="form-label">Price (₱) <span class="text-danger">*</span></label>
      <div class="d-flex gap-2">
        <input type="number" name="price" id="priceInput" min="0" class="form-control" required value="<?= round($listing['price']) ?>">
        <button type="button" class="btn btn-outline-primary" id="btnSuggestPrice">
          Suggest Price
        </button>
      </div>
      <div class="form-help" id="priceHint">Click "Suggest Price" to get a model-based estimate.</div>
    </div>

    <button type="submit" class="btn btn-primary">Update Listing</button>
    <a href="DashboardUO.php" class="btn btn-secondary ms-2">Cancel</a>
  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="darkmode.js"></script>

<!-- Toggle kitchen access based on kitchen availability -->
<script>
(function() {
  const kitchenInput = document.getElementById('kitchenInput');
  const kitchenTypeContainer = document.getElementById('kitchenTypeContainer');
  const kitchenTypeInput = document.getElementById('kitchenTypeInput');

  function toggleKitchenAccess() {
    const hasKitchen = kitchenInput.value;

    if (hasKitchen === 'No') {
      kitchenTypeContainer.style.display = 'none';
      kitchenTypeInput.value = 'None';
    } else {
      kitchenTypeContainer.style.display = 'block';
      if (kitchenTypeInput.value === 'None') {
        kitchenTypeInput.value = 'Private';
      }
    }
  }

  kitchenInput.addEventListener('change', toggleKitchenAccess);
  toggleKitchenAccess(); // Initialize on page load
})();
</script>

<!-- Photo Management -->
<script>
(function() {
  const addBtn = document.getElementById('addNewPhotoBtn');
  const photoInput = document.getElementById('newPhotosInput');
  const newPreviewContainer = document.getElementById('newPhotoPreviewContainer');
  const existingContainer = document.getElementById('existingPhotosContainer');
  const deleteContainer = document.getElementById('deletePhotosContainer');
  let newPhotoFiles = [];
  let photosToDelete = [];
  const MAX_PHOTOS = 3;

  function getTotalPhotoCount() {
    const existingCount = existingContainer.querySelectorAll('[data-photo]').length;
    return existingCount + newPhotoFiles.length;
  }

  addBtn.addEventListener('click', () => {
    if (getTotalPhotoCount() >= MAX_PHOTOS) {
      alert('Maximum 3 photos allowed');
      return;
    }
    photoInput.click();
  });

  photoInput.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;

    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!allowedTypes.includes(file.type)) {
      alert('Invalid file type');
      photoInput.value = '';
      return;
    }

    if (file.size > 5 * 1024 * 1024) {
      alert('File too large (max 5MB)');
      photoInput.value = '';
      return;
    }

    if (getTotalPhotoCount() < MAX_PHOTOS) {
      newPhotoFiles.push(file);
      renderNewPreviews();
    }

    photoInput.value = '';
  });

  function renderNewPreviews() {
    newPreviewContainer.innerHTML = '';
    newPhotoFiles.forEach((file, index) => {
      const reader = new FileReader();
      reader.onload = (e) => {
        const div = document.createElement('div');
        div.className = 'position-relative';
        div.style.width = '150px';
        div.innerHTML = `
          <img src="${e.target.result}" class="img-thumbnail" style="width: 150px; height: 150px; object-fit: cover;">
          <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1" onclick="removeNewPhoto(${index})">
            <i class="bi bi-x"></i>
          </button>
          <small class="d-block text-center mt-1">New Photo ${index + 1}</small>
        `;
        newPreviewContainer.appendChild(div);
      };
      reader.readAsDataURL(file);
    });

    updateAddButton();
  }

  window.removeNewPhoto = function(index) {
    newPhotoFiles.splice(index, 1);
    renderNewPreviews();
  };

  window.markPhotoForDeletion = function(btn, photoPath) {
    if (!confirm('Remove this photo?')) return;

    photosToDelete.push(photoPath);
    btn.closest('[data-photo]').remove();

    // Add hidden input for deletion
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'delete_photos[]';
    input.value = photoPath;
    deleteContainer.appendChild(input);

    updateAddButton();
  };

  function updateAddButton() {
    const total = getTotalPhotoCount();
    addBtn.innerHTML = `<i class="bi bi-plus-circle"></i> Add Photo (${total}/${MAX_PHOTOS})`;
    addBtn.disabled = total >= MAX_PHOTOS;
  }

  // Before submit, add new files to input
  document.querySelector('form').addEventListener('submit', (e) => {
    if (newPhotoFiles.length > 0) {
      const dataTransfer = new DataTransfer();
      newPhotoFiles.forEach(file => {
        dataTransfer.items.add(file);
      });
      photoInput.files = dataTransfer.files;
    }
  });

  updateAddButton();
})();
</script>

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

    const capacityEl  = $('#capacityInput');
    const bedroomEl   = $('#bedroomInput');
    const sqmEl       = $('#sqmInput');
    const typeEl      = $('#propertyType');
    const kitchenEl   = $('#kitchenInput');
    const kitchenTyEl = $('#kitchenTypeInput');
    const genderEl    = $('#genderInput');
    const petsEl      = $('#petsInput');
    const addressEl   = $('#addressInput');

    if (!capacityEl || !bedroomEl || !sqmEl || !typeEl || !addressEl) {
      alert('Please ensure all required fields are filled');
      console.error('Missing form elements');
      return;
    }

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

    console.log('Values:', {
      capacity, bedroom, sqm, typeLabel, kitchen, kitchenTy,
      gender, pets, address, location
    });

    if (!typeLabel || typeLabel === '') {
      alert('Please select a Property Type first');
      return;
    }
    if (!address) {
      alert('Please enter an Address first');
      return;
    }

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
        "Location":        location
      }]
    };

    console.log('Payload:', JSON.stringify(payload, null, 2));

    const hint = document.getElementById('priceHint');
    if (hint) hint.textContent = 'Fetching suggestion…';

    try {
      const isLocalhost = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
      const apiPath = isLocalhost ? '/public_html/api/ml_suggest_price.php' : '/api/ml_suggest_price.php';
      console.log('Sending POST to', apiPath);

      const res = await fetch(apiPath, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
      });

      console.log('Response status:', res.status);

      const text = await res.text();
      console.log('Raw response:', text);

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
        if (priceInput) {
          priceInput.value = Math.round(data.prediction);
        }

        if (hint) {
          if (data.interval){
            const l = Math.round(data.interval.low).toLocaleString();
            const p = Math.round(data.interval.pred).toLocaleString();
            const h = Math.round(data.interval.high).toLocaleString();
            hint.textContent = `Suggested: ₱${p} (likely ₱${l}–₱${h})`;
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
