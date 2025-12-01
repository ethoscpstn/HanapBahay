<?php
session_start();
require 'mysql_connect.php';
require_once 'includes/navigation.php';

// Debug during setup (remove later)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (empty($_SESSION['owner_id'])) {
  header("Location: LoginModule.php");
  exit;
}

$owner_id = (int)$_SESSION['owner_id'];
$errors = [];
$ok = false;

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $first_name = trim($_POST['first_name'] ?? $profile['first_name']);
  $last_name  = trim($_POST['last_name'] ?? $profile['last_name']);
  $email      = trim($_POST['email'] ?? $profile['email']);

  // Validate email
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Invalid email address.";
  }

  // Handle password change
  $new_password = trim($_POST['new_password'] ?? '');
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
      $stmt = $conn->prepare("UPDATE tbadmin
        SET first_name=?, last_name=?, email=?, password=?
        WHERE id=?");
      $stmt->bind_param("ssssi", $first_name, $last_name, $email, $hashed_pw, $owner_id);
    } else {
      $stmt = $conn->prepare("UPDATE tbadmin
        SET first_name=?, last_name=?, email=?
        WHERE id=?");
      $stmt->bind_param("sssi", $first_name, $last_name, $email, $owner_id);
    }

    if ($stmt->execute()) {
      $ok = true;
      $profile['first_name'] = $first_name;
      $profile['last_name']  = $last_name;
      $profile['email']      = $email;
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
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Edit Profile</title>
  <link rel="icon" type="image/png" sizes="16x16" href="Assets/HanapBahayTablogo.png?v=2">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="edit_profile.css?v=9" />
  <link rel="stylesheet" href="darkmode.css">
</head>
<body class="profile-page">
  <!-- Top Navigation -->
  <?= getNavigationForRole('edit_profile.php') ?>

<div class="profile-container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Edit Profile</h3>
    <a href="DashboardUO.php" class="btn-back">Back to Dashboard</a>
  </div>

  <?php if ($ok): ?><div class="alert alert-success">Profile updated.</div><?php endif; ?>
  <?php if ($errors): ?><div class="alert alert-danger"><ul><?php foreach($errors as $e){ echo "<li>".htmlspecialchars($e)."</li>"; } ?></ul></div><?php endif; ?>

  <form method="POST" enctype="multipart/form-data" class="bg-white p-4 shadow-sm rounded">
    <h6 class="text-muted">Account Information</h5>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">First Name</label>
        <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($profile['first_name']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Last Name</label>
        <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($profile['last_name']) ?>">
      </div>
    </div>

    <div class="mt-3">
      <label class="form-label">Email</label>
      <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($profile['email']) ?>" required>
    </div>

    <div class="row g-3 mt-3">
      <div class="col-md-6">
        <label class="form-label">New Password</label>
        <input type="password" name="new_password" class="form-control" placeholder="Leave blank to keep current">
      </div>
      <div class="col-md-6">
        <label class="form-label">Confirm Password</label>
        <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter new password">
      </div>
    </div>

    <div class="mt-4 d-flex gap-2">
      <button type="submit" class="btn-save">Save Changes</button>
      <a href="DashboardUO.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<script src="darkmode.js"></script>
</body>
</html>
