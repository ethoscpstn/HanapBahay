<?php
session_start();
require_once __DIR__ . '/../../mysql_connect.php';
header('Content-Type: application/json');

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Debug session info
error_log('admin_fetch_messages.php - Session user_id: ' . ($_SESSION['user_id'] ?? 'not set'));
error_log('admin_fetch_messages.php - Session role: ' . ($_SESSION['role'] ?? 'not set'));

// Only admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    error_log('admin_fetch_messages.php - Unauthorized access attempt');
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

$thread_id = isset($_GET['thread_id']) ? (int)$_GET['thread_id'] : 0;
error_log('admin_fetch_messages.php - Thread ID: ' . $thread_id);

if (!$thread_id) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid thread ID']);
    exit;
}

try {
    // First check if thread exists
    $check_stmt = $conn->prepare("SELECT 1 FROM chat_threads WHERE id = ? LIMIT 1");
    $check_stmt->bind_param('i', $thread_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        $check_stmt->close();
        http_response_code(404);
        echo json_encode(['error' => 'Thread not found']);
        exit;
    }
    $check_stmt->close();
    
    // Fetch messages with sender names
    $sql = "
        SELECT 
            cm.id,
            cm.thread_id,
            cm.sender_id,
            cm.body,
            cm.created_at,
            CASE 
                WHEN cm.sender_id = 0 THEN 'System'
                ELSE CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''), ' (', COALESCE(cp.role, 'unknown'), ')')
            END as sender_name
        FROM chat_messages cm
        LEFT JOIN chat_participants cp ON cp.user_id = cm.sender_id AND cp.thread_id = cm.thread_id
        LEFT JOIN tbadmin u ON u.id = cm.sender_id
        WHERE cm.thread_id = ?
        ORDER BY cm.created_at ASC
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    
    $stmt->bind_param('i', $thread_id);
    $stmt->execute();
    
    if ($stmt->error) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $messages = [];
    
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    
    $stmt->close();
    
    error_log('admin_fetch_messages.php - Found ' . count($messages) . ' messages for thread ' . $thread_id);
    echo json_encode(['messages' => $messages]);
    
} catch (Throwable $e) {
    error_log('admin_fetch_messages.php error: ' . $e->getMessage());
    error_log('admin_fetch_messages.php stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
