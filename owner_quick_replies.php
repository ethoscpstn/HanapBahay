<?php
session_start();
require 'mysql_connect.php';
require_once 'includes/navigation.php';

// Only owners can access
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'unit_owner')) {
    header("Location: LoginModule.php");
    exit();
}

$owner_id = $_SESSION['user_id'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $message = trim($_POST['message'] ?? '');
        $category = trim($_POST['category'] ?? 'general');
        $display_order = (int)($_POST['display_order'] ?? 0);
        
        if ($message) {
            $stmt = $conn->prepare("INSERT INTO owner_quick_replies (owner_id, message, category, display_order, is_active) VALUES (?, ?, ?, ?, 1)");
            $stmt->bind_param("issi", $owner_id, $message, $category, $display_order);
            if ($stmt->execute()) {
                $success_message = "Quick reply added successfully!";
            } else {
                $error_message = "Failed to add quick reply: " . $conn->error;
            }
            $stmt->close();
        } else {
            $error_message = "Message is required.";
        }
    } elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        $category = trim($_POST['category'] ?? 'general');
        $display_order = (int)($_POST['display_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($id && $message) {
            $stmt = $conn->prepare("UPDATE owner_quick_replies SET message = ?, category = ?, display_order = ?, is_active = ? WHERE id = ? AND owner_id = ?");
            $stmt->bind_param("ssiiii", $message, $category, $display_order, $is_active, $id, $owner_id);
            if ($stmt->execute()) {
                $success_message = "Quick reply updated successfully!";
            } else {
                $error_message = "Failed to update quick reply: " . $conn->error;
            }
            $stmt->close();
        } else {
            $error_message = "Invalid data provided.";
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM owner_quick_replies WHERE id = ? AND owner_id = ?");
            $stmt->bind_param("ii", $id, $owner_id);
            if ($stmt->execute()) {
                $success_message = "Quick reply deleted successfully!";
            } else {
                $error_message = "Failed to delete quick reply: " . $conn->error;
            }
            $stmt->close();
        } else {
            $error_message = "Invalid quick reply ID.";
        }
    }
}

// Create owner_quick_replies table if it doesn't exist
$create_table_sql = "CREATE TABLE IF NOT EXISTS `owner_quick_replies` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `owner_id` int(11) NOT NULL,
    `message` text NOT NULL,
    `category` varchar(50) DEFAULT 'general',
    `display_order` int(11) DEFAULT 0,
    `is_active` tinyint(1) DEFAULT 1,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `owner_id` (`owner_id`),
    KEY `category` (`category`),
    KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

$conn->query($create_table_sql);

// Insert default quick replies if table is empty for this owner
$count_result = $conn->prepare("SELECT COUNT(*) as count FROM owner_quick_replies WHERE owner_id = ?");
$count_result->bind_param("i", $owner_id);
$count_result->execute();
$count = $count_result->get_result()->fetch_assoc()['count'];
$count_result->close();

if ($count == 0) {
    $default_replies = [
        ['Thank you for your interest! The property is still available. Would you like to schedule a viewing?', 'availability', 1],
        ['Yes, we can arrange a viewing. Please let me know your preferred time and I\'ll confirm availability.', 'viewing', 2],
        ['The monthly rent is as listed. We require a security deposit equivalent to one month\'s rent.', 'pricing', 3],
        ['Utilities are not included in the rent. You\'ll need to set up your own electricity, water, and internet accounts.', 'utilities', 4],
        ['Yes, pets are allowed with an additional pet deposit. Please let me know what type of pet you have.', 'pets', 5],
        ['The property comes with basic furniture. Please see the photos for details on what\'s included.', 'furniture', 6],
        ['Parking is available on a first-come, first-served basis. There\'s no additional charge for parking.', 'parking', 7],
        ['The lease term is typically 12 months, but we can discuss shorter terms if needed.', 'lease', 8]
    ];
    
    $stmt = $conn->prepare("INSERT INTO owner_quick_replies (owner_id, message, category, display_order) VALUES (?, ?, ?, ?)");
    foreach ($default_replies as $reply) {
        $stmt->bind_param("issi", $owner_id, $reply[0], $reply[1], $reply[2]);
        $stmt->execute();
    }
    $stmt->close();
}

