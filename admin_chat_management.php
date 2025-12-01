<?php
session_start();
require 'mysql_connect.php';
require_once 'includes/navigation.php';

// Only admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginModule.php");
    exit();
}

// Fetch all chat threads with participant details
$stmt = $conn->prepare("
    SELECT 
        ct.id as thread_id,
        ct.listing_id,
        ct.created_at as thread_created,
        l.title as property_title,
        l.address as property_address,
        GROUP_CONCAT(
            CONCAT(
                u.first_name, ' ', u.last_name, 
                ' (', cp.role, ')'
            ) SEPARATOR ' | '
        ) as participants,
        GROUP_CONCAT(u.email SEPARATOR ' | ') as participant_emails,
        COUNT(cm.id) as message_count,
        MAX(cm.created_at) as last_message_at
    FROM chat_threads ct
    LEFT JOIN tblistings l ON l.id = ct.listing_id
    LEFT JOIN chat_participants cp ON cp.thread_id = ct.id
    LEFT JOIN tbadmin u ON u.id = cp.user_id
    LEFT JOIN chat_messages cm ON cm.thread_id = ct.id
    GROUP BY ct.id, ct.listing_id, ct.created_at, l.title, l.address
    ORDER BY COALESCE(MAX(cm.created_at), ct.created_at) DESC
");
$stmt->execute();
$result = $stmt->get_result();
$threads = [];
while ($row = $result->fetch_assoc()) {
    $threads[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Chat Management - HanapBahay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="darkmode.css">
    <style>
        body { background: #f7f7fb; }
        .topbar { background: #8B4513; color: #fff; }
        .logo { height:42px; }
        .chat-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s ease;
        }
        .chat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .status-badge {
            font-size: 0.75rem;
        }
        .participant-info {
            font-size: 0.85rem;
            color: #666;
            max-height: 60px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .message-count {
            background: #e3f2fd;
            color: #1976d2;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        .chat-card {
            max-height: 300px;
            overflow: hidden;
        }
        .chat-card .card-body {
            padding: 1rem;
        }
        .participant-list {
            max-height: 40px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .email-list {
            max-height: 40px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .message-content {
            word-wrap: break-word;
            white-space: pre-wrap;
        }
        #chatMessages {
            max-height: 400px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <?= getNavigationForRole('admin_chat_management.php') ?>

    <main class="container py-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="bi bi-chat-dots"></i> Chat Management</h2>
                    <div class="d-flex gap-2">
                        <span class="badge bg-primary"><?= count($threads) ?> Conversations</span>
                    </div>
                </div>

                <!-- Search and Filter -->
                <div class="row mb-4">
                    <div class="col-md-5">
                        <input type="text" id="searchInput" class="form-control" placeholder="Search by property, participants, or email...">
                    </div>
                    <div class="col-md-2">
                        <select id="messageFilter" class="form-select">
                            <option value="">All Conversations</option>
                            <option value="active">With Messages</option>
                            <option value="empty">No Messages</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select id="dateFilter" class="form-select">
                            <option value="">All Time</option>
                            <option value="today">Today</option>
                            <option value="week">This Week</option>
                            <option value="month">This Month</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="button" id="clearFilters" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-x-circle"></i> Clear Filters
                        </button>
                    </div>
                </div>

                <!-- Chat Threads -->
                <div class="row" id="chatThreads">
                    <?php if (empty($threads)): ?>
                        <div class="col-12">
                            <div class="alert alert-info text-center">
                                <i class="bi bi-chat-square"></i>
                                No chat conversations found.
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($threads as $thread): ?>
                            <?php
                            // Clean up participants - remove duplicates and limit display
                            $participants = explode(' | ', $thread['participants']);
                            $unique_participants = array_unique($participants);
                            $display_participants = array_slice($unique_participants, 0, 3); // Show max 3
                            $participant_text = implode(', ', $display_participants);
                            if (count($unique_participants) > 3) {
                                $participant_text .= ' (+' . (count($unique_participants) - 3) . ' more)';
                            }
                            
                            // Clean up emails - remove duplicates and limit display
                            $emails = explode(' | ', $thread['participant_emails']);
                            $unique_emails = array_unique($emails);
                            $display_emails = array_slice($unique_emails, 0, 2); // Show max 2
                            $email_text = implode(', ', $display_emails);
                            if (count($unique_emails) > 2) {
                                $email_text .= ' (+' . (count($unique_emails) - 2) . ' more)';
                            }
                            ?>
                            <div class="col-md-6 col-lg-4 mb-3" 
                                 data-participants="<?= htmlspecialchars(strtolower($thread['participants'] . ' ' . $thread['participant_emails'])) ?>"
                                 data-message-count="<?= $thread['message_count'] ?>"
                                 data-created="<?= strtotime($thread['thread_created']) ?>"
                                 data-last-message="<?= $thread['last_message_at'] ? strtotime($thread['last_message_at']) : 0 ?>">
                                <div class="chat-card p-3 h-100">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="mb-0 text-truncate" title="<?= htmlspecialchars($thread['property_title']) ?>">
                                            <?= htmlspecialchars($thread['property_title']) ?>
                                        </h6>
                                        <span class="message-count"><?= $thread['message_count'] ?></span>
                                    </div>
                                    
                                    <p class="text-muted small mb-2">
                                        <i class="bi bi-geo-alt"></i>
                                        <?= htmlspecialchars(substr($thread['property_address'], 0, 50)) ?>
                                        <?php if (strlen($thread['property_address']) > 50): ?>...<?php endif; ?>
                                    </p>
                                    
                                    <div class="participant-info mb-2">
                                        <strong>Participants:</strong><br>
                                        <div class="participant-list" title="<?= htmlspecialchars($thread['participants']) ?>">
                                            <?= htmlspecialchars($participant_text) ?>
                                        </div>
                                    </div>
                                    
                                    <div class="participant-info mb-3">
                                        <strong>Emails:</strong><br>
                                        <div class="email-list" title="<?= htmlspecialchars($thread['participant_emails']) ?>">
                                            <?= htmlspecialchars($email_text) ?>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-muted">
                                            <?php if ($thread['last_message_at']): ?>
                                                Last: <?= date('M j, g:i A', strtotime($thread['last_message_at'])) ?>
                                            <?php else: ?>
                                                Created: <?= date('M j, g:i A', strtotime($thread['thread_created'])) ?>
                                            <?php endif; ?>
                                        </small>
                                        <button class="btn btn-sm btn-outline-primary" 
                                                onclick="viewChatThread(<?= $thread['thread_id'] ?>)">
                                            <i class="bi bi-eye"></i> View Chat
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Chat Thread Modal -->
    <div class="modal fade" id="chatModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="chatModalTitle">Chat Conversation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="chatMessages" style="height: 400px; overflow-y: auto; border: 1px solid #dee2e6; padding: 15px; border-radius: 8px;">
                        <!-- Messages will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Search and filter functionality
        const searchInput = document.getElementById('searchInput');
        const messageFilter = document.getElementById('messageFilter');
        const dateFilter = document.getElementById('dateFilter');
        const chatThreads = document.querySelectorAll('#chatThreads > div[data-participants]');
        const emptyStateDiv = document.querySelector('#chatThreads > div:not([data-participants])');

        function filterThreads() {
            const searchTerm = searchInput.value.toLowerCase();
            const messageValue = messageFilter.value;
            const dateValue = dateFilter.value;
            const now = Date.now();
            let visibleCount = 0;

            chatThreads.forEach(thread => {
                const participants = thread.dataset.participants;
                const messageCount = parseInt(thread.dataset.messageCount);
                const created = parseInt(thread.dataset.created) * 1000;
                const lastMessage = parseInt(thread.dataset.lastMessage) * 1000;

                const matchesSearch = participants.includes(searchTerm);
                
                let matchesMessage = true;
                if (messageValue === 'active') matchesMessage = messageCount > 0;
                if (messageValue === 'empty') matchesMessage = messageCount === 0;

                let matchesDate = true;
                if (dateValue === 'today') {
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    matchesDate = lastMessage >= today.getTime() || created >= today.getTime();
                } else if (dateValue === 'week') {
                    const weekAgo = now - (7 * 24 * 60 * 60 * 1000);
                    matchesDate = lastMessage >= weekAgo || created >= weekAgo;
                } else if (dateValue === 'month') {
                    const monthAgo = now - (30 * 24 * 60 * 60 * 1000);
                    matchesDate = lastMessage >= monthAgo || created >= monthAgo;
                }

                if (matchesSearch && matchesMessage && matchesDate) {
                    thread.style.display = '';
                    visibleCount++;
                } else {
                    thread.style.display = 'none';
                }
            });

            // Show/hide empty state message based on visible threads
            if (emptyStateDiv) {
                if (visibleCount === 0 && chatThreads.length > 0) {
                    // Show "no results" message when filtering
                    emptyStateDiv.style.display = '';
                    emptyStateDiv.innerHTML = `
                        <div class="col-12">
                            <div class="alert alert-warning text-center">
                                <i class="bi bi-search"></i>
                                No conversations match your current filters.
                                <br><small>Try adjusting your search criteria or clearing filters.</small>
                            </div>
                        </div>
                    `;
                } else if (visibleCount > 0) {
                    // Hide empty state when there are visible results
                    emptyStateDiv.style.display = 'none';
                }
            }
        }

        searchInput.addEventListener('input', filterThreads);
        messageFilter.addEventListener('change', filterThreads);
        dateFilter.addEventListener('change', filterThreads);

        // Clear filters functionality
        document.getElementById('clearFilters').addEventListener('click', function() {
            searchInput.value = '';
            messageFilter.value = '';
            dateFilter.value = '';
            filterThreads();
        });

        // View chat thread
        function viewChatThread(threadId) {
            const modal = new bootstrap.Modal(document.getElementById('chatModal'));
            document.getElementById('chatModalTitle').textContent = `Chat Thread #${threadId}`;
            
            // Show loading state
            const messagesContainer = document.getElementById('chatMessages');
            messagesContainer.innerHTML = '<div class="text-center p-4"><i class="bi bi-hourglass-split"></i> Loading messages...</div>';
            
            // Load messages
            fetch(`api/chat/admin_fetch_messages.php?thread_id=${threadId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        messagesContainer.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>Error:</strong> ${data.error}
                                <br><small>Please try again or contact support if the issue persists.</small>
                            </div>
                        `;
                    } else if (data.messages && data.messages.length > 0) {
                        messagesContainer.innerHTML = data.messages.map(msg => `
                            <div class="mb-3 p-3 ${msg.sender_id == 0 ? 'bg-light border-start border-3 border-info' : 'bg-white border'} rounded">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <strong class="text-primary">${msg.sender_name || 'System'}</strong>
                                    <small class="text-muted">${new Date(msg.created_at).toLocaleString()}</small>
                                </div>
                                <div class="message-content">${msg.body}</div>
                            </div>
                        `).join('');
                    } else {
                        messagesContainer.innerHTML = `
                            <div class="alert alert-info text-center">
                                <i class="bi bi-chat-square"></i>
                                No messages found in this conversation.
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading messages:', error);
                    messagesContainer.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Error loading messages:</strong> ${error.message}
                            <br><small>Please check your connection and try again.</small>
                        </div>
                    `;
                });
            
            modal.show();
        }
    </script>
    <script src="darkmode.js"></script>
</body>
</html>
