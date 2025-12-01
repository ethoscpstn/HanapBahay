<?php
/**
 * Backwards compatibility shim for legacy clients still calling
 * /api/chat/send_message.php with {conversation_id, message} payloads.
 * We simply remap the parameters and delegate to post_message.php.
 */

// Normalize legacy parameter names to the current API
if (isset($_POST['conversation_id']) && !isset($_POST['thread_id'])) {
    $_POST['thread_id'] = $_POST['conversation_id'];
}
if (isset($_POST['message']) && !isset($_POST['body'])) {
    $_POST['body'] = $_POST['message'];
}

require __DIR__ . '/post_message.php';
