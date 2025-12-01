<?php
require 'mysql_connect.php';

// CHANGE THESE:
$email = 'admin@hanapbahay.com';
$plain = 'Admin@123';
$first = 'System';
$last  = 'Admin';

$hash = password_hash($plain, PASSWORD_DEFAULT);

// Try update first
$stmt = $conn->prepare("UPDATE tbadmin SET first_name=?, last_name=?, password=?, role='admin' WHERE email=?");
$stmt->bind_param("ssss", $first, $last, $hash, $email);
$stmt->execute();

if ($stmt->affected_rows === 0) {
  // No existing row — insert new
  $stmt->close();
  $stmt = $conn->prepare("INSERT INTO tbadmin (first_name, last_name, email, password, role, created_at) VALUES (?,?,?,?, 'admin', NOW())");
  $stmt->bind_param("ssss", $first, $last, $email, $hash);
  $stmt->execute();
}

$stmt->close();
$conn->close();

echo "Admin user ready: $email / $plain (hashed). Delete this file now.";
