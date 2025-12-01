<?php
session_start();
require 'mysql_connect.php';

// Only unit owners can access
if (!isset($_SESSION['owner_id']) || ($_SESSION['role'] ?? '') !== 'unit_owner') {
    header("Location: LoginModule.php");
    exit();
}

$owner_id = (int)$_SESSION['owner_id'];
$success = '';
$error = '';

// Fetch current payment settings
$stmt = $conn->prepare("
    SELECT gcash_name, gcash_number, gcash_qr_path,
           paymaya_name, paymaya_number, paymaya_qr_path,
           bank_name, bank_account_name, bank_account_number,
           first_name, last_name
    FROM tbadmin WHERE id = ? LIMIT 1
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$result = $stmt->get_result();
$owner = $result->fetch_assoc();
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gcash_name = trim($_POST['gcash_name'] ?? '');
    $gcash_number = trim($_POST['gcash_number'] ?? '');
    $paymaya_name = trim($_POST['paymaya_name'] ?? '');
    $paymaya_number = trim($_POST['paymaya_number'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $bank_account_name = trim($_POST['bank_account_name'] ?? '');
    $bank_account_number = trim($_POST['bank_account_number'] ?? '');

    $gcash_qr_path = $owner['gcash_qr_path'];
    $paymaya_qr_path = $owner['paymaya_qr_path'];

    // Handle GCash QR upload
    if (!empty($_FILES['gcash_qr']['tmp_name'])) {
        $file = $_FILES['gcash_qr'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $mime = mime_content_type($file['tmp_name']);

        if (in_array($mime, $allowed) && $file['size'] <= 5 * 1024 * 1024) {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = "gcash_qr_" . $owner_id . "_" . time() . "." . $ext;
            $upload_dir = __DIR__ . "/uploads/qr_codes";
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0775, true);

            if (move_uploaded_file($file['tmp_name'], $upload_dir . "/" . $filename)) {
                // Delete old QR if exists
                if ($gcash_qr_path && file_exists(__DIR__ . "/" . $gcash_qr_path)) {
                    unlink(__DIR__ . "/" . $gcash_qr_path);
                }
                $gcash_qr_path = "uploads/qr_codes/" . $filename;
            }
        } else {
            $error = "Invalid GCash QR code image (max 5MB, JPG/PNG only)";
        }
    }

    // Handle PayMaya QR upload
    if (!empty($_FILES['paymaya_qr']['tmp_name'])) {
        $file = $_FILES['paymaya_qr'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $mime = mime_content_type($file['tmp_name']);

        if (in_array($mime, $allowed) && $file['size'] <= 5 * 1024 * 1024) {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = "paymaya_qr_" . $owner_id . "_" . time() . "." . $ext;
            $upload_dir = __DIR__ . "/uploads/qr_codes";
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0775, true);

            if (move_uploaded_file($file['tmp_name'], $upload_dir . "/" . $filename)) {
                // Delete old QR if exists
                if ($paymaya_qr_path && file_exists(__DIR__ . "/" . $paymaya_qr_path)) {
                    unlink(__DIR__ . "/" . $paymaya_qr_path);
                }
                $paymaya_qr_path = "uploads/qr_codes/" . $filename;
            }
        } else {
            $error = "Invalid PayMaya QR code image (max 5MB, JPG/PNG only)";
        }
    }

    if (!$error) {
        // Update database
        $stmt = $conn->prepare("
            UPDATE tbadmin SET
                gcash_name = ?, gcash_number = ?, gcash_qr_path = ?,
                paymaya_name = ?, paymaya_number = ?, paymaya_qr_path = ?,
                bank_name = ?, bank_account_name = ?, bank_account_number = ?
            WHERE id = ?
        ");
        $stmt->bind_param("sssssssssi",
            $gcash_name, $gcash_number, $gcash_qr_path,
            $paymaya_name, $paymaya_number, $paymaya_qr_path,
            $bank_name, $bank_account_name, $bank_account_number,
            $owner_id
        );

        if ($stmt->execute()) {
            $success = "Payment settings updated successfully!";
            // Refresh data
            $owner['gcash_name'] = $gcash_name;
            $owner['gcash_number'] = $gcash_number;
            $owner['gcash_qr_path'] = $gcash_qr_path;
            $owner['paymaya_name'] = $paymaya_name;
            $owner['paymaya_number'] = $paymaya_number;
            $owner['paymaya_qr_path'] = $paymaya_qr_path;
            $owner['bank_name'] = $bank_name;
            $owner['bank_account_name'] = $bank_account_name;
            $owner['bank_account_number'] = $bank_account_number;
        } else {
            $error = "Failed to update payment settings.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Setup - HanapBahay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f7f7fb; }
        .topbar { background: #8B4513; color: #fff; }
        .logo { height:42px; }
        .qr-preview { max-width: 200px; border: 2px solid #ddd; border-radius: 8px; padding: 10px; }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="topbar py-2">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <img src="Assets/Logo1.png" class="logo" alt="HanapBahay" style="height:42px;">
                <strong>Payment Setup</strong>
            </div>
            <div class="d-flex gap-2">
                <a href="DashboardUO.php" class="btn btn-sm btn-outline-light">Back to Dashboard</a>
                <a href="logout.php" class="btn btn-sm btn-outline-light">Logout</a>
            </div>
        </div>
    </nav>

    <main class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h4 class="mb-0"><i class="bi bi-credit-card"></i> Payment Method Setup</h4>
                        <p class="text-muted small mb-0">Configure your payment methods for tenants to pay rental fees</p>
                    </div>
                    <div class="card-body">
                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data">
                            <!-- GCash Section -->
                            <div class="mb-4 pb-3 border-bottom">
                                <h5 class="text-primary"><i class="bi bi-wallet2"></i> GCash</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Account Name</label>
                                        <input type="text" class="form-control" name="gcash_name"
                                               value="<?= htmlspecialchars($owner['gcash_name'] ?? '') ?>"
                                               placeholder="Juan Dela Cruz">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Mobile Number</label>
                                        <input type="text" class="form-control" name="gcash_number"
                                               value="<?= htmlspecialchars($owner['gcash_number'] ?? '') ?>"
                                               placeholder="09171234567">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">QR Code Image</label>
                                        <input type="file" class="form-control" name="gcash_qr" accept="image/*" id="gcash_qr_input">
                                        <small class="text-muted">Upload your GCash QR code (JPG/PNG, max 5MB)</small>
                                        <?php if (!empty($owner['gcash_qr_path'])): ?>
                                            <div class="mt-2">
                                                <img src="<?= htmlspecialchars($owner['gcash_qr_path']) ?>"
                                                     alt="GCash QR" class="qr-preview">
                                                <p class="small text-success mt-1"><i class="bi bi-check"></i> QR code uploaded</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6" id="gcash_preview"></div>
                                </div>
                            </div>

                            <!-- PayMaya Section -->
                            <div class="mb-4 pb-3 border-bottom">
                                <h5 class="text-success"><i class="bi bi-wallet"></i> PayMaya</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Account Name</label>
                                        <input type="text" class="form-control" name="paymaya_name"
                                               value="<?= htmlspecialchars($owner['paymaya_name'] ?? '') ?>"
                                               placeholder="Juan Dela Cruz">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Mobile Number</label>
                                        <input type="text" class="form-control" name="paymaya_number"
                                               value="<?= htmlspecialchars($owner['paymaya_number'] ?? '') ?>"
                                               placeholder="09171234567">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">QR Code Image</label>
                                        <input type="file" class="form-control" name="paymaya_qr" accept="image/*" id="paymaya_qr_input">
                                        <small class="text-muted">Upload your PayMaya QR code (JPG/PNG, max 5MB)</small>
                                        <?php if (!empty($owner['paymaya_qr_path'])): ?>
                                            <div class="mt-2">
                                                <img src="<?= htmlspecialchars($owner['paymaya_qr_path']) ?>"
                                                     alt="PayMaya QR" class="qr-preview">
                                                <p class="small text-success mt-1"><i class="bi bi-check"></i> QR code uploaded</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6" id="paymaya_preview"></div>
                                </div>
                            </div>

                            <!-- Bank Transfer Section -->
                            <div class="mb-4">
                                <h5 class="text-info"><i class="bi bi-bank"></i> Bank Transfer</h5>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Bank Name</label>
                                        <input type="text" class="form-control" name="bank_name"
                                               value="<?= htmlspecialchars($owner['bank_name'] ?? '') ?>"
                                               placeholder="BPI, BDO, Metrobank, etc.">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Account Name</label>
                                        <input type="text" class="form-control" name="bank_account_name"
                                               value="<?= htmlspecialchars($owner['bank_account_name'] ?? '') ?>"
                                               placeholder="Juan Dela Cruz">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Account Number</label>
                                        <input type="text" class="form-control" name="bank_account_number"
                                               value="<?= htmlspecialchars($owner['bank_account_number'] ?? '') ?>"
                                               placeholder="1234567890">
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Save Payment Settings
                                </button>
                                <a href="DashboardUO.php" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Preview QR code uploads
        document.getElementById('gcash_qr_input')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('gcash_preview').innerHTML =
                        `<img src="${e.target.result}" class="qr-preview" alt="GCash QR Preview">`;
                };
                reader.readAsDataURL(file);
            }
        });

        document.getElementById('paymaya_qr_input')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('paymaya_preview').innerHTML =
                        `<img src="${e.target.result}" class="qr-preview" alt="PayMaya QR Preview">`;
                };
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>
