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

// Fetch current profile
$stmt = $conn->prepare("SELECT id, first_name, last_name, email, password
                        FROM tbadmin WHERE id=? LIMIT 1");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile) {
    die("Owner not found in tbadmin");
}

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
    
    $form_type = $_POST['form_type'] ?? '';
    
    if ($form_type === 'profile') {
        // Handle profile update
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($first_name)) $errors[] = "First name required.";
        if (empty($last_name)) $errors[] = "Last name required.";
        if (empty($email)) $errors[] = "Email required.";
        
        if (!empty($new_password)) {
            if ($new_password !== $confirm_password) {
                $errors[] = "Passwords don't match.";
            } else {
                // Use same password validation as registration
                if (strlen($new_password) < 8) $errors[] = "Password must be at least 8 characters.";
                if (!preg_match('/[A-Z]/', $new_password)) $errors[] = "Include at least 1 uppercase letter.";
                if (!preg_match('/[a-z]/', $new_password)) $errors[] = "Include at least 1 lowercase letter.";
                if (!preg_match('/\\d/', $new_password)) $errors[] = "Include at least 1 number.";
                if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $new_password)) $errors[] = "Include at least 1 special character.";
            }
        }
        
        if (empty($errors)) {
            $sql = "UPDATE tbadmin SET first_name=?, last_name=?, email=?";
            $params = [$first_name, $last_name, $email];
            $types = "sss";
            
            if (!empty($new_password)) {
                $sql .= ", password=?";
                $params[] = password_hash($new_password, PASSWORD_DEFAULT);
                $types .= "s";
            }
            
            $sql .= " WHERE id=?";
            $params[] = $owner_id;
            $types .= "i";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $ok = $stmt->execute();
            $stmt->close();
            
            if ($ok) {
                $success = "Profile updated successfully.";
                csrf_regenerate(); // Regenerate CSRF token after successful update
                // Refresh profile data
                $stmt = $conn->prepare("SELECT id, first_name, last_name, email, password
                                        FROM tbadmin WHERE id=? LIMIT 1");
                $stmt->bind_param("i", $owner_id);
                $stmt->execute();
                $profile = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            } else {
                $errors[] = "Failed to update profile.";
            }
        }
    } elseif ($form_type === 'payment') {
        // Handle payment settings update
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
                $success = "Payment settings updated successfully.";
                csrf_regenerate(); // Regenerate CSRF token after successful update
                // Refresh settings data
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
                $errors[] = "Failed to update payment settings.";
            }
            $stmt->close();
        }
    }
}

