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

// Fetch owner's listings for analysis
$stmt = $conn->prepare("
    SELECT id, title, address, price, capacity, bedroom, unit_sqm, kitchen, kitchen_type,
           gender_specific, pets, amenities, is_available, created_at, verification_status
    FROM tblistings
    WHERE owner_id = ? AND is_deleted = 0
    ORDER BY created_at DESC
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$result = $stmt->get_result();
$listings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Generate comprehensive recommendations
$recommendations = [];

// 1. Pricing Recommendations
$overpriced_count = 0;
$underpriced_count = 0;
$fair_priced_count = 0;

foreach ($listings as $listing) {
    if ($listing['verification_status'] === 'approved' || $listing['verification_status'] === null) {
        // Simple pricing analysis based on capacity and location
        $base_price_per_person = 3000; // Base rate per person
        $expected_price = $listing['capacity'] * $base_price_per_person;
        $actual_price = (float)$listing['price'];
        
        $price_diff_percent = (($actual_price - $expected_price) / $expected_price) * 100;
        
        if ($price_diff_percent > 20) {
            $overpriced_count++;
        } elseif ($price_diff_percent < -20) {
            $underpriced_count++;
        } else {
            $fair_priced_count++;
        }
    }
}

if ($overpriced_count > 0) {
    $recommendations[] = [
        'type' => 'pricing',
        'severity' => 'high',
        'title' => 'Overpriced Listings Detected',
        'message' => "You have $overpriced_count listing(s) that may be priced too high. Consider reducing prices by 10-15% to attract more tenants.",
        'action' => 'Review and adjust pricing',
        'icon' => 'exclamation-triangle',
        'color' => 'danger'
    ];
}

if ($underpriced_count > 0) {
    $recommendations[] = [
        'type' => 'pricing',
        'severity' => 'medium',
        'title' => 'Underpriced Opportunities',
        'message' => "You have $underpriced_count listing(s) that may be underpriced. Consider increasing prices by 5-10% to maximize revenue.",
        'action' => 'Review pricing strategy',
        'icon' => 'arrow-up-circle',
        'color' => 'warning'
    ];
}

// 2. Availability Recommendations
$unavailable_count = 0;
$available_count = 0;

foreach ($listings as $listing) {
    if ($listing['is_available'] == 0) {
        $unavailable_count++;
    } else {
        $available_count++;
    }
}

if ($unavailable_count > $available_count) {
    $recommendations[] = [
        'type' => 'availability',
        'severity' => 'medium',
        'title' => 'Low Availability',
        'message' => "Most of your listings are currently unavailable. Consider making more properties available to increase rental opportunities.",
        'action' => 'Update property availability',
        'icon' => 'house-check',
        'color' => 'info'
    ];
}

// 3. Amenities Recommendations
$amenities_analysis = [];
foreach ($listings as $listing) {
    $amenities = $listing['amenities'] ? explode(',', $listing['amenities']) : [];
    $amenities_count = count($amenities);
    
    if ($amenities_count < 3) {
        $amenities_analysis[] = $listing['title'];
    }
}

if (count($amenities_analysis) > 0) {
    $recommendations[] = [
        'type' => 'amenities',
        'severity' => 'low',
        'title' => 'Enhance Property Amenities',
        'message' => "Some properties have limited amenities. Adding more amenities can justify higher prices and attract quality tenants.",
        'action' => 'Add more amenities',
        'icon' => 'plus-circle',
        'color' => 'success'
    ];
}

// 4. Market Competition Recommendations
$total_listings = count($listings);
if ($total_listings < 3) {
    $recommendations[] = [
        'type' => 'portfolio',
        'severity' => 'medium',
        'title' => 'Expand Your Portfolio',
        'message' => "You have a small portfolio of $total_listings properties. Consider adding more listings to increase your rental income potential.",
        'action' => 'Add more properties',
        'icon' => 'building-add',
        'color' => 'primary'
    ];
}

// 5. Seasonal Recommendations
$current_month = (int)date('n');
if ($current_month >= 5 && $current_month <= 8) {
    $recommendations[] = [
        'type' => 'seasonal',
        'severity' => 'low',
        'title' => 'Peak Rental Season',
        'message' => "It's peak rental season! This is the best time to list new properties and adjust prices upward by 5-10%.",
        'action' => 'Optimize for peak season',
        'icon' => 'sun',
        'color' => 'warning'
    ];
} elseif ($current_month >= 11 || $current_month <= 2) {
    $recommendations[] = [
        'type' => 'seasonal',
        'severity' => 'medium',
        'title' => 'Low Season Strategy',
        'message' => "It's low rental season. Consider offering incentives like free utilities or flexible lease terms to attract tenants.",
        'action' => 'Adjust seasonal strategy',
        'icon' => 'snow',
        'color' => 'info'
    ];
}

// 6. Verification Status Recommendations
$pending_count = 0;
$rejected_count = 0;

foreach ($listings as $listing) {
    if ($listing['verification_status'] === 'pending') {
        $pending_count++;
    } elseif ($listing['verification_status'] === 'rejected') {
        $rejected_count++;
    }
}

if ($pending_count > 0) {
    $recommendations[] = [
        'type' => 'verification',
        'severity' => 'high',
        'title' => 'Pending Verifications',
        'message' => "You have $pending_count listing(s) pending verification. Contact admin to expedite the approval process.",
        'action' => 'Follow up on verification',
        'icon' => 'clock-history',
        'color' => 'warning'
    ];
}

if ($rejected_count > 0) {
    $recommendations[] = [
        'type' => 'verification',
        'severity' => 'high',
        'title' => 'Rejected Listings',
        'message' => "You have $rejected_count listing(s) that were rejected. Review the rejection reasons and resubmit with corrections.",
        'action' => 'Resubmit rejected listings',
        'icon' => 'x-circle',
        'color' => 'danger'
    ];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Recommendations - Owner Dashboard</title>
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
        .hero-section {
            background: linear-gradient(135deg, var(--hb-brown) 0%, var(--hb-gold) 100%);
            color: white;
            padding: 40px 0;
            margin-bottom: 30px;
            border-radius: 15px;
        }
        .recommendation-card {
            background: var(--bg-secondary);
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--card-shadow);
            margin-bottom: 20px;
            border-left: 5px solid var(--hb-brown);
            transition: all 0.3s ease;
        }
        .recommendation-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .recommendation-card.high-priority {
            border-left-color: var(--danger);
            background: linear-gradient(135deg, var(--bg-secondary) 0%, rgba(220, 53, 69, 0.05) 100%);
        }
        .recommendation-card.medium-priority {
            border-left-color: var(--warning);
            background: linear-gradient(135deg, var(--bg-secondary) 0%, rgba(255, 193, 7, 0.05) 100%);
        }
        .recommendation-card.low-priority {
            border-left-color: var(--success);
            background: linear-gradient(135deg, var(--bg-secondary) 0%, rgba(25, 135, 84, 0.05) 100%);
        }
        .recommendation-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 15px;
        }
        .recommendation-icon.danger {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
        }
        .recommendation-icon.warning {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning);
        }
        .recommendation-icon.info {
            background: rgba(13, 202, 240, 0.1);
            color: var(--info);
        }
        .recommendation-icon.success {
            background: rgba(25, 135, 84, 0.1);
            color: var(--success);
        }
        .recommendation-icon.primary {
            background: rgba(13, 110, 253, 0.1);
            color: var(--primary);
        }
        .priority-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        .action-button {
            background: var(--hb-brown);
            border: none;
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        .action-button:hover {
            background: var(--hb-gold);
            color: var(--text-primary);
            transform: translateY(-1px);
        }
        .stats-card {
            background: var(--bg-secondary);
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--card-shadow);
            text-align: center;
            border-left: 5px solid var(--hb-gold);
        }
        .stats-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--hb-brown);
        }
        .stats-label {
            font-size: 1rem;
            color: var(--text-muted);
            margin-top: 10px;
        }
        .filter-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        .filter-btn {
            padding: 8px 16px;
            border-radius: 20px;
            border: 2px solid var(--border-color);
            background: var(--bg-tertiary);
            color: var(--text-primary);
            transition: all 0.3s ease;
        }
        .filter-btn.active {
            background: var(--hb-brown);
            color: white;
            border-color: var(--hb-brown);
        }
        .filter-btn:hover {
            background: var(--hb-gold);
            color: var(--text-primary);
            border-color: var(--hb-gold);
        }
        .no-recommendations {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        .no-recommendations i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: var(--hb-gold);
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <?= getNavigationForRole('owner_recommendations.php') ?>

    <main class="container py-4">
        <!-- Hero Section -->
        <div class="hero-section text-center">
            <h1 class="display-5 mb-3"><i class="bi bi-lightbulb"></i> Property Recommendations</h1>
            <p class="lead">AI-powered insights to optimize your rental properties and maximize revenue</p>
        </div>

        <!-- Quick Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-value"><?= count($listings) ?></div>
                    <div class="stats-label">Total Properties</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-value"><?= count($recommendations) ?></div>
                    <div class="stats-label">Active Recommendations</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-value"><?= count(array_filter($recommendations, function($r) { return $r['severity'] === 'high'; })) ?></div>
                    <div class="stats-label">High Priority</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-value"><?= $available_count ?></div>
                    <div class="stats-label">Available Properties</div>
                </div>
            </div>
        </div>

        <!-- Filter Buttons -->
        <div class="filter-buttons">
            <button class="filter-btn active" data-filter="all">All Recommendations</button>
            <button class="filter-btn" data-filter="high">High Priority</button>
            <button class="filter-btn" data-filter="medium">Medium Priority</button>
            <button class="filter-btn" data-filter="low">Low Priority</button>
            <button class="filter-btn" data-filter="pricing">Pricing</button>
            <button class="filter-btn" data-filter="availability">Availability</button>
            <button class="filter-btn" data-filter="amenities">Amenities</button>
            <button class="filter-btn" data-filter="seasonal">Seasonal</button>
        </div>

        <!-- Recommendations List -->
        <div id="recommendationsList">
            <?php if (empty($recommendations)): ?>
                <div class="no-recommendations">
                    <i class="bi bi-check-circle"></i>
                    <h4>All Set!</h4>
                    <p>No recommendations at this time. Your properties are well-optimized!</p>
                </div>
            <?php else: ?>
                <?php foreach ($recommendations as $rec): ?>
                    <div class="recommendation-card <?= $rec['severity'] ?>-priority" data-severity="<?= $rec['severity'] ?>" data-type="<?= $rec['type'] ?>">
                        <div class="position-relative">
                            <span class="priority-badge bg-<?= $rec['color'] ?> text-white">
                                <?= strtoupper($rec['severity']) ?> PRIORITY
                            </span>
                            
                            <div class="recommendation-icon <?= $rec['color'] ?>">
                                <i class="bi bi-<?= $rec['icon'] ?>"></i>
                            </div>
                            
                            <h4 class="mb-3"><?= htmlspecialchars($rec['title']) ?></h4>
                            <p class="mb-4"><?= htmlspecialchars($rec['message']) ?></p>
                            
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-<?= $rec['color'] ?> me-2"><?= ucfirst($rec['type']) ?></span>
                                    <small class="text-muted">
                                        <i class="bi bi-clock"></i> 
                                        <?php
                                        $time_ago = '';
                                        switch($rec['type']) {
                                            case 'pricing': $time_ago = 'Updated daily'; break;
                                            case 'seasonal': $time_ago = 'Monthly review'; break;
                                            case 'availability': $time_ago = 'Real-time'; break;
                                            default: $time_ago = 'Weekly review'; break;
                                        }
                                        echo $time_ago;
                                        ?>
                                    </small>
                                </div>
                                <button class="action-button" onclick="handleRecommendationAction('<?= $rec['type'] ?>', '<?= $rec['action'] ?>')">
                                    <i class="bi bi-arrow-right"></i> <?= htmlspecialchars($rec['action']) ?>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Action Guide -->
        <div class="recommendation-card">
            <h4 class="mb-3"><i class="bi bi-question-circle"></i> How to Use These Recommendations</h4>
            <div class="row">
                <div class="col-md-4">
                    <div class="d-flex align-items-start mb-3">
                        <div class="recommendation-icon danger me-3">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <div>
                            <h6>High Priority</h6>
                            <p class="small text-muted mb-0">Address these immediately to avoid revenue loss or tenant issues.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-start mb-3">
                        <div class="recommendation-icon warning me-3">
                            <i class="bi bi-info-circle"></i>
                        </div>
                        <div>
                            <h6>Medium Priority</h6>
                            <p class="small text-muted mb-0">Plan to address these within the next week for optimal results.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-start mb-3">
                        <div class="recommendation-icon success me-3">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div>
                            <h6>Low Priority</h6>
                            <p class="small text-muted mb-0">Consider these improvements for long-term optimization.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Filter functionality
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                // Update active state
                document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const filter = this.dataset.filter;
                const cards = document.querySelectorAll('.recommendation-card');
                
                cards.forEach(card => {
                    if (filter === 'all' || 
                        card.dataset.severity === filter || 
                        card.dataset.type === filter) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        });

        // Handle recommendation actions
        function handleRecommendationAction(type, action) {
            switch(type) {
                case 'pricing':
                    window.location.href = 'DashboardUO.php#analytics-content';
                    break;
                case 'availability':
                    window.location.href = 'DashboardUO.php';
                    break;
                case 'amenities':
                    window.location.href = 'DashboardAddUnit.php';
                    break;
                case 'verification':
                    window.location.href = 'DashboardUO.php';
                    break;
                case 'portfolio':
                    window.location.href = 'DashboardAddUnit.php';
                    break;
                case 'seasonal':
                    window.location.href = 'DashboardUO.php#analytics-content';
                    break;
                default:
                    window.location.href = 'DashboardUO.php';
            }
        }

        // Auto-refresh recommendations every 5 minutes
        setInterval(function() {
            // In a real implementation, you might want to refresh the data
            console.log('Recommendations refreshed');
        }, 300000); // 5 minutes
    </script>
    <script src="darkmode.js"></script>
</body>
</html>
