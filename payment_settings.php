<?php
session_start();
require 'mysql_connect.php';
require 'includes/csrf.php';
require_once 'includes/navigation.php';

// Require owner login
if (!isset($_SESSION['owner_id']) || $_SESSION['role'] !== 'unit_owner') {
    header("Location: LoginModule.php");
    exit();
}

$owner_id = (int)$_SESSION['owner_id'];
$errors = [];
$success = '';

// Fetch current payment settings
$stmt = $conn->prepare("
    SELECT gcash_name, gcash_number, gcash_qr_path,
           paymaya_name, paymaya_number, paymaya_qr_path,
           bank_name, bank_account_name, bank_account_number
    FROM tbadmin
    WHERE id = ?
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$result = $stmt->get_result();
$settings = $result->fetch_assoc();
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Sanitize inputs
    $gcash_name = trim($_POST['gcash_name'] ?? '');
    $gcash_number = trim($_POST['gcash_number'] ?? '');
    $paymaya_name = trim($_POST['paymaya_name'] ?? '');
    $paymaya_number = trim($_POST['paymaya_number'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $bank_account_name = trim($_POST['bank_account_name'] ?? '');
    $bank_account_number = trim($_POST['bank_account_number'] ?? '');

    // Keep existing QR paths
    $gcash_qr_path = $settings['gcash_qr_path'] ?? null;
    $paymaya_qr_path = $settings['paymaya_qr_path'] ?? null;

    // Handle GCash QR upload
    if (!empty($_FILES['gcash_qr']['name']) && $_FILES['gcash_qr']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = function_exists('mime_content_type') ? mime_content_type($_FILES['gcash_qr']['tmp_name']) : $_FILES['gcash_qr']['type'];

        if (!isset($allowed[$mime])) {
            $errors[] = "Invalid GCash QR file type. Only JPG, PNG, WebP allowed.";
        } elseif ($_FILES['gcash_qr']['size'] > 5 * 1024 * 1024) {
            $errors[] = "GCash QR file too large (max 5 MB).";
        } else {
            $dir = __DIR__ . "/uploads/payment_qr/" . date('Ymd');
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $ext = $allowed[$mime];
            $safeName = "gcash_" . $owner_id . "_" . time() . "." . $ext;
            $abs = $dir . "/" . $safeName;
            if (@move_uploaded_file($_FILES['gcash_qr']['tmp_name'], $abs)) {
                // Delete old QR if exists
                if ($gcash_qr_path && file_exists(__DIR__ . '/' . $gcash_qr_path)) {
                    @unlink(__DIR__ . '/' . $gcash_qr_path);
                }
                $gcash_qr_path = "uploads/payment_qr/" . date('Ymd') . "/" . $safeName;
            } else {
                $errors[] = "Failed to save GCash QR code.";
            }
        }
    }

    // Handle PayMaya QR upload
    if (!empty($_FILES['paymaya_qr']['name']) && $_FILES['paymaya_qr']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = function_exists('mime_content_type') ? mime_content_type($_FILES['paymaya_qr']['tmp_name']) : $_FILES['paymaya_qr']['type'];

        if (!isset($allowed[$mime])) {
            $errors[] = "Invalid PayMaya QR file type. Only JPG, PNG, WebP allowed.";
        } elseif ($_FILES['paymaya_qr']['size'] > 5 * 1024 * 1024) {
            $errors[] = "PayMaya QR file too large (max 5 MB).";
        } else {
            $dir = __DIR__ . "/uploads/payment_qr/" . date('Ymd');
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $ext = $allowed[$mime];
            $safeName = "paymaya_" . $owner_id . "_" . time() . "." . $ext;
            $abs = $dir . "/" . $safeName;
            if (@move_uploaded_file($_FILES['paymaya_qr']['tmp_name'], $abs)) {
                // Delete old QR if exists
                if ($paymaya_qr_path && file_exists(__DIR__ . '/' . $paymaya_qr_path)) {
                    @unlink(__DIR__ . '/' . $paymaya_qr_path);
                }
                $paymaya_qr_path = "uploads/payment_qr/" . date('Ymd') . "/" . $safeName;
            } else {
                $errors[] = "Failed to save PayMaya QR code.";
            }
        }
    }

    if (empty($errors)) {
        $update_stmt = $conn->prepare("
            UPDATE tbadmin
            SET gcash_name = ?, gcash_number = ?, gcash_qr_path = ?,
                paymaya_name = ?, paymaya_number = ?, paymaya_qr_path = ?,
                bank_name = ?, bank_account_name = ?, bank_account_number = ?
            WHERE id = ?
        ");
        $update_stmt->bind_param(
            "sssssssssi",
            $gcash_name, $gcash_number, $gcash_qr_path,
            $paymaya_name, $paymaya_number, $paymaya_qr_path,
            $bank_name, $bank_account_name, $bank_account_number,
            $owner_id
        );

        if ($update_stmt->execute()) {
            $success = "Payment settings updated successfully!";
            // Refresh settings
            $stmt = $conn->prepare("
                SELECT gcash_name, gcash_number, gcash_qr_path,
                       paymaya_name, paymaya_number, paymaya_qr_path,
                       bank_name, bank_account_name, bank_account_number
                FROM tbadmin
                WHERE id = ?
            ");
            $stmt->bind_param("i", $owner_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $settings = $result->fetch_assoc();
            $stmt->close();
        } else {
            $errors[] = "Database error: " . $update_stmt->error;
        }
        $update_stmt->close();
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Settings - HanapBahay</title>
    <link rel="icon" type="image/png" sizes="16x16" href="Assets/HanapBahayTablogo.png?v=2">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="darkmode.css">
    <style>
        body { background: #f7f7f7; }
        .qr-preview { max-width: 200px; max-height: 200px; border-radius: 8px; }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <?= getNavigationForRole('payment_settings.php') ?>

    <main class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-credit-card"></i> Payment Settings</h2>
            <a href="DashboardUO.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= h($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <strong>Errors:</strong>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="bg-white p-4 rounded shadow-sm">
            <?php echo csrf_field(); ?>

            <!-- GCash Section -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-phone"></i> GCash</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Account Name</label>
                            <input type="text" name="gcash_name" class="form-control" value="<?= h($settings['gcash_name'] ?? '') ?>" placeholder="Optional">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mobile Number</label>
                            <input type="text" name="gcash_number" class="form-control" value="<?= h($settings['gcash_number'] ?? '') ?>" placeholder="09XXXXXXXXX">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">QR Code (Optional)</label>
                        <?php if (!empty($settings['gcash_qr_path'])): ?>
                            <div class="mb-2">
                                <img src="<?= h($settings['gcash_qr_path']) ?>" alt="GCash QR" class="qr-preview">
                            </div>
                        <?php endif; ?>
                        <input type="file" name="gcash_qr" class="form-control" accept="image/*">
                        <small class="text-muted">Upload for easier payment. JPG, PNG, WebP (max 5 MB)</small>
                    </div>
                </div>
            </div>

            <!-- PayMaya Section -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-wallet2"></i> PayMaya</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Account Name</label>
                            <input type="text" name="paymaya_name" class="form-control" value="<?= h($settings['paymaya_name'] ?? '') ?>" placeholder="Optional">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mobile Number</label>
                            <input type="text" name="paymaya_number" class="form-control" value="<?= h($settings['paymaya_number'] ?? '') ?>" placeholder="09XXXXXXXXX">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">QR Code (Optional)</label>
                        <?php if (!empty($settings['paymaya_qr_path'])): ?>
                            <div class="mb-2">
                                <img src="<?= h($settings['paymaya_qr_path']) ?>" alt="PayMaya QR" class="qr-preview">
                            </div>
                        <?php endif; ?>
                        <input type="file" name="paymaya_qr" class="form-control" accept="image/*">
                        <small class="text-muted">Upload for easier payment. JPG, PNG, WebP (max 5 MB)</small>
                    </div>
                </div>
            </div>

            <!-- Bank Transfer Section -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-bank"></i> Bank Transfer</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Bank Name</label>
                        <input type="text" name="bank_name" class="form-control" value="<?= h($settings['bank_name'] ?? '') ?>" placeholder="e.g., BDO, BPI, Metrobank">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Account Name</label>
                        <input type="text" name="bank_account_name" class="form-control" value="<?= h($settings['bank_account_name'] ?? '') ?>" placeholder="Account holder name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Account Number</label>
                        <input type="text" name="bank_account_number" class="form-control" value="<?= h($settings['bank_account_number'] ?? '') ?>" placeholder="Bank account number">
                    </div>
                </div>
            </div>

            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> These payment details will be shown to tenants when they apply for your properties. You can leave fields blank if you don't use that payment method.
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save"></i> Save Payment Settings
            </button>
        </form>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="darkmode.js"></script>
</body>
</html>
