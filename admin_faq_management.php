<?php
session_start();
require 'mysql_connect.php';
require_once 'includes/navigation.php';

// Only admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginModule.php");
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $question = trim($_POST['question'] ?? '');
        $answer = trim($_POST['answer'] ?? '');
        $category = trim($_POST['category'] ?? 'general');
        $display_order = (int)($_POST['display_order'] ?? 0);
        
        if ($question && $answer) {
            $stmt = $conn->prepare("INSERT INTO faqs (question, answer, category, display_order, is_active) VALUES (?, ?, ?, ?, 1)");
            $stmt->bind_param("sssi", $question, $answer, $category, $display_order);
            if ($stmt->execute()) {
                $success_message = "FAQ added successfully!";
            } else {
                $error_message = "Failed to add FAQ: " . $conn->error;
            }
            $stmt->close();
        } else {
            $error_message = "Question and answer are required.";
        }
    } elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $question = trim($_POST['question'] ?? '');
        $answer = trim($_POST['answer'] ?? '');
        $category = trim($_POST['category'] ?? 'general');
        $display_order = (int)($_POST['display_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($id && $question && $answer) {
            $stmt = $conn->prepare("UPDATE faqs SET question = ?, answer = ?, category = ?, display_order = ?, is_active = ? WHERE id = ?");
            $stmt->bind_param("sssiii", $question, $answer, $category, $display_order, $is_active, $id);
            if ($stmt->execute()) {
                $success_message = "FAQ updated successfully!";
            } else {
                $error_message = "Failed to update FAQ: " . $conn->error;
            }
            $stmt->close();
        } else {
            $error_message = "Invalid data provided.";
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM faqs WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $success_message = "FAQ deleted successfully!";
            } else {
                $error_message = "Failed to delete FAQ: " . $conn->error;
            }
            $stmt->close();
        } else {
            $error_message = "Invalid FAQ ID.";
        }
    }
}

// Create faqs table if it doesn't exist
$create_table_sql = "CREATE TABLE IF NOT EXISTS `faqs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `question` text NOT NULL,
    `answer` text NOT NULL,
    `category` varchar(50) DEFAULT 'general',
    `display_order` int(11) DEFAULT 0,
    `is_active` tinyint(1) DEFAULT 1,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

$conn->query($create_table_sql);

// Insert default FAQs if table is empty
$count_result = $conn->query("SELECT COUNT(*) as count FROM faqs");
$count = $count_result->fetch_assoc()['count'];

if ($count == 0) {
    $default_faqs = [
        ['How do I create a property listing?', 'Go to your dashboard and click "Add New Property". Fill in all the required details including photos, amenities, and pricing information.', 'general', 1],
        ['What payment methods are accepted?', 'We accept GCash, PayMaya, and Bank Transfer. You can choose your preferred payment method during the rental application process.', 'payment', 2],
        ['How does the price prediction work?', 'Our AI analyzes property features like size, location, amenities, and market trends to suggest competitive pricing for your listings.', 'pricing', 3],
        ['Can I edit my property listing?', 'Yes, you can edit your listings anytime from your dashboard. Changes will be reviewed and updated within 24 hours.', 'general', 4],
        ['What happens after I submit a rental request?', 'Your request will be sent to the property owner who will review and respond within 48 hours. You can track the status in your dashboard.', 'rental', 5],
        ['How do I contact a property owner?', 'Use the "Message Owner" button on any property listing to start a conversation. You can also use quick reply options for common questions.', 'communication', 6],
        ['What documents do I need to rent a property?', 'You typically need a valid ID, proof of income, and sometimes a security deposit. Specific requirements may vary by property.', 'rental', 7],
        ['How do I cancel a rental request?', 'You can cancel pending requests from your dashboard. Once approved, cancellation policies depend on the property owner.', 'rental', 8]
    ];
    
    $stmt = $conn->prepare("INSERT INTO faqs (question, answer, category, display_order) VALUES (?, ?, ?, ?)");
    foreach ($default_faqs as $faq) {
        $stmt->bind_param("sssi", $faq[0], $faq[1], $faq[2], $faq[3]);
        $stmt->execute();
    }
    $stmt->close();
}

