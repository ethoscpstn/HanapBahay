<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../mysql_connect.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/pusher_config.php';

try {
  [$me_id, $me_role] = current_user_id_and_role();   // or (int)$_SESSION['user_id']
  if ($me_id === 0) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

  $thread_id = (int)($_POST['thread_id'] ?? 0);
  $body      = trim($_POST['body'] ?? '');
  if (!$thread_id || $body === '') { http_response_code(422); echo json_encode(['error'=>'Invalid input']); exit; }

  // participant check
  $chk = $conn->prepare("SELECT 1 FROM chat_participants WHERE thread_id=? AND user_id=? LIMIT 1");
  $chk->bind_param('ii', $thread_id, $me_id);
  $chk->execute(); $chk->store_result();
  if ($chk->num_rows === 0) { http_response_code(403); echo json_encode(['error'=>'Not a participant']); exit; }
  $chk->close();

  // insert
  $stmt = $conn->prepare("INSERT INTO chat_messages (thread_id, sender_id, body) VALUES (?, ?, ?)");
  $stmt->bind_param('iis', $thread_id, $me_id, $body);
  $stmt->execute();
  $msg_id = (int)$stmt->insert_id;
  $stmt->close();

  // load the inserted row
  $stmt = $conn->prepare("SELECT id, thread_id, sender_id, body, created_at FROM chat_messages WHERE id=?");
  $stmt->bind_param('i', $msg_id);
  $stmt->execute();
  $msg = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  // Check for auto-reply trigger (only for tenant messages)
  $auto_reply_sent = false;
  if ($me_role === 'tenant') {
    $body_lower = strtolower($body);

    // Get matching auto-reply pattern
    $auto_stmt = $conn->prepare("
      SELECT response_message, match_type
      FROM chat_auto_replies
      WHERE is_active = 1
      AND (
        (match_type = 'contains' AND ? LIKE CONCAT('%', LOWER(trigger_pattern), '%'))
        OR (match_type = 'starts_with' AND ? LIKE CONCAT(LOWER(trigger_pattern), '%'))
        OR (match_type = 'exact' AND LOWER(trigger_pattern) = ?)
      )
      ORDER BY
        CASE match_type
          WHEN 'exact' THEN 1
          WHEN 'starts_with' THEN 2
          WHEN 'contains' THEN 3
        END
      LIMIT 1
    ");
    $auto_stmt->bind_param('sss', $body_lower, $body_lower, $body_lower);
    $auto_stmt->execute();
    $auto_result = $auto_stmt->get_result();

    if ($auto_row = $auto_result->fetch_assoc()) {
      // Insert auto-reply message from system/owner
      $owner_stmt = $conn->prepare("
        SELECT user_id FROM chat_participants
        WHERE thread_id = ? AND role = 'owner'
        LIMIT 1
      ");
      $owner_stmt->bind_param('i', $thread_id);
      $owner_stmt->execute();
      $owner_result = $owner_stmt->get_result();

      if ($owner_row = $owner_result->fetch_assoc()) {
        $owner_id = (int)$owner_row['user_id'];

        // Add small delay to make auto-reply feel more natural
        sleep(1);

        $reply_stmt = $conn->prepare("INSERT INTO chat_messages (thread_id, sender_id, body) VALUES (?, ?, ?)");
        $reply_stmt->bind_param('iis', $thread_id, $owner_id, $auto_row['response_message']);
        $reply_stmt->execute();
        $reply_id = (int)$reply_stmt->insert_id;
        $reply_stmt->close();

        // Load the auto-reply message
        $load_reply_stmt = $conn->prepare("SELECT id, thread_id, sender_id, body, created_at FROM chat_messages WHERE id=?");
        $load_reply_stmt->bind_param('i', $reply_id);
        $load_reply_stmt->execute();
        $reply_msg = $load_reply_stmt->get_result()->fetch_assoc();
        $load_reply_stmt->close();

        $auto_reply_sent = $reply_msg;
      }
      $owner_stmt->close();
    }
    $auto_stmt->close();
  }

  // realtime (don't fail request if push errs)
  try {
    $pusher = pusher_client();
    $pusher->trigger("thread-{$thread_id}", 'new-message', $msg);

    // Also send auto-reply via pusher if one was generated
    if ($auto_reply_sent) {
      $pusher->trigger("thread-{$thread_id}", 'new-message', $auto_reply_sent);
    }
  } catch (Throwable $e) {
    error_log('Pusher error: '.$e->getMessage());
  }

  echo json_encode(['ok'=>true, 'message'=>$msg, 'auto_reply'=>$auto_reply_sent]);

} catch (Throwable $e) {
  http_response_code(500);
  error_log('post_message.php: '.$e->getMessage());
  echo json_encode(['error'=>'Server error']);
}
