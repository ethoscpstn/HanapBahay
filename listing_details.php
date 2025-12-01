<?php
// listing_details.php — Public page (no login required)
require 'mysql_connect.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header("Location: browse_listings.php"); exit(); }

$stmt = $conn->prepare("
  SELECT l.id, l.title, l.description, l.address, l.price, l.capacity, l.is_archived,
         l.is_verified, l.verification_status, l.property_photos, l.amenities,
         l.bedroom, l.unit_sqm, l.kitchen, l.kitchen_type, l.kitchen_facility,
         l.gender_specific, l.pets, l.total_units, l.occupied_units,
         a.first_name, a.last_name
  FROM tblistings l
  LEFT JOIN tbadmin a ON a.id = l.owner_id
  WHERE l.id = ?
  LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$listing = $res->fetch_assoc();
$stmt->close();

// Reject access if listing is archived, not verified, or rejected
if (!$listing ||
    (int)$listing['is_archived'] === 1 ||
    (int)$listing['is_verified'] !== 1 ||
    ((isset($listing['verification_status']) && $listing['verification_status'] === 'rejected'))) {
  header("Location: browse_listings.php"); exit();
}

// Google Maps Embed API (address-only)
$GOOGLE_MAPS_EMBED_KEY = "AIzaSyCrKcnAX9KOdNp_TNHwWwzbLSQodgYqgnU";
$address = trim($listing['address'] ?? '');
$mapsSrc = $address !== ''
  ? "https://www.google.com/maps/embed/v1/place?key=" . urlencode($GOOGLE_MAPS_EMBED_KEY) . "&q=" . urlencode($address)
  : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($listing['title']) ?> • HanapBahay</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <style>
    body { background:#fff; }
    .topbar { position: sticky; top:0; z-index: 1000; background: #8B4513; color:#fff; }
    .map-embed { width: 100%; aspect-ratio: 16 / 9; border: 0; border-radius: 12px; }
  </style>
</head>
<body>
  <nav class="topbar py-2">
    <div class="container d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-3">
        <img src="Assets/Logo1.png" class="logo" alt="HanapBahay" style="height:28px;" />
        <a class="text-white text-decoration-none fw-semibold" href="browse_listings.php">Back to Browse</a>
      </div>
      <div class="d-flex align-items-center gap-2">
        <a href="LoginModule.php" class="btn btn-outline-light btn-sm">Login</a>
        <a href="LoginModule.php?register=1" class="btn btn-warning btn-sm text-dark">Register</a>
      </div>
    </div>
  </nav>

  <main class="container py-4">
    <div class="row g-4">
      <div class="col-lg-7">
        <h1 class="h4 mb-2"><?= htmlspecialchars($listing['title']) ?></h1>
        <div class="text-muted mb-2"><?= htmlspecialchars($listing['address']) ?></div>
        <div class="mb-3">
          <span class="badge bg-success">₱<?= number_format((float)$listing['price'], 2) ?>/month</span>
          <span class="badge text-bg-warning text-dark ms-2">Capacity: <?= (int)$listing['capacity'] ?></span>
          <span class="badge <?= ($listing['total_units'] - $listing['occupied_units']) > 0 ? 'bg-success' : 'bg-danger' ?> ms-2">
            <?= ($listing['total_units'] - $listing['occupied_units']) ?>/<?= $listing['total_units'] ?> Units Available
          </span>
        </div>

        <!-- Property Details -->
        <div class="card mb-4">
          <div class="card-header bg-light">
            <h2 class="h6 mb-0">Property Details</h2>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-sm-6">
                <p class="mb-1"><strong>Property Type:</strong></p>
                <p class="mb-2"><?= htmlspecialchars($listing['title']) ?></p>
              </div>
              <div class="col-sm-6">
                <p class="mb-1"><strong>Floor Area:</strong></p>
                <p class="mb-2"><?= $listing['unit_sqm'] ?> sqm</p>
              </div>
              <div class="col-sm-6">
                <p class="mb-1"><strong>Bedrooms:</strong></p>
                <p class="mb-2"><?= $listing['bedroom'] ?></p>
              </div>
              <div class="col-sm-6">
                <p class="mb-1"><strong>Kitchen:</strong></p>
                <p class="mb-2">
                  <?php if ($listing['kitchen'] === 'Yes'): ?>
                    <?= $listing['kitchen_type'] ?> Access
                    <?php if ($listing['kitchen_facility']): ?>
                      <br><small class="text-muted"><?= $listing['kitchen_facility'] ?> Cooking</small>
                    <?php endif; ?>
                  <?php else: ?>
                    No Kitchen
                  <?php endif; ?>
                </p>
              </div>
              <div class="col-sm-6">
                <p class="mb-1"><strong>Gender Policy:</strong></p>
                <p class="mb-2"><?= $listing['gender_specific'] ?></p>
              </div>
              <div class="col-sm-6">
                <p class="mb-1"><strong>Pet Policy:</strong></p>
                <p class="mb-2"><?= $listing['pets'] ?></p>
              </div>
            </div>
          </div>
        </div>

        <!-- Description -->
        <div class="card mb-4">
          <div class="card-header bg-light">
            <h2 class="h6 mb-0">Description</h2>
          </div>
          <div class="card-body">
            <p class="mb-0"><?= nl2br(htmlspecialchars($listing['description'])) ?></p>
          </div>
        </div>

        <?php 
        // Parse and display amenities
        $amenities_arr = !empty($listing['amenities']) ? array_map('trim', explode(',', $listing['amenities'])) : [];
        if (!empty($amenities_arr)): 
        ?>
        <div class="card mb-4">
          <div class="card-header bg-light">
            <h2 class="h6 mb-0">Amenities</h2>
          </div>
          <div class="card-body">
            <div class="row g-2">
              <?php foreach ($amenities_arr as $amenity): ?>
              <div class="col-6">
                <div class="d-flex align-items-center">
                  <i class="bi bi-check-circle-fill text-success me-2"></i>
                  <?= htmlspecialchars(ucfirst($amenity)) ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Photos -->
        <?php
        $photos = !empty($listing['property_photos']) ? json_decode($listing['property_photos'], true) : [];
        if (!empty($photos)):
        ?>
        <div class="card mb-4">
          <div class="card-header bg-light">
            <h2 class="h6 mb-0">Property Photos</h2>
          </div>
          <div class="card-body p-0">
            <div id="propertyPhotos" class="carousel slide" data-bs-ride="carousel">
              <div class="carousel-indicators">
                <?php foreach ($photos as $index => $photo): ?>
                <button type="button" data-bs-target="#propertyPhotos" data-bs-slide-to="<?= $index ?>" 
                        <?= $index === 0 ? 'class="active"' : '' ?>></button>
                <?php endforeach; ?>
              </div>
              <div class="carousel-inner">
                <?php foreach ($photos as $index => $photo): ?>
                <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                  <img src="<?= htmlspecialchars($photo) ?>" class="d-block w-100" alt="Property Photo <?= $index + 1 ?>"
                       style="max-height: 500px; object-fit: contain;">
                </div>
                <?php endforeach; ?>
              </div>
              <?php if (count($photos) > 1): ?>
              <button class="carousel-control-prev" type="button" data-bs-target="#propertyPhotos" data-bs-slide="prev">
                <span class="carousel-control-prev-icon"></span>
              </button>
              <button class="carousel-control-next" type="button" data-bs-target="#propertyPhotos" data-bs-slide="next">
                <span class="carousel-control-next-icon"></span>
              </button>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Map -->
        <div class="card mb-4">
          <div class="card-header bg-light">
            <h2 class="h6 mb-0">Location</h2>
          </div>
          <div class="card-body p-0">
            <?php if ($mapsSrc): ?>
          <iframe
            class="map-embed"
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"
            src="<?= $mapsSrc ?>">
          </iframe>
          <small class="text-muted d-block mt-2">Map is based on the address; shown location may be approximate.</small>
        <?php else: ?>
          <div class="alert alert-secondary">No address provided.</div>
        <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Listed by section - moved outside Location container -->
    <div class="row g-4 mt-3">
      <div class="col-lg-5">
        <div class="p-3 border rounded-3">
          <div class="d-flex align-items-center mb-3">
            <div class="rounded-circle bg-warning-subtle me-2" style="width:38px;height:38px;display:flex;align-items:center;justify-content:center">
              <i class="bi bi-person-fill"></i>
            </div>
            <div>
              <div class="small text-muted">Listed by</div>
              <div class="fw-semibold">
                <?= htmlspecialchars(trim(($listing['first_name'] ?? '').' '.($listing['last_name'] ?? ''))) ?: 'Unit Owner' ?>
              </div>
            </div>
          </div>

          <?php if (isset($_SESSION['tenant_id'])): ?>
            <div class="alert alert-info mb-3">
              <h3 class="h6">Property Status</h3>
              <p class="mb-2">
                <?php 
                $available = $listing['total_units'] - $listing['occupied_units'];
                if ($available > 0):
                ?>
                  <span class="text-success">
                    <i class="bi bi-check-circle"></i> 
                    <?= $available ?> unit<?= $available > 1 ? 's' : '' ?> available for rent
                  </span>
                <?php else: ?>
                  <span class="text-danger">
                    <i class="bi bi-x-circle"></i> 
                    No units currently available
                  </span>
                <?php endif; ?>
              </p>
              <p class="mb-0 small">
                Total Units: <?= $listing['total_units'] ?><br>
                Reserved Units: <?= $listing['occupied_units'] ?>
              </p>
            </div>

            <?php if ($available > 0): ?>
              <a href="submit_rental.php?listing_id=<?= $listing['id'] ?>" class="btn btn-dark w-100 mb-2">
                <i class="bi bi-house-add"></i> Apply to Rent
              </a>
            <?php endif; ?>
            
            <a href="start_chat.php?listing_id=<?= $listing['id'] ?>" class="btn btn-outline-secondary w-100">
              <i class="bi bi-chat-dots"></i> Message Owner
            </a>
          <?php else: ?>
            <div class="alert alert-warning mb-3">
              <i class="bi bi-info-circle"></i>
              <?php if ($listing['total_units'] - $listing['occupied_units'] > 0): ?>
                Units are available! Login or register to apply.
              <?php else: ?>
                No units are currently available.
              <?php endif; ?>
            </div>

            <a href="LoginModule.php" class="btn btn-dark w-100 mb-2">
              <i class="bi bi-box-arrow-in-right"></i> Login to Apply
            </a>
            <a href="LoginModule.php" class="btn btn-outline-secondary w-100">
              <i class="bi bi-chat-dots"></i> Login to Message Owner
            </a>
            <div class="small text-muted mt-2">
              You can browse without an account. To apply or chat, please log in or register.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>

  <!-- Bootstrap JavaScript -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
