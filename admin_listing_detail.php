<?php
session_start();
require 'mysql_connect.php';

$property_helpers_path = __DIR__ . '/includes/property_helpers.php';
if (file_exists($property_helpers_path)) {
    require_once $property_helpers_path;
} else {
    if (!function_exists('normalize_property_type_label')) {
        function normalize_property_type_label($value) {
            $value = strtolower(trim((string)$value));
            if ($value === '') {
                return '';
            }

            $map = [
                'studio' => 'Studio',
                'condominium' => 'Condominium',
                'condo' => 'Condominium',
                'apartment' => 'Apartment',
                'apt' => 'Apartment',
                'house' => 'House',
                'townhouse' => 'House',
                'room' => 'Room',
                'bedspace' => 'Room',
                'dorm' => 'Dormitory',
                'loft' => 'Loft',
                'boarding' => 'Boarding House',
                'boarding house' => 'Boarding House'
            ];

            foreach ($map as $needle => $label) {
                if (strpos($value, $needle) !== false) {
                    return $label;
                }
            }

            return ucwords($value);
        }
    }

    if (!function_exists('infer_property_type_from_title')) {
        function infer_property_type_from_title($title) {
            $title = (string)$title;
            if ($title === '') {
                return '';
            }
            return normalize_property_type_label($title);
        }
    }

    if (!function_exists('extract_city_from_address')) {
        function extract_city_from_address($address) {
            if (!$address) {
                return '';
            }
            $address = strtolower((string)$address);
            $parts = array_map('trim', explode(',', $address));
            $city_map = [
                'manila' => 'Manila',
                'quezon city' => 'Quezon City',
                'caloocan' => 'Caloocan',
                'las piñas' => 'Las Piñas',
                'las pinas' => 'Las Piñas',
                'makati' => 'Makati',
                'malabon' => 'Malabon',
                'mandaluyong' => 'Mandaluyong',
                'marikina' => 'Marikina',
                'muntinlupa' => 'Muntinlupa',
                'navotas' => 'Navotas',
                'parañaque' => 'Parañaque',
                'paranaque' => 'Parañaque',
                'pasay' => 'Pasay',
                'pasig' => 'Pasig',
                'pateros' => 'Pateros',
                'san juan' => 'San Juan',
                'taguig' => 'Taguig',
                'valenzuela' => 'Valenzuela'
            ];

            foreach ($parts as $part) {
                foreach ($city_map as $needle => $label) {
                    if (strpos($part, $needle) !== false) {
                        return $label;
                    }
                }
            }
            return '';
        }
    }
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo '<div class="text-danger">Unauthorized access.</div>';
    exit();
}

$listing_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($listing_id <= 0) {
    http_response_code(400);
    echo '<div class="text-danger">Invalid listing id.</div>';
    exit();
}

$stmt = $conn->prepare("
    SELECT l.*, o.first_name, o.last_name, o.email
    FROM tblistings l
    JOIN tbadmin o ON o.id = l.owner_id
    WHERE l.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $listing_id);
$stmt->execute();
$result = $stmt->get_result();
$listing = $result->fetch_assoc();
$stmt->close();

if (!$listing) {
    http_response_code(404);
    echo '<div class="text-danger">Listing not found.</div>';
    exit();
}

$photos = [];
if (!empty($listing['property_photos'])) {
    $decoded = json_decode($listing['property_photos'], true);
    if (is_array($decoded)) {
        $photos = array_values(array_filter($decoded));
    }
}

$amenities = [];
if (!empty($listing['amenities'])) {
    $amenities = array_values(array_filter(array_map('trim', explode(',', $listing['amenities']))));
}

$mlPrediction = null;
$priceAlert = null;
$diffPct = null;

$is_localhost = ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1');
$api_url = $is_localhost
    ? 'http://localhost/public_html/api/ml_suggest_price.php'
    : 'https://' . $_SERVER['HTTP_HOST'] . '/api/ml_suggest_price.php';

$capacity = max(1, (int)($listing['capacity'] ?? 1));
$bedroom = (int)($listing['bedroom'] ?? 1);
$unit_sqm = (float)($listing['unit_sqm'] ?? 20);
$propertyType = infer_property_type_from_title($listing['title'] ?? '');
$locationCity = extract_city_from_address($listing['address'] ?? '') ?: 'Metro Manila';

