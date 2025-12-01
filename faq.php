<?php
session_start();
require 'mysql_connect.php';
require_once 'includes/navigation.php';

// Create faqs table if it doesn't exist (same as admin)
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

// Fetch active FAQs grouped by category
$stmt = $conn->prepare("SELECT * FROM faqs WHERE is_active = 1 ORDER BY category ASC, display_order ASC, id ASC");
$stmt->execute();
$result = $stmt->get_result();
$faqs = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Group FAQs by category
$faqs_by_category = [];
foreach ($faqs as $faq) {
    $category = $faq['category'];
    if (!isset($faqs_by_category[$category])) {
        $faqs_by_category[$category] = [];
    }
    $faqs_by_category[$category][] = $faq;
}

// Get categories for navigation
$categories = array_keys($faqs_by_category);
sort($categories);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Frequently Asked Questions - HanapBahay</title>
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
        .faq-section {
            background: var(--bg-secondary);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
            border-left: 5px solid var(--hb-brown);
        }
        .faq-category-title {
            color: var(--hb-brown);
            font-weight: bold;
            margin-bottom: 25px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border-color);
        }
        .faq-item {
            background: var(--bg-tertiary);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        .faq-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .faq-question {
            font-weight: bold;
            color: var(--text-primary);
            margin-bottom: 10px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .faq-question:hover {
            color: var(--hb-brown);
        }
        .faq-answer {
            color: var(--text-secondary);
            line-height: 1.6;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
        }
        .faq-toggle {
            color: var(--hb-brown);
            font-size: 1.2rem;
            transition: transform 0.3s ease;
        }
        .faq-toggle.rotated {
            transform: rotate(180deg);
        }
        .category-nav {
            background: var(--bg-secondary);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
        }
        .category-nav a {
            color: var(--text-primary);
            text-decoration: none;
            padding: 8px 16px;
            margin: 4px;
            border-radius: 20px;
            background: var(--bg-tertiary);
            transition: all 0.3s ease;
            display: inline-block;
        }
        .category-nav a:hover, .category-nav a.active {
            background: var(--hb-brown);
            color: var(--text-primary);
        }
        .search-box {
            background: var(--bg-secondary);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
        }
        .form-control {
            background: var(--bg-tertiary);
            border-color: var(--border-color);
            color: var(--text-primary);
        }
        .form-control:focus {
            background: var(--bg-tertiary);
            border-color: var(--hb-brown);
            color: var(--text-primary);
            box-shadow: 0 0 0 0.2rem rgba(139, 69, 19, 0.25);
        }
        .hero-section {
            background: linear-gradient(135deg, var(--hb-brown) 0%, var(--hb-gold) 100%);
            color: white;
            padding: 60px 0;
            margin-bottom: 40px;
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
    <?= getNavigationForRole('faq.php') ?>

    <main class="container py-4">
        <!-- Hero Section -->
        <div class="hero-section text-center">
            <h1 class="display-4 mb-3"><i class="bi bi-question-circle"></i> Frequently Asked Questions</h1>
            <p class="lead">Find answers to common questions about renting properties on HanapBahay</p>
        </div>

        <!-- Search Box -->
        <div class="search-box">
            <div class="row">
                <div class="col-md-8">
                    <div class="input-group">
                        <span class="input-group-text" style="background: var(--bg-tertiary); border-color: var(--border-color); color: var(--text-primary);">
                            <i class="bi bi-search"></i>
                        </span>
                        <input type="text" id="searchInput" class="form-control" placeholder="Search for questions or answers...">
                    </div>
                </div>
                <div class="col-md-4">
                    <button class="btn btn-primary w-100" onclick="clearSearch()">
                        <i class="bi bi-x-circle"></i> Clear Search
                    </button>
                </div>
            </div>
        </div>

        <!-- Category Navigation -->
        <div class="category-nav">
            <h6 class="mb-3"><i class="bi bi-tags"></i> Browse by Category:</h6>
            <a href="#" class="category-link active" data-category="all">All Categories</a>
            <?php foreach ($categories as $category): ?>
                <a href="#" class="category-link" data-category="<?= htmlspecialchars($category) ?>">
                    <?= htmlspecialchars(ucfirst($category)) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- FAQs Content -->
        <div id="faqsContent">
            <?php if (empty($faqs_by_category)): ?>
                <div class="no-results">
                    <i class="bi bi-question-circle"></i>
                    <h4>No FAQs Available</h4>
                    <p>We're working on adding helpful frequently asked questions. Check back soon!</p>
                </div>
            <?php else: ?>
                <?php foreach ($faqs_by_category as $category => $category_faqs): ?>
                    <div class="faq-section" data-category="<?= htmlspecialchars($category) ?>">
                        <h3 class="faq-category-title">
                            <i class="bi bi-<?= $category === 'general' ? 'house' : ($category === 'rental' ? 'key' : ($category === 'payment' ? 'credit-card' : ($category === 'pricing' ? 'currency-dollar' : ($category === 'communication' ? 'chat-dots' : 'wrench')))) ?>"></i>
                            <?= htmlspecialchars(ucfirst($category)) ?> Questions
                        </h3>
                        
                        <?php foreach ($category_faqs as $faq): ?>
                            <div class="faq-item" data-search="<?= strtolower(htmlspecialchars($faq['question'] . ' ' . $faq['answer'])) ?>">
                                <div class="faq-question" onclick="toggleFaq(this)">
                                    <span><?= htmlspecialchars($faq['question']) ?></span>
                                    <i class="bi bi-chevron-down faq-toggle"></i>
                                </div>
                                <div class="faq-answer" style="display: none;">
                                    <?= nl2br(htmlspecialchars($faq['answer'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- No Results Message -->
        <div id="noResults" class="no-results" style="display: none;">
            <i class="bi bi-search"></i>
            <h4>No FAQs Found</h4>
            <p>Try adjusting your search terms or browse by category.</p>
        </div>

        <!-- Contact Section -->
        <div class="faq-section text-center">
            <h3 class="faq-category-title">
                <i class="bi bi-headset"></i> Still Need Help?
            </h3>
            <p class="mb-4">Can't find what you're looking for? We're here to help!</p>
            <div class="row">
                <div class="col-md-4">
                    <div class="faq-item">
                        <h6><i class="bi bi-chat-dots"></i> Live Chat</h6>
                        <p class="small text-muted">Chat with our support team in real-time</p>
                        <button class="btn btn-outline-primary btn-sm">Start Chat</button>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="faq-item">
                        <h6><i class="bi bi-envelope"></i> Email Support</h6>
                        <p class="small text-muted">Send us an email and we'll respond within 24 hours</p>
                        <a href="mailto:support@hanapbahay.com" class="btn btn-outline-primary btn-sm">Send Email</a>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="faq-item">
                        <h6><i class="bi bi-telephone"></i> Phone Support</h6>
                        <p class="small text-muted">Call us during business hours</p>
                        <a href="tel:+639123456789" class="btn btn-outline-primary btn-sm">Call Now</a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // FAQ toggle functionality
        function toggleFaq(element) {
            const answer = element.nextElementSibling;
            const toggle = element.querySelector('.faq-toggle');
            
            if (answer.style.display === 'none' || answer.style.display === '') {
                answer.style.display = 'block';
                toggle.classList.add('rotated');
            } else {
                answer.style.display = 'none';
                toggle.classList.remove('rotated');
            }
        }

        // Category filtering
        document.querySelectorAll('.category-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();

                // Update active state
                document.querySelectorAll('.category-link').forEach(l => l.classList.remove('active'));
                this.classList.add('active');

                const category = this.dataset.category;
                const sections = document.querySelectorAll('.faq-section');

                console.log('Filtering by category:', category);
                
                sections.forEach(section => {
                    const sectionCategory = section.dataset.category;
                    console.log('Checking section:', sectionCategory, 'against:', category);
                    
                    if (category === 'all' || sectionCategory === category) {
                        section.style.display = 'block';
                        console.log('Showing section:', sectionCategory);
                    } else {
                        section.style.display = 'none';
                        console.log('Hiding section:', sectionCategory);
                    }
                });

                // Clear search when switching categories
                document.getElementById('searchInput').value = '';
                // Don't call filterFaqs() here as it might interfere with category filtering
            });
        });

        // Search functionality
        document.getElementById('searchInput').addEventListener('input', filterFaqs);

        function filterFaqs() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const faqItems = document.querySelectorAll('.faq-item');
            const sections = document.querySelectorAll('.faq-section');
            let hasResults = false;

            sections.forEach(section => {
                let sectionHasResults = false;
                
                section.querySelectorAll('.faq-item').forEach(item => {
                    const searchText = item.dataset.search;
                    
                    if (!searchTerm || searchText.includes(searchTerm)) {
                        item.style.display = 'block';
                        sectionHasResults = true;
                        hasResults = true;
                    } else {
                        item.style.display = 'none';
                    }
                });
                
                // Show/hide section based on whether it has results
                if (sectionHasResults) {
                    section.style.display = 'block';
                } else if (searchTerm) {
                    section.style.display = 'none';
                }
            });

            // Show/hide no results message
            const noResults = document.getElementById('noResults');
            if (!hasResults && searchTerm) {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
        }

        function clearSearch() {
            document.getElementById('searchInput').value = '';
            document.querySelectorAll('.category-link').forEach(l => l.classList.remove('active'));
            document.querySelector('.category-link[data-category="all"]').classList.add('active');

            // Show all sections and items
            document.querySelectorAll('.faq-section').forEach(section => {
                section.style.display = 'block';
                section.querySelectorAll('.faq-item').forEach(item => {
                    item.style.display = 'block';
                });
            });

            document.getElementById('noResults').style.display = 'none';
        }

        // Debug function to check category filtering
        function debugCategories() {
            console.log('Available categories:');
            document.querySelectorAll('.category-link').forEach(link => {
                console.log('Link category:', link.dataset.category);
            });
            console.log('Available sections:');
            document.querySelectorAll('.faq-section').forEach(section => {
                console.log('Section category:', section.dataset.category);
            });
        }

        // Call debug function on page load
        window.addEventListener('load', function() {
            debugCategories();
        });

        // Auto-expand FAQ if URL has hash
        window.addEventListener('load', function() {
            const hash = window.location.hash;
            if (hash) {
                const targetElement = document.querySelector(hash);
                if (targetElement && targetElement.classList.contains('faq-question')) {
                    toggleFaq(targetElement);
                    targetElement.scrollIntoView({ behavior: 'smooth' });
                }
            }
        });
    </script>
    <script src="darkmode.js"></script>
</body>
</html>
