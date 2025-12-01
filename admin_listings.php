<?php
// admin_listings.php — manage property listing approvals
session_start();
require 'mysql_connect.php';
require_once 'includes/navigation.php';

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
}

// Require admin login
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginModule.php");
    exit();
}

$admin_id = (int)($_SESSION['user_id'] ?? 0);

// --- Handle approve/reject actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['listing_id'], $_POST['action'])) {
    $listing_id = (int)$_POST['listing_id'];
    $action = $_POST['action']; // 'approve' or 'reject'
    $rejection_reason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : '';
    
    // Ensure rejection_reason is never empty string for rejected listings
    if ($action === 'reject' && empty($rejection_reason)) {
        $rejection_reason = 'No reason provided';
    }

    // Get owner details for email notification
    $stmt = $conn->prepare("SELECT l.title, o.first_name, o.last_name, o.email
                           FROM tblistings l
                           JOIN tbadmin o ON o.id = l.owner_id
                           WHERE l.id = ?");
    $stmt->bind_param("i", $listing_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $listing_data = $result->fetch_assoc();
    $stmt->close();

    if ($action === 'approve') {
        // Approve listing
        $stmt = $conn->prepare("UPDATE tblistings
            SET verification_status = 'approved',
                is_verified = 1,
                is_archived = 0,
                is_available = 1,
                is_visible = 1,
                availability_status = 'available',
                verified_at = NOW(),
                verified_by = ?,
                verification_notes = 'Approved'
            WHERE id = ?");
        $stmt->bind_param("ii", $admin_id, $listing_id);
        $ok = $stmt->execute();
        $stmt->close();
    } else {
        // Reject listing
        $stmt = $conn->prepare("UPDATE tblistings
            SET verification_status = 'rejected',
                is_verified = -1,
                is_available = 0,
                is_visible = 0,
                availability_status = 'unavailable',
                verified_at = NOW(),
                verified_by = ?,
                verification_notes = ?,
                rejection_reason = ?
            WHERE id = ?");
        $rejected_note = 'Rejected';
        $stmt->bind_param("issi", $admin_id, $rejected_note, $rejection_reason, $listing_id);
        $ok = $stmt->execute();
        $stmt->close();
    }

    // Send email notification if we have owner's email
    if ($listing_data && !empty($listing_data['email'])) {
        $emailStatus = ($action === 'approve') ? 'approved' : 'rejected';
        try {
            require_once 'send_verification_result_email.php';
            $owner_full_name = trim(($listing_data['first_name'] ?? '') . ' ' . ($listing_data['last_name'] ?? ''));
            if (!sendVerificationResultEmail(
                $listing_data['email'],
                $owner_full_name,
                $listing_data['title'],
                $emailStatus,
                $rejection_reason,
                $listing_id
            )) {
                error_log("sendVerificationResultEmail returned false for listing {$listing_id}");
            }
        } catch (Throwable $e) {
            error_log('Verification email error: ' . $e->getMessage());
        }
    }

    if ($ok) {
        $_SESSION['flash'] = "Listing #$listing_id " . ($action === 'approve' ? "approved" : "rejected") . ".";
    } else {
        $_SESSION['flash_error'] = "Failed to update listing #$listing_id.";
    }

    // Keep current filter on redirect
    $status = $_GET['status'] ?? 'all';
    header("Location: admin_listings.php?status=" . urlencode($status));
    exit();
}

// --- Handle filter ---
$status = $_GET['status'] ?? 'all';
$where = "";
if ($status === 'pending') {
    $where = "l.is_verified = 0";
} elseif ($status === 'approved') {
    $where = "l.is_verified = 1";
} elseif ($status === 'rejected') {
    $where = "l.is_verified = -1";
}

// --- Fetch listings with owner info (apply filter if any) ---
$sql = "
  SELECT l.id, l.title, l.address, l.price, l.capacity, l.is_available, l.created_at,
         l.is_verified, l.verification_notes, l.verified_at, l.verified_by,
         l.rejection_reason, l.verification_status,
         l.total_units, l.occupied_units, l.description, l.amenities,
         l.bedroom, l.unit_sqm, l.kitchen, l.kitchen_type, l.kitchen_facility,
         l.gender_specific, l.pets,
         a.first_name, a.last_name, a.email,
         v.first_name as verifier_fname, v.last_name as verifier_lname
  FROM tblistings l
  JOIN tbadmin a ON a.id = l.owner_id
  LEFT JOIN tbadmin v ON v.id = l.verified_by
  WHERE l.is_archived = 0
  " . ($where ? " AND $where " : "") . "
  ORDER BY l.created_at DESC
";
$res = $conn->query($sql);
$listings = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$conn->close();

if (!empty($listings)) {
    $is_localhost = ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1');
    $ml_api_url = $is_localhost
        ? 'http://localhost/public_html/api/ml_suggest_price.php'
        : 'https://' . $_SERVER['HTTP_HOST'] . '/api/ml_suggest_price.php';

    $curl_available = function_exists('curl_init');

    foreach ($listings as &$listing) {
        $listing['ml_prediction'] = null;
        $listing['ml_price_alert'] = null;
        $listing['ml_diff_pct'] = null;

        if ((int)($listing['is_verified'] ?? 0) !== 0) {
            continue;
        }

        $capacity = max(1, (int)($listing['capacity'] ?? 1));
        $bedrooms = (int)($listing['bedroom'] ?? 1);
        $unit_sqm = (float)($listing['unit_sqm'] ?? 20);

        $property_type = infer_property_type_from_title($listing['title'] ?? '');
        $city = extract_city_from_address($listing['address'] ?? '') ?: 'Metro Manila';

        if ($curl_available) {
            $ml_input = [
                'Capacity' => $capacity,
                'Bedroom' => $bedrooms,
                'unit_sqm' => $unit_sqm,
                'cap_per_bedroom' => $capacity / max(1, $bedrooms),
                'Type' => $property_type,
                'Kitchen' => $listing['kitchen'] ?? 'Yes',
                'Kitchen type' => $listing['kitchen_type'] ?? 'Private',
                'Gender specific' => $listing['gender_specific'] ?? 'Mixed',
                'Pets' => $listing['pets'] ?? 'Allowed',
                'Location' => $city
            ];

            $ch = curl_init();
            if ($ch) {
                curl_setopt($ch, CURLOPT_URL, $ml_api_url);
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
                        $predicted_price = (float)$ml_data['prediction'];
                        $actual_price = (float)($listing['price'] ?? 0);
                        if ($predicted_price > 0) {
                            $diff_percent = (($actual_price - $predicted_price) / $predicted_price) * 100;
                            $listing['ml_prediction'] = round($predicted_price, 2);
                            $listing['ml_diff_pct'] = round($diff_percent, 1);

                            if ($diff_percent > 30) {
                                $listing['ml_price_alert'] = [
                                    'type' => 'overpriced',
                                    'diff_pct' => round($diff_percent, 1)
                                ];
                            } elseif ($diff_percent < -30) {
                                $listing['ml_price_alert'] = [
                                    'type' => 'underpriced',
                                    'diff_pct' => round(abs($diff_percent), 1)
                                ];
                            }
                        }
                    }
                }
            }
        }
    }
    unset($listing);
}

// simple helper
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin — Manage & Verify Listings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="darkmode.css">
  <style>
    body { background:#f7f7fb; }
    .topbar { background: #8B4513; color: #fff; }
    .table-container {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .notes-cell textarea{ width:100%; min-height:42px; resize:vertical; }
    .status-badge { font-size:.85rem; }
    .table thead th { white-space: nowrap; }
  </style>
</head>
<body>
    <!-- Top Navigation -->
    <?= getNavigationForRole('admin_listings.php') ?>

    <main class="container-fluid py-4">
        <h3 class="mb-4"><i class="bi bi-building"></i> Manage & Verify Property Listings</h3>

  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert alert-success"><?= h($_SESSION['flash']) ?></div>
    <?php unset($_SESSION['flash']); ?>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger"><?= h($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

        <!-- Filter Section -->
        <div class="row mb-4">
            <div class="col-md-6">
                <form method="get" class="d-flex align-items-center gap-3">
                    <label for="status" class="form-label fw-semibold mb-0">Filter by Status:</label>
                    <select id="status" name="status" class="form-select w-auto" onchange="this.form.submit()">
                        <option value="all"      <?= $status==='all' ? 'selected':'' ?>>All</option>
                        <option value="pending"  <?= $status==='pending' ? 'selected':'' ?>>Pending</option>
                        <option value="approved" <?= $status==='approved' ? 'selected':'' ?>>Approved</option>
                        <option value="rejected" <?= $status==='rejected' ? 'selected':'' ?>>Rejected</option>
                    </select>
                </form>
            </div>
        </div>

        <!-- Listings Table -->
        <div class="table-container">
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Title & Type</th>
                            <th>Owner Info</th>
                            <th>Location & Price</th>
                            <th>Units & Capacity</th>
                            <th>Amenities</th>
                            <th>Verification</th>
                            <th style="min-width:200px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
      <?php if (empty($listings)): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No listings found.</td></tr>
      <?php else: ?>
        <?php foreach ($listings as $row): ?>
          <tr>
            <td>
              <div class="fw-bold"><?= h($row['title']) ?></div>
              <div class="small text-muted">
                <i class="bi bi-calendar"></i> <?= date('M j, Y', strtotime($row['created_at'])) ?>
              </div>
              <div class="mt-2">
                <?php if (!empty($row['bedroom'])): ?>
                  <span class="badge bg-info text-dark"><?= $row['bedroom'] ?> BR</span>
                <?php endif; ?>
                <?php if (!empty($row['unit_sqm'])): ?>
                  <span class="badge bg-info text-dark"><?= $row['unit_sqm'] ?> sqm</span>
                <?php endif; ?>
              </div>
            </td>

            <td>
              <div class="fw-bold"><?= h(trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''))) ?></div>
              <div class="small text-muted"><?= h($row['email']) ?></div>
            </td>

            <td>
              <div><?= h($row['address']) ?></div>
              <div class="fw-bold mt-1">₱<?= number_format((float)$row['price'], 2) ?>/month</div>
              <?php
                $mlAlert = $row['ml_price_alert'] ?? null;
                if ((int)($row['is_verified'] ?? 0) === 0 && $mlAlert && !empty($row['ml_prediction'])):
                    $alertClass = $mlAlert['type'] === 'overpriced' ? 'alert-danger' : 'alert-info';
                    $alertLabel = $mlAlert['type'] === 'overpriced' ? 'Above market estimate' : 'Below market estimate';
                    $diffDisplay = number_format((float)$mlAlert['diff_pct'], 1);
              ?>
                <div class="alert <?= $alertClass ?> mt-2 py-2 px-3 small">
                  <div class="fw-bold text-uppercase mb-1">ML Pricing Alert</div>
                  <div>Predicted: <strong>₱<?= number_format((float)$row['ml_prediction'], 2) ?></strong></div>
                  <div><?= $alertLabel ?> (<?= $diffDisplay ?>%)</div>
                  <div class="text-muted">This listing may need a pricing review.</div>
                </div>
              <?php endif; ?>
            </td>

            <td>
              <div>
                <span class="badge <?= ($row['total_units'] - $row['occupied_units']) > 0 ? 'bg-success' : 'bg-danger' ?>">
                  <?= ($row['total_units'] - $row['occupied_units']) ?>/<?= $row['total_units'] ?> Available
                </span>
              </div>
              <div class="mt-2">
                <span class="badge bg-warning text-dark">Capacity: <?= (int)$row['capacity'] ?></span>
              </div>
              <div class="small text-muted mt-2">
                <div>Gender: <?= h($row['gender_specific']) ?></div>
                <div>Pets: <?= h($row['pets']) ?></div>
              </div>
            </td>

            <td>
              <?php if ($row['kitchen'] === 'Yes'): ?>
                <div><i class="bi bi-check-circle-fill text-success"></i> Kitchen (<?= h($row['kitchen_type']) ?>)</div>
                <?php if (!empty($row['kitchen_facility'])): ?>
                  <div class="small text-muted">- <?= h($row['kitchen_facility']) ?> Cooking</div>
                <?php endif; ?>
              <?php endif; ?>
              
              <?php 
              $amenities = !empty($row['amenities']) ? array_map('trim', explode(',', $row['amenities'])) : [];
              foreach ($amenities as $amenity): 
              ?>
                <div><i class="bi bi-check-circle-fill text-success"></i> <?= h(ucfirst($amenity)) ?></div>
              <?php endforeach; ?>
            </td>

            <td>
              <div>
                <?php if ((int)$row['is_verified'] === 1): ?>
                  <span class="badge bg-success status-badge">Approved</span>
                <?php elseif ((int)$row['is_verified'] === -1): ?>
                  <span class="badge bg-danger status-badge">Rejected</span>
                <?php else: ?>
                  <span class="badge bg-warning text-dark status-badge">Pending</span>
                <?php endif; ?>
              </div>

              <?php if (!empty($row['verified_at'])): ?>
                <div class="small text-muted mt-1">
                  <?= date('M j, Y', strtotime($row['verified_at'])) ?>
                  <?php if (!empty($row['verifier_fname'])): ?>
                    <br>by <?= h($row['verifier_fname'] . ' ' . $row['verifier_lname']) ?>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <?php if ((int)$row['is_verified'] === -1 && !empty($row['rejection_reason'])): ?>
                <div class="alert alert-danger small mt-2 mb-0 p-2">
                  <?= h($row['rejection_reason']) ?>
                </div>
              <?php endif; ?>
            </td>

            <td class="text-nowrap">
              <a href="admin_view_listing.php?id=<?= (int)$row['id'] ?>"
                 class="btn btn-sm btn-outline-primary"
                 title="View Details">
                <i class="bi bi-eye"></i> View
              </a>
              
              <a href="admin_transactions.php?property_id=<?= (int)$row['id'] ?>"
                 class="btn btn-sm btn-outline-info mt-1"
                 title="View Transactions for this Property">
                <i class="bi bi-receipt"></i> Transactions
              </a>
              
              <?php if ((int)$row['is_verified'] === 0): ?>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="listing_id" value="<?= (int)$row['id'] ?>">
                  <input type="hidden" name="action" value="approve">
                  <button type="submit" class="btn btn-sm btn-success mt-2" onclick="return confirm('Approve this listing? It will become visible to tenants.')">
                    <i class="bi bi-check-circle"></i> Approve
                  </button>
                </form>

                <button type="button"
                        class="btn btn-sm btn-danger mt-2"
                        data-bs-toggle="modal"
                        data-bs-target="#rejectModal<?= (int)$row['id'] ?>">
                  <i class="bi bi-x-circle"></i> Reject
                </button>

                <div class="modal fade" id="rejectModal<?= (int)$row['id'] ?>" tabindex="-1" aria-labelledby="rejectModalLabel<?= (int)$row['id'] ?>" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                      <form method="POST">
                        <div class="modal-header">
                          <h5 class="modal-title" id="rejectModalLabel<?= (int)$row['id'] ?>">Reject Listing #<?= (int)$row['id'] ?></h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                          <input type="hidden" name="listing_id" value="<?= (int)$row['id'] ?>">
                          <input type="hidden" name="action" value="reject">
                          <div class="mb-3">
                            <label class="form-label">Reason for rejection <span class="text-danger">*</span></label>
                            <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="Explain why this listing is being rejected..."></textarea>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                          <button type="submit" class="btn btn-danger">Reject Listing</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="darkmode.js"></script>
</body>
</html>