$ml_input = [
    'Capacity' => $capacity,
    'Bedroom' => $bedroom,
    'unit_sqm' => $unit_sqm,
    'cap_per_bedroom' => $capacity / max(1, $bedroom),
    'Type' => $propertyType,
    'Kitchen' => $listing['kitchen'] ?? 'Yes',
    'Kitchen type' => $listing['kitchen_type'] ?? 'Private',
    'Gender specific' => $listing['gender_specific'] ?? 'Mixed',
    'Pets' => $listing['pets'] ?? 'Allowed',
    'Location' => $locationCity
];
$curl_available = function_exists('curl_init');
if ($curl_available) {
    $ch = curl_init();
    if ($ch) {
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['inputs' => [$ml_input]]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $ml_response = curl_exec($ch);
        $ml_error = curl_error($ch);
        curl_close($ch);

        if ($ml_response && !$ml_error) {
            $ml_data = json_decode($ml_response, true);
            if (isset($ml_data['prediction'])) {
                $mlPrediction = round((float)$ml_data['prediction'], 2);
                $actual = (float)$listing['price'];
                if ($mlPrediction > 0) {
                    $diffPct = (($actual - $mlPrediction) / $mlPrediction) * 100;
                    if ($diffPct > 30) {
                        $priceAlert = [
                            'type' => 'overpriced',
                            'message' => 'Listing price is significantly above ML estimated value.',
                            'diff' => round($diffPct, 1)
                        ];
                    } elseif ($diffPct < -30) {
                        $priceAlert = [
                            'type' => 'underpriced',
                            'message' => 'Listing price is significantly below ML estimated value.',
                            'diff' => round(abs($diffPct), 1)
                        ];
                    }
                }
            }
        }
    }
}

$ownerName = trim(($listing['first_name'] ?? '') . ' ' . ($listing['last_name'] ?? ''));
$verification_status = $listing['verification_status'] ?? 'pending';
$is_pending = ($verification_status === 'pending' || (int)($listing['is_verified'] ?? 0) === 0);
$priceFormatted = '₱' . number_format((float)$listing['price'], 2);
$submittedAt = !empty($listing['created_at']) ? date('M d, Y g:i A', strtotime($listing['created_at'])) : 'N/A';