// Fetch all quick replies for this owner
$stmt = $conn->prepare("SELECT * FROM owner_quick_replies WHERE owner_id = ? ORDER BY display_order ASC, id ASC");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$result = $stmt->get_result();
$quick_replies = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get categories for filter
$categories = array_unique(array_column($quick_replies, 'category'));
sort($categories);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quick Reply Management - Owner Dashboard</title>
    <link rel="icon" type="image/png" sizes="16x16" href="Assets/HanapBahayTablogo.png?v=2">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="darkmode.css">
    <style>
        body { 
            background: var(--bg-primary);
            color: var(--text-primary);
        }
        .topbar { 
            background: var(--hb-brown);
            color: var(--text-primary);
        }
        .reply-card {
            background: var(--bg-secondary);
            border-radius: 10px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            margin-bottom: 20px;
            border-left: 4px solid var(--hb-brown);
        }
        .reply-message {
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 15px;
        }
        .category-badge {
            background: var(--hb-gold);
            color: var(--text-primary);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .form-control, .form-select {
            background: var(--bg-tertiary);
            border-color: var(--border-color);
            color: var(--text-primary);
        }
        .form-control:focus, .form-select:focus {
            background: var(--bg-tertiary);
            border-color: var(--hb-brown);
            color: var(--text-primary);
            box-shadow: 0 0 0 0.2rem rgba(139, 69, 19, 0.25);
        }
        .btn-primary {
            background: var(--hb-brown);
            border-color: var(--hb-brown);
        }
        .btn-primary:hover {
            background: var(--hb-gold);
            border-color: var(--hb-gold);
            color: var(--text-primary);
        }
        .btn-outline-danger:hover {
            background: var(--danger);
            border-color: var(--danger);
        }
        .preview-box {
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            font-style: italic;
            color: var(--text-secondary);
        }
        .hero-section {
            background: linear-gradient(135deg, var(--hb-brown) 0%, var(--hb-gold) 100%);
            color: white;
            padding: 40px 0;
            margin-bottom: 30px;
            border-radius: 15px;
        }
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        .no-results i {
            font-size: 4rem;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <?= getNavigationForRole('owner_quick_replies.php') ?>

    <main class="container py-4">
        <!-- Hero Section -->
        <div class="hero-section text-center">
            <h1 class="display-5 mb-3"><i class="bi bi-lightning-charge"></i> Quick Reply Management</h1>
            <p class="lead">Create custom quick replies to respond faster to tenant inquiries</p>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3><i class="bi bi-chat-dots"></i> Your Quick Replies</h3>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addReplyModal">
                <i class="bi bi-plus-circle"></i> Add New Reply
            </button>
        </div>

        <?php if (isset($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Category Filter -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">Filter by Category</h6>
                        <select id="categoryFilter" class="form-select">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars(ucfirst($category)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">Search Replies</h6>
                        <input type="text" id="searchInput" class="form-control" placeholder="Search messages...">
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Replies List -->
        <div id="repliesList">
            <?php foreach ($quick_replies as $reply): ?>
                <div class="reply-card" data-category="<?= htmlspecialchars($reply['category']) ?>" data-search="<?= strtolower(htmlspecialchars($reply['message'])) ?>">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="category-badge"><?= htmlspecialchars(ucfirst($reply['category'])) ?></span>
                            <span class="badge bg-secondary">Order: <?= $reply['display_order'] ?></span>
                            <?php if (!$reply['is_active']): ?>
                                <span class="badge bg-danger">Inactive</span>
                            <?php endif; ?>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary" onclick="editReply(<?= htmlspecialchars(json_encode($reply)) ?>)">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <button class="btn btn-outline-danger" onclick="deleteReply(<?= $reply['id'] ?>, '<?= htmlspecialchars(substr($reply['message'], 0, 50)) ?>...')">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                    <div class="reply-message"><?= htmlspecialchars($reply['message']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- No Results Message -->
        <div id="noResults" class="no-results" style="display: none;">
            <i class="bi bi-search"></i>
            <h5>No Quick Replies Found</h5>
            <p>Try adjusting your search or filter criteria.</p>
        </div>

        <!-- Usage Instructions -->
        <div class="card mt-4">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-info-circle"></i> How to Use Quick Replies</h5>
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="bi bi-chat-dots"></i> In Chat Conversations</h6>
                        <p class="small text-muted">When tenants message you, you'll see your quick replies as buttons. Click any button to instantly send that message.</p>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="bi bi-lightning-charge"></i> Best Practices</h6>
                        <ul class="small text-muted">
                            <li>Keep messages concise and friendly</li>
                            <li>Use categories to organize by topic</li>
                            <li>Set display order to prioritize common replies</li>
                            <li>Deactivate instead of deleting to preserve history</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Add Reply Modal -->
    <div class="modal fade" id="addReplyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background: var(--bg-secondary); color: var(--text-primary);">
                <div class="modal-header" style="border-color: var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add New Quick Reply</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label class="form-label">Message</label>
                            <textarea name="message" id="newMessage" class="form-control" rows="3" required placeholder="Enter your quick reply message..."></textarea>
                            <div class="form-text">This message will be sent instantly when clicked by tenants.</div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Category</label>
                                    <select name="category" class="form-select" required>
                                        <option value="general">General</option>
                                        <option value="availability">Availability</option>
                                        <option value="viewing">Viewing</option>
                                        <option value="pricing">Pricing</option>
                                        <option value="utilities">Utilities</option>
                                        <option value="pets">Pets</option>
                                        <option value="furniture">Furniture</option>
                                        <option value="parking">Parking</option>
                                        <option value="lease">Lease Terms</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Display Order</label>
                                    <input type="number" name="display_order" class="form-control" value="0" min="0">
                                    <div class="form-text">Lower numbers appear first</div>
                                </div>
                            </div>
                        </div>
                        <div class="preview-box">
                            <strong>Preview:</strong><br>
                            <span id="messagePreview">Your message will appear here...</span>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-color: var(--border-color);">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Quick Reply</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Reply Modal -->
    <div class="modal fade" id="editReplyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background: var(--bg-secondary); color: var(--text-primary);">
                <div class="modal-header" style="border-color: var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Quick Reply</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" id="editId">
                        <div class="mb-3">
                            <label class="form-label">Message</label>
                            <textarea name="message" id="editMessage" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Category</label>
                                    <select name="category" id="editCategory" class="form-select" required>
                                        <option value="general">General</option>
                                        <option value="availability">Availability</option>
                                        <option value="viewing">Viewing</option>
                                        <option value="pricing">Pricing</option>
                                        <option value="utilities">Utilities</option>
                                        <option value="pets">Pets</option>
                                        <option value="furniture">Furniture</option>
                                        <option value="parking">Parking</option>
                                        <option value="lease">Lease Terms</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Display Order</label>
                                    <input type="number" name="display_order" id="editDisplayOrder" class="form-control" min="0">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input type="checkbox" name="is_active" id="editIsActive" class="form-check-input" checked>
                                        <label class="form-check-label" for="editIsActive">Active</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="preview-box">
                            <strong>Preview:</strong><br>
                            <span id="editMessagePreview">Your message will appear here...</span>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-color: var(--border-color);">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Quick Reply</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="background: var(--bg-secondary); color: var(--text-primary);">
                <div class="modal-header" style="border-color: var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this quick reply?</p>
                    <div class="alert alert-warning">
                        <strong id="deleteMessage"></strong>
                    </div>
                    <p class="text-muted">This action cannot be undone.</p>
                </div>
                <div class="modal-footer" style="border-color: var(--border-color);">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="deleteId">
                        <button type="submit" class="btn btn-danger">Delete Reply</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Filter and search functionality
        document.getElementById('categoryFilter').addEventListener('change', filterReplies);
        document.getElementById('searchInput').addEventListener('input', filterReplies);

        function filterReplies() {
            const categoryFilter = document.getElementById('categoryFilter').value.toLowerCase();
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const replyCards = document.querySelectorAll('.reply-card');
            let visibleCount = 0;

            replyCards.forEach(card => {
                const category = card.dataset.category.toLowerCase();
                const searchText = card.dataset.search;
                
                const categoryMatch = !categoryFilter || category === categoryFilter;
                const searchMatch = !searchTerm || searchText.includes(searchTerm);
                
                if (categoryMatch && searchMatch) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            // Show/hide no results message
            const noResults = document.getElementById('noResults');
            if (visibleCount === 0) {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
        }

        // Edit reply function
        function editReply(reply) {
            document.getElementById('editId').value = reply.id;
            document.getElementById('editMessage').value = reply.message;
            document.getElementById('editCategory').value = reply.category;
            document.getElementById('editDisplayOrder').value = reply.display_order;
            document.getElementById('editIsActive').checked = reply.is_active == 1;
            
            // Update preview
            document.getElementById('editMessagePreview').textContent = reply.message;
            
            new bootstrap.Modal(document.getElementById('editReplyModal')).show();
        }

        // Delete reply function
        function deleteReply(id, message) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteMessage').textContent = message;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        // Live preview for new message
        document.getElementById('newMessage').addEventListener('input', function() {
            const preview = document.getElementById('messagePreview');
            preview.textContent = this.value || 'Your message will appear here...';
        });

        // Live preview for edit message
        document.getElementById('editMessage').addEventListener('input', function() {
            const preview = document.getElementById('editMessagePreview');
            preview.textContent = this.value || 'Your message will appear here...';
        });
    </script>
    <script src="darkmode.js"></script>
</body>
</html>
