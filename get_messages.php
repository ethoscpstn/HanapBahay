<?php
require_once 'mysql_connect.php';
header('Content-Type: application/json');

if (!isset($_GET['conversation_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing conversation ID']);
    exit;
}

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'User not authenticated']);
    exit;
}

$conversation_id = intval($_GET['conversation_id']);
$user_id = $_SESSION['user_id'];
$last_time = isset($_GET['last_time']) ? $_GET['last_time'] : '0';

try {
    // Get messages after the last sync time with better ordering
    $stmt = $conn->prepare("
        SELECT 
            m.message_id,
            m.sender_id,
            m.message_content,
            m.sent_time,
            UNIX_TIMESTAMP(m.sent_time) as sent_timestamp,
            CASE WHEN m.sender_id = ? THEN 'sent' ELSE 'received' END as message_type
        FROM chat_messages m
        WHERE m.conversation_id = ?
        AND m.sent_time > ?
        ORDER BY m.sent_time ASC, m.message_id ASC
    ");
    
    $stmt->bind_param("iis", $user_id, $conversation_id, $last_time);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $messages = [];
        
        while ($row = $result->fetch_assoc()) {
            $messages[] = [
                'id' => $row['message_id'],
                'content' => $row['message_content'],
                'time' => $row['sent_time'],
                'timestamp' => (int)$row['sent_timestamp'],
                'type' => $row['message_type']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'messages' => $messages
        ]);
    } else {
        throw new Exception("Failed to fetch messages");
    }
} catch (Exception $e) {
    error_log("Error in get_messages.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to fetch messages']);
}

$stmt->close();
$conn->close();