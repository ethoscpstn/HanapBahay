<?php
session_start();
require 'mysql_connect.php';

// Only unit owners can access
if (!isset($_SESSION['owner_id']) || ($_SESSION['role'] ?? '') !== 'unit_owner') {
    header("Location: LoginModule.php");
    exit();
}

$owner_id = (int)$_SESSION['owner_id'];

// Fetch all rental requests for this owner's properties
$stmt = $conn->prepare("
    SELECT
        rr.id, rr.tenant_id, rr.listing_id, rr.payment_method, rr.payment_option,
        rr.amount_to_pay, rr.status, rr.requested_at, rr.receipt_path,
        l.title AS property_title, l.address, l.price,
        t.first_name, t.last_name, t.email AS tenant_email
    FROM rental_requests rr
    JOIN tblistings l ON rr.listing_id = l.id
    JOIN tbadmin t ON rr.tenant_id = t.id
    WHERE l.owner_id = ?
    ORDER BY rr.requested_at DESC
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$result = $stmt->get_result();
$requests = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Requests - HanapBahay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f7f7fb; }
        .topbar { background: #8B4513; color: #fff; }
        .logo { height:42px; }
        .request-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 16px;
            transition: transform 0.2s;
        }
        .request-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .status-badge {
            font-size: 0.85rem;
            padding: 4px 12px;
        }
        .receipt-preview {
            max-width: 200px;
            border-radius: 8px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="topbar py-2">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <img src="Assets/Logo1.png" class="logo" alt="HanapBahay" style="height:42px;">
                <strong>Tenant Requests</strong>
            </div>
            <div class="d-flex gap-2">
                <a href="DashboardUO.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
                <a href="logout.php" class="btn btn-sm btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0">Rental Applications</h4>
            <span class="badge bg-primary"><?= count($requests) ?> Total Requests</span>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (empty($requests)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No rental requests yet. Tenants will appear here when they apply for your properties.
            </div>
        <?php else: ?>
            <?php foreach ($requests as $req): ?>
                <div class="request-card p-4">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="mb-1"><?= htmlspecialchars($req['property_title']) ?></h5>
                                    <p class="text-muted small mb-2">
                                        <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($req['address']) ?>
                                    </p>
                                </div>
                                <span class="status-badge badge bg-<?= $req['status'] === 'pending' ? 'warning' : ($req['status'] === 'approved' ? 'success' : 'danger') ?>">
                                    <?= ucfirst($req['status']) ?>
                                </span>
                            </div>

                            <div class="row mb-2">
                                <div class="col-sm-6">
                                    <strong>Tenant:</strong> <?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?><br>
                                    <strong>Email:</strong> <?= htmlspecialchars($req['tenant_email']) ?>
                                </div>
                                <div class="col-sm-6">
                                    <strong>Payment Method:</strong> <?= ucfirst($req['payment_method']) ?><br>
                                    <strong>Payment Option:</strong> <?= ucfirst($req['payment_option'] ?? 'N/A') ?><br>
                                    <strong>Amount:</strong> ₱<?= number_format($req['amount_to_pay'] ?? 0, 2) ?>
                                </div>
                            </div>

                            <small class="text-muted">
                                <i class="bi bi-clock"></i> Requested: <?= date('M d, Y h:i A', strtotime($req['requested_at'])) ?>
                            </small>
                        </div>

                        <div class="col-md-4 text-center">
                            <?php if (!empty($req['receipt_path'])): ?>
                                <p class="small mb-2"><strong>Payment Receipt:</strong></p>
                                <img src="<?= htmlspecialchars($req['receipt_path']) ?>"
                                     alt="Receipt"
                                     class="receipt-preview img-fluid"
                                     onclick="window.open(this.src, '_blank')">
                                <p class="small text-muted mt-1">Click to enlarge</p>
                            <?php else: ?>
                                <p class="text-muted small">No receipt uploaded</p>
                            <?php endif; ?>

                            <div class="d-flex gap-2 justify-content-center mt-3">
                                <?php if ($req['status'] === 'pending'): ?>
                                    <form method="POST" action="update_request_status.php" class="d-inline">
                                        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                        <input type="hidden" name="status" value="approved">
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="bi bi-check-circle"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" action="update_request_status.php" class="d-inline">
                                        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                        <input type="hidden" name="status" value="rejected">
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <i class="bi bi-x-circle"></i> Reject
                                        </button>
                                    </form>
                                <?php endif; ?>
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
