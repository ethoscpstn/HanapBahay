<?php
// restore_listing.php
session_start();
require 'mysql_connect.php';

if (!isset($_SESSION['owner_id'])) {
  header('Location: LoginModule.php');
  exit();
}

$owner_id  = (int)$_SESSION['owner_id'];
$listing_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($listing_id <= 0) {
  header('Location: DashboardUO.php?err=bad_request');
  exit();
}

$stmt = $conn->prepare("
  SELECT id
  FROM tblistings
  WHERE id = ? AND owner_id = ? AND is_deleted = 0 AND is_archived = 1
  LIMIT 1
");
$stmt->bind_param("ii", $listing_id, $owner_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
  $stmt->close();
  $conn->close();
  header('Location: DashboardUO.php#archivedListings');
  exit();
}

$stmt->close();
$conn->close();

header('Location: DashboardAddUnit.php?restore=' . $listing_id);
exit();