// Fetch all FAQs
$stmt = $conn->prepare("SELECT * FROM faqs ORDER BY display_order ASC, id ASC");
$stmt->execute();
$result = $stmt->get_result();
$faqs = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get categories for filter
$categories = array_unique(array_column($faqs, 'category'));
sort($categories);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAQ Management - Admin Dashboard</title>
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
        .faq-card {
            background: var(--bg-secondary);
            border-radius: 10px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            margin-bottom: 20px;
            border-left: 4px solid var(--hb-brown);
        }
        .faq-question {
            font-weight: bold;
            color: var(--hb-brown);
            margin-bottom: 10px;
        }
        .faq-answer {
            color: var(--text-secondary);
            line-height: 1.6;
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
        .table {
            background: var(--bg-secondary);
            color: var(--text-primary);
        }
        .table th {
            background: var(--bg-tertiary);
            border-color: var(--border-color);
            color: var(--text-primary);
        }
        .table td {
            border-color: var(--border-color);
        }
        .table-hover tbody tr:hover {
            background: var(--bg-tertiary);
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <?= getNavigationForRole('admin_faq_management.php') ?>

    <main class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3><i class="bi bi-question-circle"></i> FAQ Management</h3>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFaqModal">
                <i class="bi bi-plus-circle"></i> Add New FAQ
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
                        <h6 class="card-title">Search FAQs</h6>
                        <input type="text" id="searchInput" class="form-control" placeholder="Search questions or answers...">
                    </div>
                </div>
            </div>
        </div>

        <!-- FAQs List -->
        <div id="faqsList">
            <?php foreach ($faqs as $faq): ?>
                <div class="faq-card" data-category="<?= htmlspecialchars($faq['category']) ?>" data-search="<?= strtolower(htmlspecialchars($faq['question'] . ' ' . $faq['answer'])) ?>">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="category-badge"><?= htmlspecialchars(ucfirst($faq['category'])) ?></span>
                            <span class="badge bg-secondary">Order: <?= $faq['display_order'] ?></span>
                            <?php if (!$faq['is_active']): ?>
                                <span class="badge bg-danger">Inactive</span>
                            <?php endif; ?>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary" onclick="editFaq(<?= htmlspecialchars(json_encode($faq)) ?>)">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <button class="btn btn-outline-danger" onclick="deleteFaq(<?= $faq['id'] ?>, '<?= htmlspecialchars($faq['question']) ?>')">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                    <div class="faq-question"><?= htmlspecialchars($faq['question']) ?></div>
                    <div class="faq-answer"><?= nl2br(htmlspecialchars($faq['answer'])) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- No Results Message -->
        <div id="noResults" class="text-center py-5" style="display: none;">
            <i class="bi bi-search" style="font-size: 3rem; color: var(--text-muted);"></i>
            <h5 class="mt-3 text-muted">No FAQs found</h5>
            <p class="text-muted">Try adjusting your search or filter criteria.</p>
        </div>
    </main>

    <!-- Add FAQ Modal -->
    <div class="modal fade" id="addFaqModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background: var(--bg-secondary); color: var(--text-primary);">
                <div class="modal-header" style="border-color: var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add New FAQ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label class="form-label">Question</label>
                            <textarea name="question" class="form-control" rows="2" required placeholder="Enter the FAQ question..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Answer</label>
                            <textarea name="answer" class="form-control" rows="4" required placeholder="Enter the detailed answer..."></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Category</label>
                                    <select name="category" class="form-select" required>
                                        <option value="general">General</option>
                                        <option value="rental">Rental Process</option>
                                        <option value="payment">Payment</option>
                                        <option value="pricing">Pricing</option>
                                        <option value="communication">Communication</option>
                                        <option value="technical">Technical Support</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Display Order</label>
                                    <input type="number" name="display_order" class="form-control" value="0" min="0">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-color: var(--border-color);">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add FAQ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit FAQ Modal -->
    <div class="modal fade" id="editFaqModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background: var(--bg-secondary); color: var(--text-primary);">
                <div class="modal-header" style="border-color: var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit FAQ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" id="editId">
                        <div class="mb-3">
                            <label class="form-label">Question</label>
                            <textarea name="question" id="editQuestion" class="form-control" rows="2" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Answer</label>
                            <textarea name="answer" id="editAnswer" class="form-control" rows="4" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Category</label>
                                    <select name="category" id="editCategory" class="form-select" required>
                                        <option value="general">General</option>
                                        <option value="rental">Rental Process</option>
                                        <option value="payment">Payment</option>
                                        <option value="pricing">Pricing</option>
                                        <option value="communication">Communication</option>
                                        <option value="technical">Technical Support</option>
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
                    </div>
                    <div class="modal-footer" style="border-color: var(--border-color);">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update FAQ</button>
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
                    <p>Are you sure you want to delete this FAQ?</p>
                    <div class="alert alert-warning">
                        <strong id="deleteQuestion"></strong>
                    </div>
                    <p class="text-muted">This action cannot be undone.</p>
                </div>
                <div class="modal-footer" style="border-color: var(--border-color);">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="deleteId">
                        <button type="submit" class="btn btn-danger">Delete FAQ</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Filter and search functionality
        document.getElementById('categoryFilter').addEventListener('change', filterFaqs);
        document.getElementById('searchInput').addEventListener('input', filterFaqs);

        function filterFaqs() {
            const categoryFilter = document.getElementById('categoryFilter').value.toLowerCase();
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const faqCards = document.querySelectorAll('.faq-card');
            let visibleCount = 0;

            faqCards.forEach(card => {
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

        // Edit FAQ function
        function editFaq(faq) {
            document.getElementById('editId').value = faq.id;
            document.getElementById('editQuestion').value = faq.question;
            document.getElementById('editAnswer').value = faq.answer;
            document.getElementById('editCategory').value = faq.category;
            document.getElementById('editDisplayOrder').value = faq.display_order;
            document.getElementById('editIsActive').checked = faq.is_active == 1;
            
            new bootstrap.Modal(document.getElementById('editFaqModal')).show();
        }

        // Delete FAQ function
        function deleteFaq(id, question) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteQuestion').textContent = question;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
    </script>
    <script src="darkmode.js"></script>
</body>
</html>
