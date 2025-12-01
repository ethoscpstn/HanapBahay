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

  // Check if thread exists
  $thread_chk = $conn->prepare("SELECT 1 FROM chat_threads WHERE id=? LIMIT 1");
  $thread_chk->bind_param('i', $thread_id);
  $thread_chk->execute(); $thread_chk->store_result();
  if ($thread_chk->num_rows === 0) { 
    http_response_code(404); 
    echo json_encode(['error'=>'Thread not found', 'code'=>'THREAD_NOT_FOUND']); 
    exit; 
  }
  $thread_chk->close();

  // participant check
  $chk = $conn->prepare("SELECT 1 FROM chat_participants WHERE thread_id=? AND user_id=? LIMIT 1");
  $chk->bind_param('ii', $thread_id, $me_id);
  $chk->execute(); $chk->store_result();
  if ($chk->num_rows === 0) { 
    http_response_code(403); 
    echo json_encode(['error'=>'Not a participant in this thread', 'code'=>'NOT_PARTICIPANT']); 
    exit; 
  }
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
  error_log("post_message.php - Role: $me_role, Thread: $thread_id, Body: $body");
  if ($me_role === 'tenant') {
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
      $auto_reply_allowed = true;
      $activity_window_seconds = 300; // 5 minutes

      // Skip auto replies when owner has responded recently (but not auto-replies)
      $recent_stmt = $conn->prepare("
        SELECT created_at, sender_id
        FROM chat_messages
        WHERE thread_id = ? AND sender_id = ?
        ORDER BY id DESC
        LIMIT 1
      ");
      $recent_stmt->bind_param('ii', $thread_id, $owner_id);
      $recent_stmt->execute();
      $recent_result = $recent_stmt->get_result();

      if ($recent_row = $recent_result->fetch_assoc()) {
        $lastOwnerTs = strtotime($recent_row['created_at'] ?? '');
        // Only block if owner responded recently AND it wasn't an auto-reply (sender_id = 0)
        if ($lastOwnerTs && ($lastOwnerTs >= (time() - $activity_window_seconds)) && $recent_row['sender_id'] != 0) {
          $auto_reply_allowed = false;
          error_log("post_message.php - Auto-reply blocked: owner responded recently (sender_id: {$recent_row['sender_id']})");
        } else {
          error_log("post_message.php - Auto-reply allowed: no recent owner activity or last message was auto-reply");
        }
      }
      $recent_stmt->close();

      if ($auto_reply_allowed) {
        $body_lower = strtolower($body);
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
        $body_lower1 = $body_lower;
        $body_lower2 = $body_lower;
        $body_lower3 = $body_lower;
        $auto_stmt->bind_param('sss', $body_lower1, $body_lower2, $body_lower3);
        $auto_stmt->execute();
        $auto_result = $auto_stmt->get_result();

        $response_message = '';
        if ($auto_row = $auto_result->fetch_assoc()) {
          $response_message = $auto_row['response_message'];
        } else {
          $response_message = "This question is outside the available quick replies. Please type your message and the owner will respond when available.";
        }
        $auto_stmt->close();

        // Add small delay to make auto-reply feel more natural
        sleep(1);

        $reply_stmt = $conn->prepare("INSERT INTO chat_messages (thread_id, sender_id, body) VALUES (?, ?, ?)");
        $auto_reply_sender_id = 0; // sender_id = 0 for auto-replies
        $reply_stmt->bind_param('iis', $thread_id, $auto_reply_sender_id, $response_message);
        $reply_stmt->execute();
        $reply_id = (int)$reply_stmt->insert_id;
        $reply_stmt->close();

        $load_reply_stmt = $conn->prepare("SELECT id, thread_id, sender_id, body, created_at FROM chat_messages WHERE id=?");
        $load_reply_stmt->bind_param('i', $reply_id);
        $load_reply_stmt->execute();
        $reply_msg = $load_reply_stmt->get_result()->fetch_assoc();
        $load_reply_stmt->close();

        $auto_reply_sent = $reply_msg;
        error_log("post_message.php - Auto-reply sent: " . $response_message);
      } else {
        error_log("post_message.php - Auto-reply not allowed");
      }
    }
    $owner_stmt->close();
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
