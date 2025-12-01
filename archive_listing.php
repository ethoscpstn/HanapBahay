<?php
// archive_listing.php
session_start();
require 'mysql_connect.php';

// Set content type to JSON
header('Content-Type: application/json');

if (!isset($_SESSION['owner_id'])) {
  echo json_encode(['success' => false, 'error' => 'Not authenticated']);
  exit();
}

$owner_id  = (int)$_SESSION['owner_id'];
$listing_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($listing_id <= 0) {
  echo json_encode(['success' => false, 'error' => 'Invalid listing ID']);
  exit();
}

// Make sure the listing belongs to the logged-in owner and is not deleted
$stmt = $conn->prepare("
  UPDATE tblistings
  SET is_archived = 1,
      is_visible = 0,
      is_available = 0,
      availability_status = 'unavailable'
  WHERE id = ?
    AND owner_id = ?
    AND is_deleted = 0
    AND is_archived = 0
");
$stmt->bind_param("ii", $listing_id, $owner_id);
$stmt->execute();

if ($stmt->affected_rows > 0) {
  $stmt->close();
  $conn->close();
  echo json_encode(['success' => true, 'message' => 'Listing archived successfully']);
  exit();
}

$stmt->close();
$conn->close();
echo json_encode(['success' => false, 'error' => 'Failed to archive listing']);
exit();
