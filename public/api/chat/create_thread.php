<?php
session_start();
require_once __DIR__ . '/../../mysql_connect.php';
require_once __DIR__ . '/_auth.php';
header('Content-Type: application/json');

[$me_id, $me_role] = current_user_id_and_role();
if ($me_id === 0) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

$tenant_id = (int)($_POST['tenant_id'] ?? 0);
$owner_id  = (int)($_POST['owner_id']  ?? 0);
if (!$tenant_id || !$owner_id || $tenant_id === $owner_id) {
  http_response_code(422); echo json_encode(['error'=>'Invalid participants']); exit;
}

try {
  // 1) Does a thread already exist for this pair?
  $sql = "
    SELECT t.id
    FROM chat_threads t
    JOIN chat_participants p1 ON p1.thread_id = t.id AND p1.user_id = ?
    JOIN chat_participants p2 ON p2.thread_id = t.id AND p2.user_id = ?
    LIMIT 1
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('ii', $tenant_id, $owner_id);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()) {
    echo json_encode(['status'=>'exists', 'thread_id'=>(int)$row['id']]);
    exit;
  }

  // 2) Create new thread
  $conn->query("INSERT INTO chat_threads () VALUES ()");
  $thread_id = (int)$conn->insert_id;

  $stmt = $conn->prepare("INSERT INTO chat_participants (thread_id, user_id, role) VALUES (?,?,?), (?,?,?)");
  $roleTenant = 'tenant'; $roleOwner = 'owner';
  $stmt->bind_param('iisis', $thread_id, $tenant_id, $roleTenant, $thread_id, $owner_id, $roleOwner);
  // note: mysqli needs exact types; if above throws, split into two INSERTs:
  // $stmt = $conn->prepare("INSERT INTO chat_participants (thread_id, user_id, role) VALUES (?,?,?)");
  // $stmt->bind_param('iis', $thread_id, $tenant_id, $roleTenant);
  // $stmt->execute();
  // $stmt->bind_param('iis', $thread_id, $owner_id, $roleOwner);
  // $stmt->execute();

  echo json_encode(['status'=>'created', 'thread_id'=>$thread_id]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'Server error']);
}
