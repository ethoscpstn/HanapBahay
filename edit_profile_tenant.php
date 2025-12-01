<?php
// edit_profile_tenant.php — Tenant profile (tbadmin)
session_start();
require 'mysql_connect.php';

// show errors while wiring (remove later)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Must be logged in as tenant
if (empty($_SESSION['role']) || $_SESSION['role'] !== 'tenant' || empty($_SESSION['user_id'])) {
  header("Location: LoginModule.php");
  exit;
}

$tenant_id = (int)$_SESSION['user_id'];
$errors = [];
$ok = false;

// Fetch current profile
$stmt = $conn->prepare("SELECT id, first_name, last_name, email, password FROM tbadmin WHERE id=? LIMIT 1");
$stmt->bind_param("i", $tenant_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile) { die("Tenant not found in tbadmin"); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $first_name = trim($_POST['first_name'] ?? $profile['first_name']);
  $last_name  = trim($_POST['last_name']  ?? $profile['last_name']);
  $email      = trim($_POST['email']      ?? $profile['email']);

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Please enter a valid email address.";
  }

  // Optional password change
  $new_password     = trim($_POST['new_password'] ?? '');
  $confirm_password = trim($_POST['confirm_password'] ?? '');
  $update_password = false;
  if ($new_password !== '' || $confirm_password !== '') {
    if ($new_password !== $confirm_password) {
      $errors[] = "Password confirmation does not match.";
    } else {
      // Use same password validation as registration
      if (strlen($new_password) < 8) $errors[] = "Password must be at least 8 characters.";
      if (!preg_match('/[A-Z]/', $new_password)) $errors[] = "Include at least 1 uppercase letter.";
      if (!preg_match('/[a-z]/', $new_password)) $errors[] = "Include at least 1 lowercase letter.";
      if (!preg_match('/\\d/', $new_password)) $errors[] = "Include at least 1 number.";
      if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $new_password)) $errors[] = "Include at least 1 special character.";
      
      if (empty($errors)) {
        $update_password = true;
        $hashed_pw = password_hash($new_password, PASSWORD_DEFAULT);
      }
    }
  }

  if (!$errors) {
    if ($update_password) {
      $stmt = $conn->prepare("UPDATE tbadmin SET first_name=?, last_name=?, email=?, password=? WHERE id=?");
      $stmt->bind_param("ssssi", $first_name, $last_name, $email, $hashed_pw, $tenant_id);
    } else {
      $stmt = $conn->prepare("UPDATE tbadmin SET first_name=?, last_name=?, email=? WHERE id=?");
      $stmt->bind_param("sssi", $first_name, $last_name, $email, $tenant_id);
    }

    if ($stmt->execute()) {
      $ok = true;
      // refresh in-session display names if you use them
      $_SESSION['first_name'] = $first_name;
      $_SESSION['last_name']  = $last_name;
      $profile['first_name']  = $first_name;
      $profile['last_name']   = $last_name;
      $profile['email']       = $email;
    } else {
      $errors[] = "Update failed: " . $stmt->error;
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
  <title>Tenant Settings - HanapBahay</title>
  <link rel="icon" type="image/png" sizes="16x16" href="Assets/HanapBahayTablogo.png?v=2">
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
  <nav class="navbar navbar-expand-lg navbar-dark" style="background-color: #8B4513;">
    <div class="container">
      <a class="navbar-brand" href="DashboardT.php">
        <img src="Assets/Logo1.png" alt="HanapBahay" height="30">
      </a>
      <div class="navbar-nav ms-auto">
        <a class="nav-link" href="DashboardT.php">
          <i class="bi bi-house"></i> Dashboard
        </a>
        <a class="nav-link" href="logout.php">
          <i class="bi bi-box-arrow-right"></i> Logout
        </a>
      </div>
    </div>
  </nav>

  <main class="container-fluid py-4">
    <h3 class="mb-4"><i class="bi bi-gear"></i> Tenant Settings</h3>

    <?php if ($ok): ?>
      <div class="alert alert-success">Profile updated successfully.</div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="row justify-content-center">
      <div class="col-lg-8 col-xl-6">
        <div class="settings-container">
          <form method="POST">
            <h5 class="text-muted mb-4">Account Information</h5>
            
            <!-- Name Fields -->
            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">First Name</label>
                <input type="text" name="first_name" class="form-control form-control-lg" value="<?= htmlspecialchars($profile['first_name']) ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Last Name</label>
                <input type="text" name="last_name" class="form-control form-control-lg" value="<?= htmlspecialchars($profile['last_name']) ?>" required>
              </div>
            </div>

            <!-- Email Field -->
            <div class="mb-4">
              <label class="form-label fw-semibold">Email Address</label>
              <input type="email" name="email" class="form-control form-control-lg" value="<?= htmlspecialchars($profile['email']) ?>" required>
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
              <a href="DashboardT.php" class="btn btn-outline-secondary btn-lg px-4">
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
    // Password validation (same as account_settings.php)
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
