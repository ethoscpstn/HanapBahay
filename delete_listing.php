<?php
session_start();
require 'mysql_connect.php';

// Require owner login
if (!isset($_SESSION['owner_id'])) {
    header("Location: LoginModule.php");
    exit();
}

// Accept both GET and POST for compatibility with dashboard links
$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$owner_id = (int)$_SESSION['owner_id'];

if ($id > 0) {
    // First verify the listing belongs to this owner
    $check = $conn->prepare("SELECT id FROM tblistings WHERE id = ? AND owner_id = ?");
    $check->bind_param("ii", $id, $owner_id);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows === 0) {
        $_SESSION['error_message'] = 'Listing not found or unauthorized';
        header("Location: DashboardUO.php");
        exit();
    }

    // Mark the listing as deleted (soft delete)
    $stmt = $conn->prepare("UPDATE tblistings SET is_deleted = 1, deleted_at = NOW() WHERE id = ? AND owner_id = ?");
    $stmt->bind_param("ii", $id, $owner_id);

    if ($stmt->execute()) {
        $_SESSION['show_success_popup'] = true;
        $_SESSION['success_message'] = 'Listing deleted successfully';
        header("Location: DashboardUO.php");
    } else {
        $_SESSION['error_message'] = 'Error deleting listing';
        header("Location: DashboardUO.php");
    }
    $stmt->close();
} else {
    $_SESSION['error_message'] = 'Invalid listing ID';
    header("Location: DashboardUO.php");
}

$conn->close();
?>
