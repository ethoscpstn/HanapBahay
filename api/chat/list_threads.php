<?php
session_start();
require_once __DIR__ . '/../../mysql_connect.php';
require_once __DIR__ . '/_auth.php';
header('Content-Type: application/json');

[$me_id, $me_role] = current_user_id_and_role();
error_log("list_threads.php - me_id: $me_id, me_role: $me_role");

if ($me_id === 0) { 
  error_log("list_threads.php - Unauthorized access");
  http_response_code(401); 
  echo json_encode(['error'=>'Unauthorized']); 
  exit; 
}

/*
  Returns the user’s threads with:
   - thread_id
   - counterparty_id, counterparty_name (from tbadmin)
   - last_message preview + timestamp
*/
$sql = "
  SELECT
    t.id AS thread_id,
    u2.id   AS counterparty_id,
    CONCAT(u2.first_name,' ',u2.last_name) AS counterparty_name,
    l.title AS listing_title,
    m.body  AS last_body,
    m.created_at AS last_at
  FROM chat_threads t
  JOIN chat_participants me  ON me.thread_id = t.id AND me.user_id = ?
  JOIN chat_participants cp  ON cp.thread_id = t.id AND cp.user_id <> me.user_id
  JOIN tbadmin u2            ON u2.id = cp.user_id
  LEFT JOIN tblistings l     ON l.id = t.listing_id
  LEFT JOIN (
    SELECT cm1.thread_id, cm1.body, cm1.created_at
    FROM chat_messages cm1
    LEFT JOIN chat_messages cm2 ON cm1.thread_id = cm2.thread_id AND cm1.id < cm2.id
    WHERE cm2.id IS NULL
  ) m ON m.thread_id = t.id
  ORDER BY COALESCE(m.created_at, t.created_at) DESC
";
try {
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    throw new Exception('Prepare failed: ' . $conn->error);
  }
  
  $stmt->bind_param('i', $me_id);
  $stmt->execute();
  
  if ($stmt->error) {
    throw new Exception('Execute failed: ' . $stmt->error);
  }
  
  $res = $stmt->get_result();
  $rows = $res->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  
  echo json_encode(['threads' => $rows]);
  
} catch (Exception $e) {
  error_log('list_threads.php error: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
