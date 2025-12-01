<?php
// start_chat.php
session_start();
require 'mysql_connect.php';

// --- 1) Auth guard: tenant only ---
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'tenant') {
    header("Location: LoginModule.php");
    exit();
}

$tenant_id = $_SESSION['user_id'] ?? 0;
$listing_id = isset($_GET['listing_id']) ? (int)$_GET['listing_id'] : (isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : 0);
$is_ajax = (isset($_POST['ajax']) && $_POST['ajax'] === '1') || 
           (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');

// Debug logging for AJAX requests
if ($is_ajax) {
    error_log("start_chat.php AJAX request - tenant_id: $tenant_id, listing_id: $listing_id, role: " . ($_SESSION['role'] ?? 'not set'));
}

if ($tenant_id <= 0 || $listing_id <= 0) {
    http_response_code(400);
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid request']);
    } else {
        echo "Invalid request.";
    }
    exit();
}

// Optional: strict error mode for mysqli
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->begin_transaction();

    // --- 2) Fetch listing & owner (and lock the listing row to serialize concurrent chat starts) ---
    $stmt = $conn->prepare("SELECT id, owner_id FROM tblistings WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->bind_param("i", $listing_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $listing = $res->fetch_assoc();
    $stmt->close();

    if (!$listing) {
        $conn->rollback();
        http_response_code(404);
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Listing not found']);
        } else {
            echo "Listing not found.";
        }
        exit();
    }

    $owner_id = (int)$listing['owner_id'];
    if ($owner_id <= 0) {
        $conn->rollback();
        http_response_code(422);
        echo "Listing has no associated owner.";
        exit();
    }

    if ($owner_id === $tenant_id) {
        // Prevent owners from chatting themselves via tenant flow (edge-case)
        $conn->rollback();
        http_response_code(403);
        echo "You cannot start a chat with yourself.";
        exit();
    }

    // --- 3) Try to find an existing thread for (listing_id + both participants) ---
    // We search chat_threads by listing_id, then ensure both tenant & owner are participants.
    $sql = "
        SELECT ct.id AS thread_id
        FROM chat_threads ct
        JOIN chat_participants cp1 ON cp1.thread_id = ct.id AND cp1.user_id = ?
        JOIN chat_participants cp2 ON cp2.thread_id = ct.id AND cp2.user_id = ?
        WHERE ct.listing_id = ?
        LIMIT 1
        FOR UPDATE
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $tenant_id, $owner_id, $listing_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $existing = $res->fetch_assoc();
    $stmt->close();

    if ($existing && (int)$existing['thread_id'] > 0) {
        // Found existing thread → commit and return/redirect
        $thread_id = (int)$existing['thread_id'];
        $conn->commit();
        if ($is_ajax) {
            error_log("start_chat.php returning existing thread_id: $thread_id");
            header('Content-Type: application/json');
            echo json_encode(['thread_id' => $thread_id]);
        } else {
            header("Location: DashboardT.php?thread_id=" . $thread_id);
        }
        exit();
    }

    // --- 4) Create a new thread ---
    $stmt = $conn->prepare("INSERT INTO chat_threads (listing_id, created_at) VALUES (?, NOW())");
    $stmt->bind_param("i", $listing_id);
    $stmt->execute();
    $thread_id = (int)$conn->insert_id;
    $stmt->close();

    // --- 5) Insert participants (tenant & owner) with duplicate prevention ---
    $role_tenant = 'tenant';
    $role_owner  = 'owner';

    // Insert tenant participant
    $stmt = $conn->prepare("INSERT IGNORE INTO chat_participants (thread_id, user_id, role) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $thread_id, $tenant_id, $role_tenant);
    $stmt->execute();
    $stmt->close();

    // Insert owner participant
    $stmt = $conn->prepare("INSERT IGNORE INTO chat_participants (thread_id, user_id, role) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $thread_id, $owner_id, $role_owner);
    $stmt->execute();
    $stmt->close();

    // --- 6) (Optional) Seed a system message to mark start of conversation ---
    // Comment out if you don't want it.
    if ($conn->prepare("INSERT INTO chat_messages (thread_id, sender_id, body, created_at) VALUES (?, ?, ?, NOW())")) {
        $stmt = $conn->prepare("INSERT INTO chat_messages (thread_id, sender_id, body, created_at) VALUES (?, ?, ?, NOW())");
        $system_text = "Chat started for this listing.";
        $zero_sender = 0; // 0 = system; change if you have a dedicated system user
        $stmt->bind_param("iis", $thread_id, $zero_sender, $system_text);
        $stmt->execute();
        $stmt->close();
    }

    // --- 7) Send email notification for new conversation ---
    require_once 'includes/auth_helpers.php';
    hb_send_new_conversation_notification($conn, $thread_id, $tenant_id, $owner_id, $listing_id);

    // --- 8) Done ---
    $conn->commit();
    if ($is_ajax) {
        error_log("start_chat.php returning new thread_id: $thread_id");
        header('Content-Type: application/json');
        echo json_encode(['thread_id' => $thread_id]);
    } else {
        header("Location: DashboardT.php?thread_id=" . $thread_id);
    }
    exit();

} catch (Throwable $e) {
    // Safety rollback
    if ($conn->errno) {
        $conn->rollback();
    }
    // In production, log this instead of echoing
    http_response_code(500);
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failed to start chat: ' . $e->getMessage()]);
    } else {
        echo "Failed to start chat. " . htmlspecialchars($e->getMessage());
    }
    exit();
}
