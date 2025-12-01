<?php
session_start();
require 'mysql_connect.php';
require 'send_request_status_notification.php';

if (!isset($_SESSION['owner_id']) || $_SESSION['role'] !== 'unit_owner') {
    header("Location: LoginModule.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: rental_requests_uo.php");
    exit();
}

$owner_id = (int)$_SESSION['owner_id'];
$request_id = (int)$_POST['request_id'];
$action = $_POST['action']; // 'approve' or 'reject'
$rejection_reason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : '';

// Fetch full request details for email notification
$stmt = $conn->prepare("
    SELECT rr.id, rr.status, rr.amount_due, rr.listing_id,
           l.title AS property_title,
           t.email AS tenant_email, t.first_name AS tenant_first_name, t.last_name AS tenant_last_name,
           o.first_name AS owner_first_name, o.last_name AS owner_last_name
    FROM rental_requests rr
    JOIN tblistings l ON l.id = rr.listing_id
    JOIN tbadmin t ON t.id = rr.tenant_id
    JOIN tbadmin o ON o.id = l.owner_id
    WHERE rr.id = ? AND l.owner_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $request_id, $owner_id);
$stmt->execute();
$result = $stmt->get_result();
$request = $result->fetch_assoc();
$stmt->close();

if (!$request) {
    $_SESSION['error'] = "Invalid request or you don't have permission to modify it.";
    header("Location: rental_requests_uo.php");
    exit();
}

// Only allow changes to pending requests
if ($request['status'] !== 'pending') {
    $_SESSION['error'] = "This request has already been " . $request['status'] . ".";
    header("Location: rental_requests_uo.php");
    exit();
}

// Update the status
$new_status = ($action === 'approve') ? 'approved' : 'rejected';

require_once 'update_listing_status.php';
$notificationData = null;

$conn->begin_transaction();

try {
    if ($new_status === 'rejected') {
        $stmt = $conn->prepare("
            UPDATE rental_requests
            SET status = ?, rejection_reason = ?, rejection_message = ?, rejected_by = ?, rejected_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("sssii", $new_status, $rejection_reason, $rejection_reason, $owner_id, $request_id);
    } else {
        $stmt = $conn->prepare("
            UPDATE rental_requests
            SET status = ?, approved_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("si", $new_status, $request_id);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('Failed to update rental request status.');
    }
    $stmt->close();

    if ($new_status === 'approved') {
        if (!applyOccupancyChange((int)$request['listing_id'], 1, $conn)) {
            throw new Exception('Failed to update listing availability.');
        }
    }

    $conn->commit();

    $notificationData = [
        'tenant_email' => $request['tenant_email'],
        'tenant_name'  => trim($request['tenant_first_name'] . ' ' . $request['tenant_last_name']),
        'owner_name'   => trim($request['owner_first_name'] . ' ' . $request['owner_last_name']),
        'property_title' => $request['property_title'],
        'amount_due'     => $request['amount_due']
    ];

    if ($new_status === 'approved') {
        $_SESSION['success'] = "Rental request approved successfully! Tenant has been notified via email.";
    } else {
        $_SESSION['success'] = "Rental request rejected. Tenant has been notified via email.";
    }
} catch (Exception $e) {
    $conn->rollback();
    error_log('process_rental_request error: ' . $e->getMessage());
    $_SESSION['error'] = "Failed to update request status. Please try again.";
}

if ($notificationData) {
    sendRequestStatusNotification(
        $notificationData['tenant_email'],
        $notificationData['tenant_name'],
        $notificationData['owner_name'],
        $notificationData['property_title'],
        $notificationData['amount_due'],
        $new_status,
        $request_id
    );
}

$conn->close();

header("Location: rental_requests_uo.php");
exit();
?>
