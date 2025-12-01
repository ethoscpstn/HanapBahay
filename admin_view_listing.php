<?php
// admin_view_listing.php - View listing details (admin read-only mode)
session_start();
require 'mysql_connect.php';

// Require admin login
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginModule.php");
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: admin_listings.php");
    exit();
}

// Fetch listing with all details
$stmt = $conn->prepare("
  SELECT l.*, a.first_name, a.last_name, a.email
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

if (!$listing) {
    header("Location: admin_listings.php");
    exit();
}

// Parse property photos
$photos = json_decode($listing['property_photos'] ?? '[]', true);
if (!is_array($photos)) $photos = [];

// Google Maps Embed API (address-only)
$GOOGLE_MAPS_EMBED_KEY = "AIzaSyCrKcnAX9KOdNp_TNHwWwzbLSQodgYqgnU";
$address = trim($listing['address'] ?? '');
$mapsSrc = $address !== ''
  ? "https://www.google.com/maps/embed/v1/place?key=" . urlencode($GOOGLE_MAPS_EMBED_KEY) . "&q=" . urlencode($address)
  : null;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>View Listing - Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f7f7f7; }
    .topbar { background: #8B4513; color:#fff; padding: 14px 0; }
    .map-embed { width: 100%; aspect-ratio: 16 / 9; border: 0; border-radius: 12px; }
    .info-card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
    .info-label { font-weight: 600; color: #6b7280; font-size: 0.875rem; margin-bottom: 4px; }
    .info-value { font-size: 1rem; color: #111827; }
    .photo-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; }
    .photo-item { border-radius: 8px; overflow: hidden; aspect-ratio: 4/3; cursor: pointer; transition: transform 0.2s; }
    .photo-item:hover { transform: scale(1.05); }
    .photo-item img { width: 100%; height: 100%; object-fit: cover; }
    .modal-img { max-width: 100%; max-height: 80vh; object-fit: contain; }
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-approved { background: #d1fae5; color: #065f46; }
    .status-rejected { background: #fee2e2; color: #991b1b; }
  </style>
</head>
<body>
  <nav class="topbar">
    <div class="container d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-3">
        <img src="Assets/Logo1.png" class="logo" alt="HanapBahay" style="height:42px;" />
        <span class="fw-semibold">Admin - Listing Details</span>
      </div>
      <a href="admin_listings.php" class="btn btn-light btn-sm">
        <i class="bi bi-arrow-left"></i> Back to Listings
      </a>
    </div>
  </nav>

  <main class="container py-4">
    <!-- Status Badge -->
    <div class="mb-3">
      <?php if ((int)$listing['is_verified'] === 1): ?>
        <span class="badge status-approved px-3 py-2">
          <i class="bi bi-check-circle"></i> Approved
        </span>
      <?php elseif ((int)$listing['is_verified'] === -1): ?>
        <span class="badge status-rejected px-3 py-2">
          <i class="bi bi-x-circle"></i> Rejected
        </span>
      <?php else: ?>
        <span class="badge status-pending px-3 py-2">
          <i class="bi bi-clock"></i> Pending Verification
        </span>
      <?php endif; ?>
    </div>

    <!-- Property Title & Price -->
    <div class="info-card">
      <h2 class="h4 mb-3"><?= h($listing['title']) ?></h2>
      <div class="row g-3">
        <div class="col-md-6">
          <div class="info-label">Price</div>
          <div class="info-value fw-bold text-primary">₱<?= number_format((float)$listing['price'], 2) ?></div>
        </div>
        <div class="col-md-6">
          <div class="info-label">Capacity</div>
          <div class="info-value"><?= (int)$listing['capacity'] ?> person(s)</div>
        </div>
      </div>
    </div>

    <!-- Property Details -->
    <div class="info-card">
      <h5 class="mb-3">Property Details</h5>
      <div class="row g-3">
        <div class="col-md-6">
          <div class="info-label">Rental Type</div>
          <div class="info-value text-capitalize"><?= h($listing['rental_type'] ?? 'N/A') ?></div>
        </div>
        <div class="col-md-6">
          <div class="info-label">Total Units</div>
          <div class="info-value"><?= (int)$listing['total_units'] ?></div>
        </div>
        <div class="col-md-6">
          <div class="info-label">Bedrooms</div>
          <div class="info-value"><?= isset($listing['bedroom']) ? (int)$listing['bedroom'] : 'N/A' ?></div>
        </div>
        <div class="col-md-6">
          <div class="info-label">Unit Size (sqm)</div>
          <div class="info-value"><?= isset($listing['unit_sqm']) ? number_format((float)$listing['unit_sqm'], 1) : 'N/A' ?></div>
        </div>
        <div class="col-md-6">
          <div class="info-label">Kitchen Available</div>
          <div class="info-value"><?= h($listing['kitchen'] ?? 'N/A') ?></div>
        </div>
        <div class="col-md-6">
          <div class="info-label">Kitchen Type</div>
          <div class="info-value"><?= h($listing['kitchen_type'] ?? 'N/A') ?></div>
        </div>
        <div class="col-md-6">
          <div class="info-label">Gender Restriction</div>
          <div class="info-value"><?= h($listing['gender_specific'] ?? 'N/A') ?></div>
        </div>
        <div class="col-md-6">
          <div class="info-label">Pet Policy</div>
          <div class="info-value"><?= h($listing['pets'] ?? 'N/A') ?></div>
        </div>
      </div>
    </div>

    <!-- Description -->
    <?php if (!empty($listing['description'])): ?>
      <div class="info-card">
        <h5 class="mb-3">Description</h5>
        <p class="mb-0"><?= nl2br(h($listing['description'])) ?></p>
      </div>
    <?php endif; ?>

    <!-- Address & Map -->
    <div class="info-card">
      <h5 class="mb-3">Location</h5>
      <div class="info-label">Address</div>
      <div class="info-value mb-3"><?= h($listing['address']) ?></div>

      <?php if ($mapsSrc): ?>
        <iframe
          class="map-embed"
          loading="lazy"
          referrerpolicy="no-referrer-when-downgrade"
          src="<?= $mapsSrc ?>">
        </iframe>
      <?php endif; ?>
    </div>

    <!-- Amenities -->
    <?php if (!empty($listing['amenities'])): ?>
      <div class="info-card">
        <h5 class="mb-3">Amenities</h5>
        <div class="d-flex flex-wrap gap-2">
          <?php
          $amenities = array_map('trim', explode(',', $listing['amenities']));
          foreach ($amenities as $amenity):
          ?>
            <span class="badge bg-secondary"><?= h($amenity) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Property Photos -->
    <?php if (!empty($photos)): ?>
      <div class="info-card">
        <h5 class="mb-3">Property Photos</h5>
        <div class="photo-grid">
          <?php foreach ($photos as $photo): ?>
            <div class="photo-item" onclick="enlargeImage('<?= h($photo) ?>')">
              <img src="<?= h($photo) ?>" alt="Property photo" />
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Image Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-body p-0">
            <button type="button" class="btn-close position-absolute top-0 end-0 m-2 bg-white" data-bs-dismiss="modal"></button>
            <img id="modalImage" src="" class="modal-img w-100" alt="Enlarged photo" />
          </div>
        </div>
      </div>
    </div>

    <!-- Owner Information -->
    <div class="info-card">
      <h5 class="mb-3">Owner Information</h5>
      <div class="row g-3">
        <div class="col-md-6">
          <div class="info-label">Name</div>
          <div class="info-value"><?= h(trim(($listing['first_name'] ?? '').' '.($listing['last_name'] ?? ''))) ?></div>
        </div>
        <div class="col-md-6">
          <div class="info-label">Email</div>
          <div class="info-value"><?= h($listing['email'] ?? 'N/A') ?></div>
        </div>
      </div>
    </div>

    <!-- Verification Documents -->
    <div class="info-card">
      <h5 class="mb-3">Verification Documents</h5>
      <div class="row g-3">
        <?php if (!empty($listing['gov_id_path'])): ?>
          <div class="col-md-12">
            <div class="info-label">Government ID</div>
            <a href="<?= h($listing['gov_id_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
              <i class="bi bi-file-earmark"></i> View Document
            </a>
          </div>
        <?php endif; ?>

        <?php if ($listing['rental_type'] === 'residential' && !empty($listing['barangay_permit_path'])): ?>
          <div class="col-md-12">
            <div class="info-label">Barangay Permit</div>
            <a href="<?= h($listing['barangay_permit_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
              <i class="bi bi-file-earmark"></i> View Document
            </a>
          </div>
        <?php endif; ?>

        <?php if ($listing['rental_type'] === 'commercial'): ?>
          <?php if (!empty($listing['dti_sec_permit_path'])): ?>
            <div class="col-md-12">
              <div class="info-label">DTI/SEC Permit</div>
              <a href="<?= h($listing['dti_sec_permit_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-file-earmark"></i> View Document
              </a>
            </div>
          <?php endif; ?>

          <?php if (!empty($listing['business_permit_path'])): ?>
            <div class="col-md-12">
              <div class="info-label">Business Permit</div>
              <a href="<?= h($listing['business_permit_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-file-earmark"></i> View Document
              </a>
            </div>
          <?php endif; ?>

          <?php if (!empty($listing['bir_permit_path'])): ?>
            <div class="col-md-12">
              <div class="info-label">BIR Permit</div>
              <a href="<?= h($listing['bir_permit_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-file-earmark"></i> View Document
              </a>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Verification Status -->
    <?php if ((int)$listing['is_verified'] === -1 && !empty($listing['rejection_reason'])): ?>
      <div class="info-card">
        <h5 class="mb-3 text-danger">Rejection Reason</h5>
        <div class="alert alert-danger mb-0">
          <?= nl2br(h($listing['rejection_reason'])) ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($listing['verification_notes'])): ?>
      <div class="info-card">
        <h5 class="mb-3">Admin Notes</h5>
        <p class="mb-0"><?= nl2br(h($listing['verification_notes'])) ?></p>
      </div>
    <?php endif; ?>

    <!-- Metadata -->
    <div class="info-card">
      <h5 class="mb-3">Metadata</h5>
      <div class="row g-3">
        <div class="col-md-6">
          <div class="info-label">Created At</div>
          <div class="info-value"><?= h($listing['created_at']) ?></div>
        </div>
        <?php if (!empty($listing['verified_at'])): ?>
          <div class="col-md-6">
            <div class="info-label">Verified At</div>
            <div class="info-value"><?= h($listing['verified_at']) ?></div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    function enlargeImage(src) {
      document.getElementById('modalImage').src = src;
      const modal = new bootstrap.Modal(document.getElementById('imageModal'));
      modal.show();
    }
  </script>
</body>
</html>