ob_start();
?>
<div class="listing-detail">
  <?php if ($priceAlert && $mlPrediction): ?>
    <div class="alert <?= $priceAlert['type'] === 'overpriced' ? 'alert-danger' : 'alert-info' ?> mb-3">
      <div class="d-flex justify-content-between align-items-center flex-column flex-md-row">
        <div>
          <h6 class="mb-1 fw-bold text-uppercase">ML Pricing Alert</h6>
          <div><?= htmlspecialchars($priceAlert['message']) ?></div>
          <small class="text-muted">Difference: <?= $priceAlert['diff'] ?>%</small>
        </div>
        <div class="text-end mt-2 mt-md-0">
          <div class="small text-muted">ML Predicted Price</div>
          <div class="fs-5 fw-semibold text-primary">
            ₱<?= number_format($mlPrediction, 2) ?>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="border rounded p-3 h-100">
        <h6 class="fw-bold mb-3"><i class="bi bi-image"></i> Property Photos</h6>
        <?php if (!empty($photos)): ?>
          <div id="listingPhotosCarousel" class="carousel slide" data-bs-ride="carousel">
            <div class="carousel-inner">
              <?php foreach ($photos as $index => $photo): ?>
                <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                  <img src="<?= htmlspecialchars($photo) ?>" class="d-block w-100 rounded" alt="Photo <?= $index + 1 ?>" style="max-height:320px;object-fit:cover;">
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (count($photos) > 1): ?>
              <button class="carousel-control-prev" type="button" data-bs-target="#listingPhotosCarousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Previous</span>
              </button>
              <button class="carousel-control-next" type="button" data-bs-target="#listingPhotosCarousel" data-bs-slide="next">
                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Next</span>
              </button>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <p class="text-muted mb-0">No property photos uploaded.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="border rounded p-3 mb-3">
        <h6 class="fw-bold mb-3"><i class="bi bi-info-circle"></i> Property Details</h6>
        <ul class="list-unstyled mb-0">
          <li><strong>Title:</strong> <?= htmlspecialchars($listing['title'] ?? '') ?></li>
          <li><strong>Address:</strong> <?= htmlspecialchars($listing['address'] ?? '') ?></li>
          <li><strong>Price:</strong> <?= $priceFormatted ?> / month</li>
          <li><strong>Capacity:</strong> <?= (int)$listing['capacity'] ?> person(s)</li>
          <li><strong>Bedrooms:</strong> <?= (int)$bedroom ?></li>
          <li><strong>Floor Area:</strong> <?= $unit_sqm ? number_format($unit_sqm, 2) . ' sqm' : 'N/A' ?></li>
          <li><strong>Type:</strong> <?= htmlspecialchars($propertyType ?: 'N/A') ?></li>
        </ul>
        <?php if (!empty($listing['description'])): ?>
          <div class="mt-2">
            <strong>Description:</strong>
            <p class="mb-0"><?= nl2br(htmlspecialchars($listing['description'])) ?></p>
          </div>
        <?php endif; ?>
        <?php if (!empty($amenities)): ?>
          <div class="mt-2">
            <strong>Amenities:</strong>
            <div class="mt-1 d-flex flex-wrap gap-2">
              <?php foreach ($amenities as $amenity): ?>
                <span class="badge bg-secondary"><?= htmlspecialchars(ucwords($amenity)) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="border rounded p-3">
        <h6 class="fw-bold mb-3"><i class="bi bi-person-badge"></i> Owner Information</h6>
        <ul class="list-unstyled mb-0">
          <li><strong>Name:</strong> <?= htmlspecialchars($ownerName) ?></li>
          <li><strong>Email:</strong> <a href="mailto:<?= htmlspecialchars($listing['email']) ?>"><?= htmlspecialchars($listing['email']) ?></a></li>
          <li><strong>Submitted:</strong> <?= $submittedAt ?></li>
        </ul>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-lg-6">
      <div class="border rounded p-3 h-100">
        <h6 class="fw-bold mb-3"><i class="bi bi-badge-ad"></i> Government ID Verification</h6>
        <?php if (!empty($listing['gov_id_path'])): ?>
          <?php $extension = strtolower(pathinfo($listing['gov_id_path'], PATHINFO_EXTENSION)); ?>
          <?php if (in_array($extension, ['pdf'])): ?>
            <a href="<?= htmlspecialchars($listing['gov_id_path']) ?>" target="_blank" class="btn btn-sm btn-danger">
              <i class="bi bi-file-earmark-pdf"></i> View Uploaded ID (PDF)
            </a>
          <?php else: ?>
            <a href="<?= htmlspecialchars($listing['gov_id_path']) ?>" target="_blank">
              <img src="<?= htmlspecialchars($listing['gov_id_path']) ?>" alt="Government ID" class="img-fluid rounded border" style="max-height:280px;object-fit:contain;">
            </a>
          <?php endif; ?>
        <?php else: ?>
          <p class="text-muted mb-0">No government ID uploaded.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="border rounded p-3 h-100">
        <h6 class="fw-bold mb-3"><i class="bi bi-clipboard-check"></i> Verification Actions</h6>
        <div class="alert alert-warning">
          <strong>Checklist:</strong>
          <ul class="mb-0">
            <li>Verify government ID is clear and valid</li>
            <li>Check property photos show actual property</li>
            <li>Confirm property details match submission</li>
            <li>Review pricing against ML suggestion</li>
          </ul>
        </div>

        <?php if ($is_pending): ?>
          <div class="d-flex flex-column gap-2">
            <form method="POST" action="admin_listings.php">
              <input type="hidden" name="listing_id" value="<?= (int)$listing_id ?>">
              <input type="hidden" name="action" value="approve">
              <button type="submit" class="btn btn-success w-100">
                <i class="bi bi-check-circle"></i> Approve Listing
              </button>
            </form>

            <form method="POST" action="admin_listings.php">
              <input type="hidden" name="listing_id" value="<?= (int)$listing_id ?>">
              <input type="hidden" name="action" value="reject">
              <div class="mb-2">
                <label class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="Explain why this listing is rejected..."></textarea>
              </div>
              <button type="submit" class="btn btn-danger w-100">
                <i class="bi bi-x-circle"></i> Reject Listing
              </button>
            </form>
          </div>
        <?php else: ?>
          <p class="text-muted mb-0">This listing has already been <?= htmlspecialchars($verification_status) ?>.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php

echo ob_get_clean();
