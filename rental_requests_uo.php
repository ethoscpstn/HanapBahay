<?php
session_start();
require 'mysql_connect.php';
require_once 'includes/navigation.php';

if (!isset($_SESSION['owner_id']) || $_SESSION['role'] !== 'unit_owner') {
    header("Location: LoginModule.php");
    exit();
}

$owner_id = (int)$_SESSION['owner_id'];

// Fetch rental requests for this owner's properties
$stmt = $conn->prepare("
    SELECT rr.id, rr.tenant_id, rr.listing_id, rr.payment_method, rr.payment_option, rr.amount_due,
           rr.status, rr.requested_at, rr.receipt_path, rr.rejection_reason, rr.rejection_message,
           l.title AS property_title, l.address AS property_address, l.price AS property_price,
           t.first_name AS tenant_first_name, t.last_name AS tenant_last_name,
           t.email AS tenant_email
    FROM rental_requests rr
    JOIN tblistings l ON l.id = rr.listing_id
    JOIN tbadmin t ON t.id = rr.tenant_id
    WHERE l.owner_id = ?
    ORDER BY rr.requested_at DESC
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$result = $stmt->get_result();
$requests = [];
while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rental Requests - HanapBahay</title>
    <link rel="icon" type="image/png" sizes="16x16" href="Assets/HanapBahayTablogo.png?v=2">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="darkmode.css">
    <style>
        body { background: #f7f7fb; }
        .topbar { background: #8B4513; color: #fff; }
        .logo { height:42px; }
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d1e7dd; color: #0f5132; }
        .status-rejected { background: #f8d7da; color: #842029; }
        .status-cancelled { background: #e2e3e5; color: #383d41; }
        .request-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .request-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <?= getNavigationForRole('rental_requests_uo.php') ?>

    <main class="container py-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3><i class="bi bi-file-earmark-text"></i> Rental Requests</h3>
                    <span class="badge bg-primary"><?= count($requests) ?> Total</span>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

                <?php if (empty($requests)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> You have no rental requests yet.
                    </div>
                <?php else: ?>
                    <?php foreach ($requests as $req): ?>
                        <?php
                        $statusClass = 'status-pending';
                        if ($req['status'] === 'approved') $statusClass = 'status-approved';
                        if ($req['status'] === 'rejected') $statusClass = 'status-rejected';
                        if ($req['status'] === 'cancelled') $statusClass = 'status-cancelled';

                        $paymentOption = $req['payment_option'] ?? 'reservation';
                        if ($paymentOption === 'full_with_advance') {
                            $paymentLabel = 'Full Payment with Advance Deposit (2x rent)';
                        } elseif ($paymentOption === 'half') {
                            $paymentLabel = 'Half Payment (50%)'; // Legacy support
                        } else {
                            $paymentLabel = 'Reservation Only (50% of rent)';
                        }

                        // Calculate amount_due if not set
                        $amountDue = $req['amount_due'];
                        if (!$amountDue || $amountDue == 0) {
                            $propertyPrice = (float)($req['property_price'] ?? 0);
                            if ($paymentOption === 'full_with_advance') {
                                $amountDue = $propertyPrice * 2; // 2x rent
                            } elseif ($paymentOption === 'half') {
                                $amountDue = $propertyPrice / 2; // Legacy support
                            } else {
                                $amountDue = $propertyPrice * 0.5; // 50% reservation fee
                            }
                        }

                        $tenantName = trim($req['tenant_first_name'] . ' ' . $req['tenant_last_name']);
                        ?>
                        <div class="request-card" id="request-<?= $req['id'] ?>">
                            <div class="row">
                                <div class="col-md-8">
                                    <h5 class="mb-2"><?= htmlspecialchars($req['property_title']) ?></h5>
                                    <p class="text-muted mb-2">
                                        <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($req['property_address']) ?>
                                    </p>
                                    <div class="mb-2">
                                        <strong>Tenant:</strong> <?= htmlspecialchars($tenantName) ?><br>
                                        <small class="text-muted">
                                            <i class="bi bi-envelope"></i> <?= htmlspecialchars($req['tenant_email']) ?>
                                        </small>
                                    </div>
                                    <div class="mb-2">
                                        <strong>Payment Method:</strong> <?= htmlspecialchars(ucfirst($req['payment_method'])) ?><br>
                                        <strong>Payment Option:</strong> <?= $paymentLabel ?><br>
                                        <strong>Amount Due:</strong> <span class="text-success fs-5">₱<?= number_format($amountDue, 2) ?></span>
                                    </div>
                                    <small class="text-muted">
                                        <i class="bi bi-clock"></i> Requested on <?= date('M d, Y g:i A', strtotime($req['requested_at'])) ?>
                                    </small>
                                </div>
                                <div class="col-md-4 text-md-end">
                                    <div class="mb-3">
                                        <span class="status-badge <?= $statusClass ?>">
                                            <?= strtoupper($req['status']) ?>
                                        </span>
                                    </div>

                                    <?php if ($req['status'] === 'pending'): ?>
                                        <div class="d-flex flex-column gap-2">
                                            <form method="POST" action="process_rental_request.php" style="display: inline;">
                                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="btn btn-success btn-sm w-100" onclick="return confirm('Accept this rental request?')">
                                                    <i class="bi bi-check-circle"></i> Accept
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-danger btn-sm w-100" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $req['id'] ?>">
                                                <i class="bi bi-x-circle"></i> Reject
                                            </button>
                                        </div>
                                    <?php elseif ($req['status'] === 'approved'): ?>
                                        <div class="alert alert-success mb-0 py-2">
                                            <i class="bi bi-check-circle-fill"></i> Approved
                                        </div>
                                    <?php elseif ($req['status'] === 'rejected'): ?>
                                        <div class="alert alert-danger mb-0 py-2">
                                            <i class="bi bi-x-circle-fill"></i> Rejected
                                            <?php if (!empty($req['rejection_reason'])): ?>
                                                <hr class="my-1">
                                                <small><strong>Reason:</strong> <?= htmlspecialchars($req['rejection_reason']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-secondary mb-0 py-2">
                                            <i class="bi bi-slash-circle-fill"></i> Cancelled by Tenant
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($req['receipt_path'])): ?>
                                        <div class="mt-2">
                                            <a href="<?= htmlspecialchars($req['receipt_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary w-100">
                                                <i class="bi bi-file-earmark-image"></i> View Receipt
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Reject Modal for each request -->
                        <div class="modal fade" id="rejectModal<?= $req['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="POST" action="process_rental_request.php">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Reject Rental Request</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <div class="mb-3">
                                                <label class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                                                <textarea name="rejection_reason" class="form-control" rows="3" required
                                                    placeholder="Please explain why you are rejecting this request..."></textarea>
                                                <small class="text-muted">This will be sent to the tenant via email.</small>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger">Reject Request</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="darkmode.js"></script>
</body>
</html>
<?php $conn->close(); ?>
