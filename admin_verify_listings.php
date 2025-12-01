<?php
session_start();
require 'mysql_connect.php';
require 'send_verification_result_email.php';

// Only admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginModule.php");
    exit();
}

// Handle verification action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['listing_id'])) {
    $listing_id = (int)$_POST['listing_id'];
    $action = $_POST['action']; // 'approve' or 'reject'
    $rejection_reason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : '';

    // Ensure rejection_reason is never empty string - use NULL instead for rejected listings
    if ($action === 'reject' && empty($rejection_reason)) {
        $rejection_reason = 'No reason provided';
    }

    $admin_id = (int)($_SESSION['user_id'] ?? 0);

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
                is_available = 1,
                is_visible = 1,
                availability_status = 'available',
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
                is_available = 0,
                is_visible = 0,
                availability_status = 'unavailable',
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
    }

    header("Location: admin_verify_listings.php");
    exit();
}

// Fetch pending listings
$stmt = $conn->prepare("
    SELECT l.id, l.title, l.address, l.price, l.capacity, l.description, l.amenities,
           l.gov_id_path, l.property_photos, l.verification_status, l.rejection_reason,
           l.created_at, l.bedroom, l.unit_sqm, l.kitchen, l.kitchen_type, l.gender_specific, l.pets,
           o.first_name, o.last_name, o.email, o.id as owner_id
    FROM tblistings l
    JOIN tbadmin o ON o.id = l.owner_id
    WHERE l.verification_status = 'pending'
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
        $property_type = 'Condo';
    } elseif (strpos($title_lower, 'house') !== false) {
        $property_type = 'House';
    } elseif (strpos($title_lower, 'room') !== false) {
        $property_type = 'Room';
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

    // Call ML API
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Listings - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f7f7fb; }
        .topbar { background: #8B4513; color: #fff; }
        .listing-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: 2px solid #e0e0e0;
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
            transition: transform 0.2s;
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
            transition: transform 0.2s;
            background: #fff;
            padding: 5px;
        }
        .id-preview:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .photo-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
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
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="topbar py-2">
        <div class="container-fluid d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <img src="Assets/Logo1.png" class="logo" alt="HanapBahay" style="height:42px;">
                <strong>Admin - Verify Listings</strong>
            </div>
            <div class="d-flex gap-2">
                <a href="admin_listings.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-building"></i> Manage Listings
                </a>
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

        <h3 class="mb-4"><i class="bi bi-shield-check"></i> Pending Verification (<?= count($pending_listings) ?>)</h3>

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
                                    <small class="text-muted mt-2 d-block">
                                        <i class="bi bi-info-circle"></i>
                                        <?= $listing['price_alert']['type'] === 'overpriced'
                                            ? 'This may indicate overpricing or premium features not captured in the model.'
                                            : 'This may indicate underpricing, a special deal, or data entry errors.' ?>
                                    </small>
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
                                        <small class="text-muted d-block text-center">Click above to view the government ID</small>
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
                            <div class="alert alert-warning mb-3">
                                <small><i class="bi bi-info-circle"></i> <strong>Review checklist:</strong></small>
                                <ul class="mb-0 mt-2" style="font-size: 0.85rem;">
                                    <li>Verify government ID is clear and valid</li>
                                    <li>Check property photos show actual property</li>
                                    <li>Confirm property details are accurate</li>
                                </ul>
                            </div>
                            <form method="POST" class="mb-2">
                                <input type="hidden" name="listing_id" value="<?= $listing['id'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn btn-success w-100 btn-lg" onclick="return confirm('✓ Approve this listing?\n\nThe property will become visible to all tenants.')">
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
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php $conn->close(); ?>