function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - HanapBahay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="darkmode.css">
    <style>
        body { background: #f7f7fb; }
        .settings-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 2rem;
        }
        .nav-tabs .nav-link {
            color: #8B4513;
            border: none;
            border-bottom: 2px solid transparent;
        }
        .nav-tabs .nav-link.active {
            color: #8B4513;
            border-bottom-color: #8B4513;
            background: none;
        }
        .btn-primary {
            background-color: #8B4513;
            border-color: #8B4513;
        }
        .btn-primary:hover {
            background-color: #6B3410;
            border-color: #6B3410;
        }
        .password-hint {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 10px;
            font-size: 0.9rem;
        }
        .password-hint-list {
            margin: 5px 0 0 0;
            padding-left: 20px;
        }
        .password-hint-list li {
            margin: 2px 0;
        }
        .password-hint-list li.missing {
            color: #dc3545;
        }
        .password-hint-list li.met {
            color: #28a745;
        }
        .form-control-lg {
            padding: 0.75rem 1rem;
            font-size: 1.1rem;
        }
        .card-title {
            font-size: 0.95rem;
        }
        .btn-lg {
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
        }
        .settings-container {
            max-width: 100%;
        }
        @media (min-width: 1200px) {
            .settings-container {
                max-width: 800px;
                margin: 0 auto;
            }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <?= getNavigationForRole('account_settings.php') ?>

    <main class="container-fluid py-4">
        <h3 class="mb-4"><i class="bi bi-gear"></i> Account Settings</h3>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= h($success) ?></div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-lg-8 col-xl-6">
                <div class="settings-container">
                    <!-- Navigation Tabs -->
                    <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab">
                                <i class="bi bi-person"></i> Profile Settings
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="payment-tab" data-bs-toggle="tab" data-bs-target="#payment" type="button" role="tab">
                                <i class="bi bi-credit-card"></i> Payment Settings
                            </button>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content" id="settingsTabsContent">
                        <!-- Profile Settings Tab -->
                        <div class="tab-pane fade show active" id="profile" role="tabpanel">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="form_type" value="profile">
                                <?= csrf_field() ?>
                                
                                <h5 class="text-muted mb-4">Account Information</h5>
                                
                                <!-- Name Fields -->
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">First Name</label>
                                        <input type="text" name="first_name" class="form-control form-control-lg" value="<?= h($profile['first_name']) ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Last Name</label>
                                        <input type="text" name="last_name" class="form-control form-control-lg" value="<?= h($profile['last_name']) ?>" required>
                                    </div>
                                </div>

                                <!-- Email Field -->
                                <div class="mb-4">
                                    <label class="form-label fw-semibold">Email Address</label>
                                    <input type="email" name="email" class="form-control form-control-lg" value="<?= h($profile['email']) ?>" required>
                                </div>

                                <!-- Password Fields -->
                                <div class="card border-0 bg-light mb-4">
                                    <div class="card-body">
                                        <h6 class="card-title text-muted mb-3">
                                            <i class="bi bi-shield-lock"></i> Change Password
                                        </h6>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">New Password</label>
                                                <input type="password" name="new_password" id="newPassword" class="form-control" placeholder="Leave blank to keep current">
                                                <div class="password-hint mt-2" id="passwordHint" style="display: none;">
                                                    <strong class="password-hint-title">Password must include:</strong>
                                                    <ul id="passwordHintList" class="password-hint-list">
                                                        <li data-rule="length" class="missing">At least 8 characters</li>
                                                        <li data-rule="upper" class="missing">An uppercase letter</li>
                                                        <li data-rule="lower" class="missing">A lowercase letter</li>
                                                        <li data-rule="number" class="missing">A number</li>
                                                        <li data-rule="special" class="missing">A special character (!@#$%)</li>
                                                    </ul>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">Confirm Password</label>
                                                <input type="password" name="confirm_password" id="confirmPassword" class="form-control" placeholder="Re-enter new password">
                                                <div id="confirmFeedback" class="text-danger small mt-1" style="display: none;">Passwords do not match.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="d-flex gap-3 justify-content-center">
                                    <button type="submit" class="btn btn-primary btn-lg px-4">
                                        <i class="bi bi-check-circle"></i> Save Changes
                                    </button>
                                    <a href="DashboardUO.php" class="btn btn-outline-secondary btn-lg px-4">
                                        <i class="bi bi-x-circle"></i> Cancel
                                    </a>
                                </div>
                            </form>
                        </div>

                <!-- Payment Settings Tab -->
                <div class="tab-pane fade" id="payment" role="tabpanel">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="form_type" value="payment">
                        <?= csrf_field() ?>
                        
                        <h5 class="text-muted mb-4">Payment Methods</h5>
                        
                        <!-- GCash Section -->
                        <div class="card mb-4">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="bi bi-phone"></i> GCash</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">GCash Name</label>
                                        <input type="text" name="gcash_name" class="form-control" value="<?= h($settings['gcash_name'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">GCash Number</label>
                                        <input type="text" name="gcash_number" class="form-control" value="<?= h($settings['gcash_number'] ?? '') ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">GCash QR Code</label>
                                        <input type="file" name="gcash_qr" class="form-control" accept="image/*">
                                        <?php if (!empty($settings['gcash_qr_path'])): ?>
                                            <div class="mt-2">
                                                <small class="text-muted">Current QR: </small>
                                                <a href="<?= h($settings['gcash_qr_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">View Current QR</a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- PayMaya Section -->
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="bi bi-credit-card"></i> PayMaya</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">PayMaya Name</label>
                                        <input type="text" name="paymaya_name" class="form-control" value="<?= h($settings['paymaya_name'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">PayMaya Number</label>
                                        <input type="text" name="paymaya_number" class="form-control" value="<?= h($settings['paymaya_number'] ?? '') ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">PayMaya QR Code</label>
                                        <input type="file" name="paymaya_qr" class="form-control" accept="image/*">
                                        <?php if (!empty($settings['paymaya_qr_path'])): ?>
                                            <div class="mt-2">
                                                <small class="text-muted">Current QR: </small>
                                                <a href="<?= h($settings['paymaya_qr_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">View Current QR</a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bank Section -->
                        <div class="card mb-4">
                            <div class="card-header bg-dark text-white">
                                <h6 class="mb-0"><i class="bi bi-bank"></i> Bank Account</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Bank Name</label>
                                        <input type="text" name="bank_name" class="form-control" value="<?= h($settings['bank_name'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Account Name</label>
                                        <input type="text" name="bank_account_name" class="form-control" value="<?= h($settings['bank_account_name'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Account Number</label>
                                        <input type="text" name="bank_account_number" class="form-control" value="<?= h($settings['bank_account_number'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-3 justify-content-center mt-4">
                            <button type="submit" class="btn btn-primary btn-lg px-4">
                                <i class="bi bi-check-circle"></i> Save Payment Settings
                            </button>
                            <a href="DashboardUO.php" class="btn btn-outline-secondary btn-lg px-4">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password validation (same as registration form)
        const newPassword = document.getElementById('newPassword');
        const confirmPassword = document.getElementById('confirmPassword');
        const passwordHint = document.getElementById('passwordHint');
        const passwordHintList = document.getElementById('passwordHintList');
        const confirmFeedback = document.getElementById('confirmFeedback');
        const passwordRulesList = passwordHintList ? passwordHintList.querySelectorAll('li') : [];

        const passwordPatterns = {
            length: /.{8,}/,
            upper: /[A-Z]/,
            lower: /[a-z]/,
            number: /\d/,
            special: /[!@#$%^&*(),.?":{}|<>]/
        };

        function showPasswordHint() {
            if (passwordHint) {
                passwordHint.style.display = 'block';
            }
        }

        function hidePasswordHint() {
            if (passwordHint) {
                passwordHint.style.display = 'none';
            }
        }

        function evaluatePassword(value) {
            const results = {
                length: passwordPatterns.length.test(value),
                upper: passwordPatterns.upper.test(value),
                lower: passwordPatterns.lower.test(value),
                number: passwordPatterns.number.test(value),
                special: passwordPatterns.special.test(value)
            };
            results.allMet = Object.values(results).every(Boolean);
            return results;
        }

        function updatePasswordHint() {
            if (!newPassword || !passwordRulesList.length) {
                return { allMet: false };
            }
            const value = newPassword.value || '';
            const results = evaluatePassword(value);
            passwordRulesList.forEach(item => {
                const rule = item.getAttribute('data-rule');
                if (results[rule]) {
                    item.classList.add('met');
                    item.classList.remove('missing');
                } else {
                    item.classList.add('missing');
                    item.classList.remove('met');
                }
            });
            newPassword.classList.toggle('is-valid', results.allMet);
            newPassword.classList.toggle('is-invalid', !results.allMet && value.length > 0);
            return results;
        }

        function validateConfirmPassword() {
            if (!newPassword || !confirmPassword) {
                return false;
            }
            const matches = newPassword.value === confirmPassword.value && confirmPassword.value.length > 0;
            if (confirmFeedback) {
                if (matches) {
                    confirmFeedback.style.display = 'none';
                    confirmPassword.classList.add('is-valid');
                    confirmPassword.classList.remove('is-invalid');
                } else if (confirmPassword.value.length > 0) {
                    confirmFeedback.style.display = 'block';
                    confirmPassword.classList.add('is-invalid');
                    confirmPassword.classList.remove('is-valid');
                } else {
                    confirmFeedback.style.display = 'none';
                    confirmPassword.classList.remove('is-valid', 'is-invalid');
                }
            }
            return matches;
        }

        if (newPassword) {
            newPassword.addEventListener('focus', () => {
                if (newPassword.value.length > 0) {
                    showPasswordHint();
                    updatePasswordHint();
                }
            });
            newPassword.addEventListener('blur', () => {
                if (newPassword.value.length === 0) {
                    hidePasswordHint();
                }
            });
            newPassword.addEventListener('input', () => {
                if (newPassword.value.length > 0) {
                    showPasswordHint();
                }
                updatePasswordHint();
                validateConfirmPassword();
            });
        }

        if (confirmPassword) {
            confirmPassword.addEventListener('input', validateConfirmPassword);
        }

        // Form validation
        document.querySelector('form').addEventListener('submit', (event) => {
            if (newPassword.value.length > 0) {
                const passwordResults = updatePasswordHint();
                const passwordsMatch = validateConfirmPassword();
                if (!passwordResults.allMet) {
                    event.preventDefault();
                    newPassword.focus();
                    showPasswordHint();
                    return;
                }
                if (!passwordsMatch) {
                    event.preventDefault();
                    confirmPassword.focus();
                }
            }
        });
    </script>
    <script src="darkmode.js"></script>
</body>
</html>
<?php $conn->close(); ?>
