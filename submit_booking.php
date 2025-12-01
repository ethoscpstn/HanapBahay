<?php
session_start();
require 'mysql_connect.php';
require 'send_rental_notification.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tenant') {
        header("Location: LoginModule.php");
        exit();
    }

    $tenant_id = $_SESSION['user_id'];
    $listing_id = intval($_POST['listing_id']);
    $payment_method = $_POST['payment_method'];

    // Fetch listing details and owner info
    $stmt = $conn->prepare("
        SELECT l.price, l.title, l.owner_id,
               o.email, o.first_name, o.last_name
        FROM tblistings l
        JOIN tbadmin o ON o.id = l.owner_id
        WHERE l.id = ?
    ");
    $stmt->bind_param("i", $listing_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $price = $result['price'];
    $propertyTitle = $result['title'];
    $ownerEmail = $result['email'];
    $ownerName = trim($result['first_name'] . ' ' . $result['last_name']);
    $stmt->close();

    // Fetch tenant name
    $stmt = $conn->prepare("SELECT first_name, last_name FROM tbadmin WHERE id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $tenantResult = $stmt->get_result()->fetch_assoc();
    $tenantName = trim($tenantResult['first_name'] . ' ' . $tenantResult['last_name']);
    $stmt->close();

    // Compute amount due based on payment method
    if ($payment_method === 'half') {
        $amount_due = $price * 0.5; // Legacy support for half payment
    } elseif ($payment_method === 'full_with_advance') {
        $amount_due = $price * 2; // 2x rent (1 month rent + 1 month advance deposit)
    } else {
        $amount_due = $price * 0.5; // Default to reservation fee (50%)
    }

    // Insert rental request
    $stmt = $conn->prepare("INSERT INTO rental_requests (tenant_id, listing_id, payment_method, amount_due, status) VALUES (?, ?, ?, ?, 'pending')");
    $stmt->bind_param("iisd", $tenant_id, $listing_id, $payment_method, $amount_due);
    $stmt->execute();
    $request_id = $conn->insert_id;
    $stmt->close();

    // Send email notification to owner
    sendRentalRequestNotification(
        $ownerEmail,
        $ownerName,
        $tenantName,
        $propertyTitle,
        $amount_due,
        $payment_method,
        $request_id
    );

    header("Location: rental_request.php");
    exit();
}
?>
