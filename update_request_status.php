<?php
session_start();
require 'mysql_connect.php';

// Only unit owners can access
if (!isset($_SESSION['owner_id']) || ($_SESSION['role'] ?? '') !== 'unit_owner') {
    header("Location: LoginModule.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: view_requests.php");
    exit();
}

$owner_id = (int)$_SESSION['owner_id'];
$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$new_status = isset($_POST['status']) ? trim($_POST['status']) : '';

// Validate status
if (!in_array($new_status, ['approved', 'rejected'])) {
    $_SESSION['error'] = 'Invalid status provided.';
    header("Location: view_requests.php");
    exit();
}

// Verify that this request belongs to one of the owner's properties
$verify_stmt = $conn->prepare("
    SELECT rr.id
    FROM rental_requests rr
    JOIN tblistings l ON rr.listing_id = l.id
    WHERE rr.id = ? AND l.owner_id = ?
    LIMIT 1
");
$verify_stmt->bind_param("ii", $request_id, $owner_id);
$verify_stmt->execute();
$verify_result = $verify_stmt->get_result();

if ($verify_result->num_rows === 0) {
    $_SESSION['error'] = 'Request not found or you do not have permission to modify it.';
    header("Location: view_requests.php");
    exit();
}
$verify_stmt->close();

// Get listing_id for this request
$listing_stmt = $conn->prepare("SELECT listing_id FROM rental_requests WHERE id = ?");
$listing_stmt->bind_param("i", $request_id);
$listing_stmt->execute();
$listing_result = $listing_stmt->get_result();
$listing_row = $listing_result->fetch_assoc();
$listing_id = $listing_row['listing_id'];
$listing_stmt->close();

// Start transaction for atomic operation
$conn->begin_transaction();

try {
    // Update the request status with timestamp
    if ($new_status === 'approved') {
        $update_stmt = $conn->prepare("UPDATE rental_requests SET status = ?, approved_at = NOW() WHERE id = ?");
    } else {
        $update_stmt = $conn->prepare("UPDATE rental_requests SET status = ?, rejected_at = NOW() WHERE id = ?");
    }
    $update_stmt->bind_param("si", $new_status, $request_id);
    $update_stmt->execute();
    $update_stmt->close();

    if ($new_status === 'approved') {
        require_once 'update_listing_status.php';

        if (!applyOccupancyChange($listing_id, 1, $conn)) {
            throw new Exception('Failed to update listing availability.');
        }

        $_SESSION['success'] = 'Request approved successfully. Property availability has been updated.';
    } else {
        $_SESSION['success'] = 'Request rejected successfully.';
    }

    // Commit transaction
    $conn->commit();

} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    $_SESSION['error'] = 'Failed to update request status: ' . $e->getMessage();
}

header("Location: view_requests.php");
exit();
