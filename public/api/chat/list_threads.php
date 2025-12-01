<?php
session_start();
require_once __DIR__ . '/../../mysql_connect.php';
require_once __DIR__ . '/_auth.php';
header('Content-Type: application/json');

[$me_id, $me_role] = current_user_id_and_role();
if ($me_id === 0) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

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
    m.body  AS last_body,
    m.created_at AS last_at
  FROM chat_threads t
  JOIN chat_participants me  ON me.thread_id = t.id AND me.user_id = ?
  JOIN chat_participants cp  ON cp.thread_id = t.id AND cp.user_id <> me.user_id
  JOIN tbadmin u2            ON u2.id = cp.user_id
  LEFT JOIN LATERAL (
    SELECT body, created_at FROM chat_messages
    WHERE thread_id = t.id
    ORDER BY id DESC LIMIT 1
  ) m ON 1=1
  ORDER BY COALESCE(m.created_at, t.created_at) DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $me_id);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);

echo json_encode(['threads' => $rows]);
