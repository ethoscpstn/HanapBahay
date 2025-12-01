<?php
session_start();
require_once __DIR__ . '/../../mysql_connect.php';
require_once __DIR__ . '/_auth.php';
header('Content-Type: application/json');

// ini_set('display_errors',1); error_reporting(E_ALL);

[$me_id, $me_role] = current_user_id_and_role();
if ($me_id === 0) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

$thread_id = isset($_GET['thread_id']) ? (int)$_GET['thread_id'] : 0;
$before_id = isset($_GET['before_id']) ? (int)$_GET['before_id'] : PHP_INT_MAX;
$limit = 30;
if (!$thread_id) { http_response_code(422); echo json_encode(['error'=>'Invalid thread']); exit; }

// participant check
$stmt = $conn->prepare("SELECT 1 FROM chat_participants WHERE thread_id=? AND user_id=? LIMIT 1");
$stmt->bind_param('ii', $thread_id, $me_id);
$stmt->execute(); $stmt->store_result();
if ($stmt->num_rows === 0) { http_response_code(403); echo json_encode(['error'=>'Not a participant']); exit; }
$stmt->close();

// fetch page (newest first, then reverse)
$sql = "
  SELECT id, thread_id, sender_id, body, created_at, read_at
  FROM chat_messages
  WHERE thread_id=? AND id < ?
  ORDER BY id DESC
  LIMIT $limit
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $thread_id, $before_id);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$rows = array_reverse($rows);
$next_before = $rows ? (int)$rows[0]['id'] : null;

echo json_encode(['messages'=>$rows, 'next_before_id'=>$next_before]);
