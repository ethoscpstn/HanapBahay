<?php
// Start output buffering to prevent any accidental output before JSON
ob_start();

session_start();
define('HANAPBAHAY_SECURE', true);
require 'mysql_connect.php';

// Check if user is logged in as owner
if (!isset($_SESSION['owner_id'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$owner_id = (int)$_SESSION['owner_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

try {
    // Mark all approved, rejected, and cancelled requests as dismissed for this owner
    $stmt = $conn->prepare("
        UPDATE rental_requests rr
        JOIN tblistings l ON l.id = rr.listing_id
        SET rr.is_dismissed = 1
        WHERE l.owner_id = ?
          AND rr.status IN ('approved', 'rejected', 'cancelled')
    ");

    $stmt->bind_param("i", $owner_id);

    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        $stmt->close();
        $conn->close();

        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => "Cleared $affected request(s) from history"
        ]);
    } else {
        throw new Exception($stmt->error);
    }

} catch (Exception $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to clear history: ' . $e->getMessage()
    ]);
}
?>
