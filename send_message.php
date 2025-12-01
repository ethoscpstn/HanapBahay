<?php
require_once 'mysql_connect.php';
require_once 'includes/auth_helpers.php';
header('Content-Type: application/json');

if (!isset($_POST['message']) || !isset($_POST['conversation_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'User not authenticated']);
    exit;
}

$message = trim($_POST['message']);
$conversation_id = intval($_POST['conversation_id']);
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
    exit;
}

try {
    // Get conversation details to identify participants
    $stmt = $conn->prepare("
        SELECT ct.listing_id, ct.id as thread_id,
               GROUP_CONCAT(CASE WHEN cp.role = 'tenant' THEN cp.user_id END) as tenant_id,
               GROUP_CONCAT(CASE WHEN cp.role = 'owner' THEN cp.user_id END) as owner_id
        FROM chat_threads ct
        JOIN chat_participants cp ON cp.thread_id = ct.id
        WHERE ct.id = ?
        GROUP BY ct.id
    ");
    $stmt->bind_param("i", $conversation_id);
    $stmt->execute();
    $conversation_result = $stmt->get_result();
    $conversation = $conversation_result->fetch_assoc();
    $stmt->close();

    if (!$conversation) {
        echo json_encode(['success' => false, 'error' => 'Conversation not found']);
        exit;
    }

    // Insert the message
    $stmt = $conn->prepare("
        INSERT INTO chat_messages (conversation_id, sender_id, message_content, sent_time) 
        VALUES (?, ?, ?, NOW())
    ");
    
    $stmt->bind_param("iis", $conversation_id, $user_id, $message);
    
    if ($stmt->execute()) {
        $message_id = $conn->insert_id;
        
        // Send email notification if tenant is sending message to owner
        if ($user_role === 'tenant' && !empty($conversation['owner_id'])) {
            $tenant_id = $user_id;
            $owner_id = (int)$conversation['owner_id'];
            $listing_id = (int)$conversation['listing_id'];
            $thread_id = (int)$conversation['thread_id'];
            
            // Send email notification (non-blocking)
            hb_send_inquiry_notification($conn, $thread_id, $tenant_id, $owner_id, $message, $listing_id);
        }
        
        echo json_encode([
            'success' => true,
            'message_id' => $message_id,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } else {
        throw new Exception("Failed to send message");
    }
} catch (Exception $e) {
    error_log("Error in send_message.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to send message']);
}

$stmt->close();
$conn->close();