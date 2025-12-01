<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../mysql_connect.php';
require_once __DIR__ . '/_auth.php';

try {
    [$user_id, $user_role] = current_user_id_and_role();
    if ($user_id === 0) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // Get quick replies based on user role
    if ($user_role === 'unit_owner' || $user_role === 'owner') {
        // For owners, get their custom quick replies first, then default ones
        $stmt = $conn->prepare("
            SELECT id, message, category, 'owner' as source
            FROM owner_quick_replies
            WHERE owner_id = ? AND is_active = 1
            UNION ALL
            SELECT id, message, category, 'default' as source
            FROM chat_quick_replies
            WHERE is_active = 1
            ORDER BY source ASC, display_order ASC, id ASC
        ");
        $stmt->bind_param("i", $user_id);
    } else {
        // For tenants, get default quick replies
        $stmt = $conn->prepare("
            SELECT id, message, category, 'default' as source
            FROM chat_quick_replies
            WHERE is_active = 1
            ORDER BY display_order ASC, id ASC
        ");
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $quick_replies = [];
    while ($row = $result->fetch_assoc()) {
        $quick_replies[] = $row;
    }
    $stmt->close();

    echo json_encode(['ok' => true, 'quick_replies' => $quick_replies]);

} catch (Throwable $e) {
    http_response_code(500);
    error_log('get_quick_replies.php: ' . $e->getMessage());
    echo json_encode(['error' => 'Server error']);
}
?>