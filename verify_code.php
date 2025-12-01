<?php
// verify_code.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require 'mysql_connect.php';
require 'includes/auth_helpers.php';

if (empty($_SESSION['pending_user_id'])) {
  // No pending login – go back to login
  header("Location: LoginModule.php");
  exit;
}

$user_id = (int)$_SESSION['pending_user_id'];
$role    = $_SESSION['pending_role'] ?? '';
$email   = $_SESSION['pending_email'] ?? '';
$notice_message = '';
$notice_type = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['resend_code'])) {
    $stmt = $conn->prepare("
      SELECT id, first_name, last_name, email
        FROM tbadmin
       WHERE id = ?
       LIMIT 1
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res  = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    if ($user) {
      $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
      $sent = hb_send_login_code($conn, (int)$user['id'], $user['email'], $fullName);
      if ($sent) {
        $notice_message = 'A fresh verification code was sent. Please check your inbox (and spam folder).';
        $notice_type = 'success';
      } else {
        $notice_message = 'We could not send a new code right now. Please try again in a moment.';
        $notice_type = 'danger';
      }
    } else {
      $_SESSION['login_error'] = 'Session expired. Please log in again.';
      header("Location: LoginModule.php");
      exit;
    }

    // Skip regular code verification handling for resend submissions.
  } else {
  $code = trim($_POST['code'] ?? '');

  // Fetch user + current code/expiry
  $stmt = $conn->prepare("
    SELECT id, first_name, last_name, role, verification_code, code_expiry
    FROM tbadmin
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $res  = $stmt->get_result();
  $user = $res->fetch_assoc();
  $stmt->close();

  if (!$user) {
    $_SESSION['login_error'] = 'Session expired. Please log in again.';
    header("Location: LoginModule.php");
    exit;
  }

  // Validate code + expiry
  $now = date('Y-m-d H:i:s');
  if (
    !empty($user['verification_code']) &&
    !empty($user['code_expiry']) &&
    $user['verification_code'] === $code &&
    $user['code_expiry'] >= $now
  ) {
    // Clear code + finalize login
    $upd = $conn->prepare("
      UPDATE tbadmin
      SET verification_code=NULL, code_expiry=NULL
      WHERE id = ?
    ");
    $upd->bind_param("i", $user_id);
    $upd->execute();
    $upd->close();

    // Promote pending_* to full session
    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['first_name'] = $user['first_name'] ?? '';
    $_SESSION['last_name']  = $user['last_name'] ?? '';
    $_SESSION['role']       = $user['role'] ?? 'tenant';

    if ($_SESSION['role'] === 'unit_owner') {
      $_SESSION['owner_id'] = (int)$user['id'];
    } else {
      unset($_SESSION['owner_id']);
    }

    // Remove pending
    unset($_SESSION['pending_user_id'], $_SESSION['pending_role'], $_SESSION['pending_email']);

    // Redirect by role
    if ($_SESSION['role'] === 'admin') {
      header("Location: admin_listings.php");
    } elseif ($_SESSION['role'] === 'unit_owner') {
      header("Location: DashboardUO.php");
    } else {
      header("Location: DashboardT.php");
    }
    exit;
  } else {
    $error = 'Invalid or expired code.';
  }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Verify Code</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <script>
    (function() {
      try {
        const savedTheme = localStorage.getItem('hb-theme');
        if (savedTheme) {
          document.documentElement.setAttribute('data-theme', savedTheme);
        }
      } catch (err) {
        // ignore theme preference errors
      }
    })();
  </script>
  <style>
    :root {
      --verify-bg: #f7f7fb;
      --verify-card-bg: #ffffff;
      --verify-text: #1f2937;
      --verify-muted: #6b7280;
    }
    [data-theme="dark"] {
      --verify-bg: #0f172a;
      --verify-card-bg: #1f2937;
      --verify-text: #f9fafb;
      --verify-muted: #9ca3af;
    }
    body.verify-body {
      background: var(--verify-bg);
      color: var(--verify-text);
      min-height: 100vh;
    }
    .verify-card {
      background: var(--verify-card-bg);
      color: var(--verify-text);
    }
    .verify-card .text-muted {
      color: var(--verify-muted) !important;
    }
  </style>
</head>
<body class="verify-body">
<div class="container py-5">
  <div class="mx-auto verify-card p-4 rounded shadow-sm" style="max-width:420px;">
    <h4 class="mb-3">Enter Verification Code</h4>
    <p class="text-muted">We sent a 6-digit code to <strong><?= htmlspecialchars($email) ?></strong>.</p>
    <?php if (!empty($notice_message)): ?>
      <div class="alert alert-<?= htmlspecialchars($notice_type) ?>">
        <?= htmlspecialchars($notice_message) ?>
      </div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post">
      <div class="mb-3">
        <label class="form-label">Code</label>
        <input type="text" name="code" class="form-control" inputmode="numeric" maxlength="6" required>
      </div>
      <button class="btn btn-primary w-100">Verify</button>
    </form>
    <form method="post" class="mt-3">
      <input type="hidden" name="resend_code" value="1">
      <button class="btn btn-outline-secondary w-100" type="submit">Resend Code</button>
    </form>
    <div class="text-center mt-3">
      <a href="LoginModule.php">Back to login</a>
    </div>
  </div>
</div>
</body>
</html>
