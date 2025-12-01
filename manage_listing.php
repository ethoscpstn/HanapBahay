<?php
session_start();
require 'mysql_connect.php';
require 'send_verification_result_email.php';

// Only admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginModule.php");
    exit();
}

$admin_id = (int)($_SESSION['user_id'] ?? 0);

// Handle verification/management actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['listing_id'])) {
    $listing_id = (int)$_POST['listing_id'];
    $action = $_POST['action']; // 'approve' or 'reject'
    $rejection_reason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : '';

    // Ensure rejection_reason is never empty string - use default message
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
        // Approve: is_verified = 1, log timestamp and admin
        $stmt = $conn->prepare("UPDATE tblistings
            SET verification_status = 'approved',
                is_verified = 1,
                is_archived = 0,
                verified_at = NOW(),
                verified_by = ?,
                verification_notes = 'Approved'
            WHERE id = ?");
        $stmt->bind_param("ii", $admin_id, $listing_id);
        $stmt->execute();
        $stmt->close();

        // Send email notification
        if ($listing_data) {
            $ownerName = trim($listing_data['first_name'] . ' ' . $listing_data['last_name']);
            sendVerificationResultEmail(
                $listing_data['email'],
                $ownerName,
                $listing_data['title'],
                'approved',
                '',
                $listing_id
            );
        }

        $_SESSION['success'] = "Listing approved successfully! Owner has been notified.";
    } elseif ($action === 'reject') {
        // Reject: is_verified = -1, keep visible (is_archived = 0) for owner to see
        $stmt = $conn->prepare("UPDATE tblistings
            SET verification_status = 'rejected',
                rejection_reason = ?,
                is_verified = -1,
                is_archived = 0,
                verified_at = NOW(),
                verified_by = ?,
                verification_notes = ?
            WHERE id = ?");
        $stmt->bind_param("siis", $rejection_reason, $admin_id, $rejection_reason, $listing_id);
        $stmt->execute();
        $stmt->close();

        // Send email notification
        if ($listing_data) {
            $ownerName = trim($listing_data['first_name'] . ' ' . $listing_data['last_name']);
            sendVerificationResultEmail(
                $listing_data['email'],
                $ownerName,
                $listing_data['title'],
                'rejected',
                $rejection_reason,
                $listing_id
            );
        }

        $_SESSION['success'] = "Listing rejected. Owner has been notified.";
    } elseif ($action === 'archive') {
        // Archive listing
        $stmt = $conn->prepare("UPDATE tblistings SET is_archived = 1 WHERE id = ?");
        $stmt->bind_param("i", $listing_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['success'] = "Listing archived successfully.";
    }

    header("Location: manage_listing.php?" . ($_GET['tab'] ?? 'tab=pending'));
    exit();
}

// Get current tab
$tab = $_GET['tab'] ?? 'pending';

// Fetch pending listings (for verification tab)
$stmt = $conn->prepare("
    SELECT l.id, l.title, l.address, l.price, l.capacity, l.description, l.amenities,
           l.gov_id_path, l.property_photos, l.verification_status, l.rejection_reason,
           l.created_at, l.bedroom, l.unit_sqm, l.kitchen, l.kitchen_type, l.gender_specific, l.pets,
           o.first_name, o.last_name, o.email, o.id as owner_id
    FROM tblistings l
    JOIN tbadmin o ON o.id = l.owner_id
    WHERE l.verification_status = 'pending' AND l.is_archived = 0
    ORDER BY l.created_at ASC
");
$stmt->execute();
$result = $stmt->get_result();
$pending_listings = [];
while ($row = $result->fetch_assoc()) {
    if (!empty($row['property_photos'])) {
        $row['property_photos_array'] = json_decode($row['property_photos'], true) ?: [];
    } else {
        $row['property_photos_array'] = [];
    }

    // Get ML price prediction for pricing alert
    $row['ml_prediction'] = null;
    $row['price_alert'] = null;

    // Derive property type from title
    $title_lower = strtolower($row['title']);
    if (strpos($title_lower, 'studio') !== false) {
        $property_type = 'Studio';
    } elseif (strpos($title_lower, 'apartment') !== false) {
        $property_type = 'Apartment';
    } elseif (strpos($title_lower, 'condo') !== false) {
        $property_type = 'Condominium';
    } elseif (strpos($title_lower, 'house') !== false || strpos($title_lower, 'boarding') !== false) {
        $property_type = 'Boarding House';
    } else {
        $property_type = 'Apartment';
    }

    // Prepare ML input
    $ml_input = [
        'Capacity' => (int)$row['capacity'],
        'Bedroom' => (int)($row['bedroom'] ?? 1),
        'unit_sqm' => (float)($row['unit_sqm'] ?? 20),
        'cap_per_bedroom' => (int)$row['capacity'] / max(1, (int)($row['bedroom'] ?? 1)),
        'Type' => $property_type,
        'Kitchen' => ($row['kitchen'] ?? 'Yes'),
        'Kitchen type' => ($row['kitchen_type'] ?? 'Private'),
        'Gender specific' => ($row['gender_specific'] ?? 'Mixed'),
        'Pets' => ($row['pets'] ?? 'Allowed'),
        'Location' => 'Quezon City'
    ];

    // Call ML API with short timeout
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
    $ml_error = curl_error($ch);
    curl_close($ch);

    if ($ml_response && !$ml_error) {
        $ml_data = json_decode($ml_response, true);
        if (isset($ml_data['prediction'])) {
            $row['ml_prediction'] = $ml_data['prediction'];
            $actual_price = (float)$row['price'];
            $predicted_price = (float)$ml_data['prediction'];

            // Calculate percentage difference
            $diff_percent = (($actual_price - $predicted_price) / $predicted_price) * 100;

            // Flag if price is >30% above or below market
            if ($diff_percent > 30) {
                $row['price_alert'] = [
                    'type' => 'overpriced',
                    'diff' => $diff_percent,
                    'message' => 'Significantly above market value'
                ];
            } elseif ($diff_percent < -30) {
                $row['price_alert'] = [
                    'type' => 'underpriced',
                    'diff' => abs($diff_percent),
                    'message' => 'Significantly below market value'
                ];
            }
        }
    }

    $pending_listings[] = $row;
}
$stmt->close();

// Fetch all listings (for management tab)
$stmt = $conn->prepare("
    SELECT l.id, l.title, l.address, l.price, l.capacity, l.is_available, l.created_at,
           l.is_verified, l.verification_status, l.verified_at, l.rejection_reason,
           l.total_units, l.occupied_units,
           a.first_name, a.last_name, a.email,
           v.first_name as verifier_fname, v.last_name as verifier_lname
    FROM tblistings l
    JOIN tbadmin a ON a.id = l.owner_id
    LEFT JOIN tbadmin v ON v.id = l.verified_by
    WHERE l.is_archived = 0
    ORDER BY l.created_at DESC
");
$stmt->execute();
$result = $stmt->get_result();
$all_listings = [];
while ($row = $result->fetch_assoc()) {
    $all_listings[] = $row;
}
$stmt->close();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Listings - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="Assets/brand.css?v=20250215" rel="stylesheet">
    <link rel="stylesheet" href="darkmode.css">
    <style>
        body { background: #f7f7fb; }
        .topbar { background: #8B4513; color: #fff; }
        .nav-tabs .nav-link { color: #666; }
        .nav-tabs .nav-link.active { color: #8B4513; font-weight: bold; }
        .listing-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .section-header {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            border-left: 4px solid #8B4513;
        }
        .photo-preview {
            max-width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 10px;
            border: 2px solid #dee2e6;
            cursor: pointer;
        }
        .photo-preview:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .id-preview {
            max-width: 100%;
            max-height: 400px;
            border-radius: 8px;
            border: 3px solid #ffc107;
            cursor: pointer;
        }
        .badge-count {
            background: #8B4513;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        .id-container {
            background: #fffbf0;
            padding: 15px;
            border-radius: 8px;
            border: 2px solid #ffc107;
        }
        .no-content-box {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #f5c6cb;
            text-align: center;
        }
        .table-responsive { overflow-x: auto; }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="topbar py-2">
        <div class="container-fluid d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <img src="Assets/Logo1.png" class="logo" alt="HanapBahay" style="height:42px;">
                <strong>Admin - Manage Listings</strong>
            </div>
            <div class="d-flex gap-2">
                <a href="admin_price_analytics.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-graph-up-arrow"></i> Price Analytics
                </a>
                <a href="admin_transactions.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-receipt"></i> Transactions
                </a>
                <a href="logout.php" class="btn btn-sm btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>

    <main class="container-fluid py-4">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= htmlspecialchars($_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link <?= $tab === 'pending' ? 'active' : '' ?>" href="?tab=pending">
                    <i class="bi bi-shield-check"></i> Pending Verification (<?= count($pending_listings) ?>)
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link <?= $tab === 'all' ? 'active' : '' ?>" href="?tab=all">
                    <i class="bi bi-building"></i> All Listings (<?= count($all_listings) ?>)
                </a>
            </li>
        </ul>

        <!-- Tab Content -->
        <?php if ($tab === 'pending'): ?>
            <!-- Pending Verification Tab -->
            <?php if (empty($pending_listings)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> No listings pending verification at this time.
                </div>
            <?php else: ?>
                <?php foreach ($pending_listings as $listing): ?>
                    <?php
                    $ownerName = trim($listing['first_name'] . ' ' . $listing['last_name']);
                    $amenities_arr = !empty($listing['amenities']) ? explode(', ', $listing['amenities']) : [];
                    ?>
                    <div class="listing-card">
                        <?php if ($listing['price_alert']): ?>
                            <div class="alert alert-<?= $listing['price_alert']['type'] === 'overpriced' ? 'warning' : 'info' ?> mb-3">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="bi bi-exclamation-triangle-fill fs-4"></i>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1"><strong>ML Pricing Alert</strong></h6>
                                        <p class="mb-2">
                                            This listing is priced <strong><?= $listing['price_alert']['type'] === 'overpriced' ? 'significantly ABOVE' : 'significantly BELOW' ?></strong> market value.
                                        </p>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <small class="text-muted d-block">Listed Price:</small>
                                                <strong class="fs-5">₱<?= number_format($listing['price'], 2) ?></strong>
                                            </div>
                                            <div class="col-md-4">
                                                <small class="text-muted d-block">ML Predicted:</small>
                                                <strong class="fs-5 text-primary">₱<?= number_format($listing['ml_prediction'], 2) ?></strong>
                                            </div>
                                            <div class="col-md-4">
                                                <small class="text-muted d-block">Difference:</small>
                                                <strong class="fs-5 text-<?= $listing['price_alert']['type'] === 'overpriced' ? 'danger' : 'success' ?>">
                                                    <?= $listing['price_alert']['type'] === 'overpriced' ? '+' : '-' ?><?= number_format($listing['price_alert']['diff'], 1) ?>%
                                                </strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="row">
                            <!-- Property Photos -->
                            <div class="col-md-4">
                                <div class="section-header">
                                    <h5 class="mb-0">
                                        <i class="bi bi-camera-fill"></i> Property Photos
                                        <?php if (!empty($listing['property_photos_array'])): ?>
                                            <span class="badge-count"><?= count($listing['property_photos_array']) ?></span>
                                        <?php endif; ?>
                                    </h5>
                                </div>
                                <?php if (!empty($listing['property_photos_array'])): ?>
                                    <div class="photo-grid">
                                        <?php foreach ($listing['property_photos_array'] as $idx => $photo): ?>
                                            <div>
                                                <small class="text-muted d-block mb-1">Photo <?= $idx + 1 ?>:</small>
                                                <a href="<?= htmlspecialchars($photo) ?>" target="_blank" title="Click to view full size">
                                                    <img src="<?= htmlspecialchars($photo) ?>" alt="Property Photo <?= $idx + 1 ?>" class="photo-preview">
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="no-content-box">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        <strong>No photos uploaded</strong>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Property Details -->
                            <div class="col-md-4">
                                <div class="section-header">
                                    <h5 class="mb-0"><i class="bi bi-info-circle-fill"></i> Property Details</h5>
                                </div>
                                <p><strong>Title:</strong> <?= htmlspecialchars($listing['title']) ?></p>
                                <p><strong>Address:</strong> <?= htmlspecialchars($listing['address']) ?></p>
                                <p><strong>Price:</strong> <span class="text-success fw-bold">₱<?= number_format($listing['price'], 2) ?>/month</span></p>
                                <p><strong>Capacity:</strong> <?= (int)$listing['capacity'] ?> person(s)</p>
                                <p><strong>Description:</strong> <?= nl2br(htmlspecialchars($listing['description'])) ?></p>
                                <?php if (!empty($amenities_arr)): ?>
                                    <p><strong>Amenities:</strong><br>
                                        <?php foreach ($amenities_arr as $amenity): ?>
                                            <span class="badge bg-secondary me-1 mb-1"><?= htmlspecialchars($amenity) ?></span>
                                        <?php endforeach; ?>
                                    </p>
                                <?php endif; ?>
                                <hr>
                                <div class="section-header">
                                    <h5 class="mb-0"><i class="bi bi-person-fill"></i> Owner Information</h5>
                                </div>
                                <p><strong>Name:</strong> <?= htmlspecialchars($ownerName) ?></p>
                                <p><strong>Email:</strong> <a href="mailto:<?= htmlspecialchars($listing['email']) ?>"><?= htmlspecialchars($listing['email']) ?></a></p>
                                <p><strong>Submitted:</strong> <?= date('M d, Y g:i A', strtotime($listing['created_at'])) ?></p>
                            </div>

                            <!-- Government ID & Actions -->
                            <div class="col-md-4">
                                <div class="section-header">
                                    <h5 class="mb-0"><i class="bi bi-shield-check"></i> Government ID Verification</h5>
                                </div>
                                <?php if (!empty($listing['gov_id_path'])): ?>
                                    <div class="id-container">
                                        <?php
                                        $ext = strtolower(pathinfo($listing['gov_id_path'], PATHINFO_EXTENSION));
                                        if ($ext === 'pdf'):
                                        ?>
                                            <div class="text-center mb-3">
                                                <i class="bi bi-file-pdf" style="font-size: 3rem; color: #dc3545;"></i>
                                                <p class="mb-2"><strong>PDF Document</strong></p>
                                            </div>
                                            <a href="<?= htmlspecialchars($listing['gov_id_path']) ?>" target="_blank" class="btn btn-danger w-100 mb-2">
                                                <i class="bi bi-file-pdf"></i> Open PDF in New Tab
                                            </a>
                                        <?php else: ?>
                                            <small class="text-muted d-block mb-2 text-center">Click image to view full size</small>
                                            <a href="<?= htmlspecialchars($listing['gov_id_path']) ?>" target="_blank" title="Click to view full size">
                                                <img src="<?= htmlspecialchars($listing['gov_id_path']) ?>" alt="Government ID" class="id-preview">
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="no-content-box">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        <strong>No ID uploaded</strong>
                                    </div>
                                <?php endif; ?>

                                <hr class="my-4">
                                <div class="section-header">
                                    <h5 class="mb-0"><i class="bi bi-hand-thumbs-up"></i> Verification Actions</h5>
                                </div>
                                <form method="POST" class="mb-2">
                                    <input type="hidden" name="listing_id" value="<?= $listing['id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button type="submit" class="btn btn-success w-100 btn-lg mb-2" onclick="return confirm('✓ Approve this listing?\n\nThe property will become visible to all tenants.')">
                                        <i class="bi bi-check-circle-fill"></i> Approve Listing
                                    </button>
                                </form>
                                <button class="btn btn-danger w-100 btn-lg" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $listing['id'] ?>">
                                    <i class="bi bi-x-circle-fill"></i> Reject Listing
                                </button>

                                <!-- Reject Modal -->
                                <div class="modal fade" id="rejectModal<?= $listing['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Reject Listing</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <input type="hidden" name="listing_id" value="<?= $listing['id'] ?>">
                                                    <input type="hidden" name="action" value="reject">
                                                    <div class="mb-3">
                                                        <label class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                                                        <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="Explain why this listing is being rejected..."></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-danger">Reject</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php else: ?>
            <!-- All Listings Tab -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Owner</th>
                                    <th>Price</th>
                                    <th>Capacity</th>
                                    <th>Units</th>
                                    <th>Status</th>
                                    <th>Verification</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_listings as $listing): ?>
                                    <?php
                                    $ownerName = trim($listing['first_name'] . ' ' . $listing['last_name']);
                                    $available_units = max(0, (int)$listing['total_units'] - (int)$listing['occupied_units']);

                                    // Determine status badge
                                    if ($listing['verification_status'] === 'approved') {
                                        $status_badge = '<span class="badge bg-success">Approved</span>';
                                    } elseif ($listing['verification_status'] === 'rejected') {
                                        $status_badge = '<span class="badge bg-danger">Rejected</span>';
                                    } else {
                                        $status_badge = '<span class="badge bg-warning">Pending</span>';
                                    }
                                    ?>
                                    <tr>
                                        <td><?= h($listing['id']) ?></td>
                                        <td><?= h($listing['title']) ?></td>
                                        <td><?= h($ownerName) ?><br><small class="text-muted"><?= h($listing['email']) ?></small></td>
                                        <td>₱<?= number_format($listing['price'], 2) ?></td>
                                        <td><?= (int)$listing['capacity'] ?></td>
                                        <td><?= $available_units ?> / <?= (int)$listing['total_units'] ?></td>
                                        <td><?= $listing['is_available'] ? '<span class="badge bg-success">Available</span>' : '<span class="badge bg-secondary">Unavailable</span>' ?></td>
                                        <td><?= $status_badge ?></td>
                                        <td><?= date('M d, Y', strtotime($listing['created_at'])) ?></td>
                                        <td>
                                            <a href="property_details.php?id=<?= $listing['id'] ?>" class="btn btn-sm btn-info" target="_blank">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($listing['verification_status'] === 'pending'): ?>
                                                <a href="?tab=pending" class="btn btn-sm btn-warning" title="Verify">
                                                    <i class="bi bi-shield-check"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="darkmode.js"></script>
</body>
</html>
<?php $conn->close(); ?>
