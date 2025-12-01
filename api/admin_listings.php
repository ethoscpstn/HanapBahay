<?php
// admin_listings.php — manage property listing approvals
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'mysql_connect.php';

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
                is_verified = 0,
                is_available = 0,
                is_visible = 0,
                availability_status = 'unavailable',
                verified_at = NOW(),
                verified_by = ?,
                verification_notes = ?,
                rejection_reason = ?
            WHERE id = ?");
        $stmt->bind_param("issi", $admin_id, 'Rejected', $rejection_reason, $listing_id);
        $ok = $stmt->execute();
        $stmt->close();
    }

    // Send email notification if we have owner's email
    if ($listing_data && !empty($listing_data['email'])) {
        $emailStatus = ($action === 'approve') ? 'approved' : 'rejected';
        try {
            require_once 'send_verification_result_email.php';
            $ownerName = trim(($listing_data['first_name'] ?? '') . ' ' . ($listing_data['last_name'] ?? ''));
            if (!sendVerificationResultEmail(
                $listing_data['email'],
                $ownerName,
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

// simple helper
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin — Manage Listings</title>
    <link rel="icon" type="image/png" sizes="16x16" href="Assets/HanapBahayTablogo.png?v=2">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f7f7f7; }
    .notes-cell textarea{ width:100%; min-height:42px; resize:vertical; }
    .status-badge { font-size:.85rem; }
    .table thead th { white-space: nowrap; }
  </style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Manage Property Listings</h3>
    <div class="d-flex gap-2">
      <a href="admin_price_analytics.php" class="btn btn-info btn-sm">
        <i class="bi bi-graph-up-arrow"></i> Price Analytics
      </a>
      <a href="admin_transactions.php" class="btn btn-primary btn-sm">
        <i class="bi bi-receipt"></i> View Transactions
      </a>
      <a href="DashboardUO.php" class="btn btn-outline-secondary btn-sm ms-2">Back</a>
      <a href="logout.php" class="btn btn-outline-danger btn-sm ms-2">Logout</a>
    </div>
  </div>

  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert alert-success"><?= h($_SESSION['flash']) ?></div>
    <?php unset($_SESSION['flash']); ?>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger"><?= h($_SESSION['flash_error']) ?></div>
    <?php unset($_SESSION['flash_error']); ?>
  <?php endif; ?>

  <!-- Filter -->
  <form method="get" class="mb-3">
    <label for="status" class="form-label fw-semibold me-2">Filter by Status:</label>
    <select id="status" name="status" class="form-select d-inline-block w-auto" onchange="this.form.submit()">
      <option value="all"      <?= $status==='all' ? 'selected':'' ?>>All</option>
      <option value="pending"  <?= $status==='pending' ? 'selected':'' ?>>Pending</option>
      <option value="approved" <?= $status==='approved' ? 'selected':'' ?>>Approved</option>
      <option value="rejected" <?= $status==='rejected' ? 'selected':'' ?>>Rejected</option>
    </select>
  </form>

  <div class="table-responsive">
    <table class="table table-bordered table-hover bg-white">
      <thead class="table-dark">
        <tr>
          <th>ID</th>
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
        <tr><td colspan="8" class="text-center text-muted py-4">No listings found.</td></tr>
      <?php else: ?>
        <?php foreach ($listings as $row): ?>
          <tr>
            <td><?= (int)$row['id'] ?></td>
            
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
              <a href="admin_view_listing.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-primary" title="View Details">
                <i class="bi bi-eye"></i> View
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
                  <div class="modal-dialog">
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
