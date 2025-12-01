<?php
function current_user_id_and_role(): array {
  if (!empty($_SESSION['owner_id'])) { return [(int)$_SESSION['owner_id'], 'owner']; }
  if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'tenant') {
    return [(int)$_SESSION['user_id'], 'tenant'];
  }
  return [0, ''];
}
