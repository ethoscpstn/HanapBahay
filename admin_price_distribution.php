<?php
session_start();
require 'mysql_connect.php';
require_once 'includes/navigation.php';

// Only admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginModule.php");
    exit();
}

// Fetch analytics data
$stmt = $conn->prepare("
    SELECT id, title, address, price, capacity, bedroom, unit_sqm, kitchen, kitchen_type,
           gender_specific, pets, created_at, is_available
    FROM tblistings
    WHERE is_verified = 1 AND is_archived = 0
    ORDER BY created_at DESC
");
$stmt->execute();
$result = $stmt->get_result();
$listings = [];
$price_data = [];
$location_stats = [];
$property_type_stats = [];
$total_listings = 0;
$total_overpriced = 0;
$total_underpriced = 0;
$total_fair = 0;

// Environment detection
$is_localhost = ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1');
$api_url = $is_localhost
    ? 'http://localhost/public_html/api/ml_suggest_price.php'
    : 'https://' . $_SERVER['HTTP_HOST'] . '/api/ml_suggest_price.php';

while ($row = $result->fetch_assoc()) {
    // Derive property type from title
    $title_lower = strtolower($row['title']);
    if (strpos($title_lower, 'studio') !== false) {
        $property_type = 'Studio';
    } elseif (strpos($title_lower, 'apartment') !== false) {
        $property_type = 'Apartment';
    } elseif (strpos($title_lower, 'condo') !== false) {
        $property_type = 'Condo';
    } elseif (strpos($title_lower, 'house') !== false) {
        $property_type = 'House';
    } elseif (strpos($title_lower, 'room') !== false) {
        $property_type = 'Room';
    } else {
        $property_type = 'Apartment';
    }

    // Prepare ML input
    $ml_input = [
        'Capacity' => (int)$row['capacity'],
        'Bedroom' => (int)($row['bedroom'] ?? 1),
        'unit_sqm' => (float)($row['unit_sqm'] ?? 20),
        'cap_per_bedroom' => (int)$row['capacity'] / max(1, (int)($row['bedroom'] ?? 1)),
        'Type' => $property_type,
        'Kitchen' => ($row['kitchen'] ?? 'Yes'),
        'Kitchen type' => ($row['kitchen_type'] ?? 'Private'),
        'Gender specific' => ($row['gender_specific'] ?? 'Mixed'),
        'Pets' => ($row['pets'] ?? 'Allowed'),
        'Location' => 'Quezon City'
    ];

    // Call ML API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['inputs' => [$ml_input]]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $ml_response = curl_exec($ch);
    curl_close($ch);

    $ml_prediction = null;
    if ($ml_response) {
        $ml_data = json_decode($ml_response, true);
        if (isset($ml_data['prediction'])) {
            $ml_prediction = $ml_data['prediction'];
        }
    }

    $actual_price = (float)$row['price'];
    $row['ml_prediction'] = $ml_prediction;
    $row['property_type'] = $property_type;

    if ($ml_prediction) {
        $diff_percent = (($actual_price - $ml_prediction) / $ml_prediction) * 100;
        $row['diff_percent'] = $diff_percent;

        // Categorize pricing
        if ($diff_percent > 10) {
            $row['price_category'] = 'overpriced';
            $total_overpriced++;
        } elseif ($diff_percent < -10) {
            $row['price_category'] = 'underpriced';
            $total_underpriced++;
        } else {
            $row['price_category'] = 'fair';
            $total_fair++;
        }

        // Aggregate stats by property type
        if (!isset($property_type_stats[$property_type])) {
            $property_type_stats[$property_type] = [
                'count' => 0,
                'total_actual' => 0,
                'total_predicted' => 0
            ];
        }
        $property_type_stats[$property_type]['count']++;
        $property_type_stats[$property_type]['total_actual'] += $actual_price;
        $property_type_stats[$property_type]['total_predicted'] += $ml_prediction;

        $total_listings++;
    }

    $listings[] = $row;
}
$stmt->close();

// Calculate averages for property types
foreach ($property_type_stats as $type => &$stats) {
    $stats['avg_actual'] = $stats['count'] > 0 ? $stats['total_actual'] / $stats['count'] : 0;
    $stats['avg_predicted'] = $stats['count'] > 0 ? $stats['total_predicted'] / $stats['count'] : 0;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Price Distribution Analytics - Admin Dashboard</title>
    <link rel="icon" type="image/png" sizes="16x16" href="Assets/HanapBahayTablogo.png?v=2">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="darkmode.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        body { 
            background: var(--bg-primary);
            color: var(--text-primary);
        }
        .topbar { 
            background: var(--hb-brown);
            color: var(--text-primary);
        }
        .chart-container {
            background: var(--bg-secondary);
            border-radius: 15px;
            padding: 30px;
            box-shadow: var(--card-shadow);
            margin-bottom: 30px;
            position: relative;
            height: 500px;
            color: var(--text-primary);
        }
        .stat-card {
            background: var(--bg-secondary);
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--card-shadow);
            border-left: 5px solid var(--hb-brown);
            color: var(--text-primary);
            margin-bottom: 20px;
        }
        .metric-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--hb-brown);
        }
        .metric-label {
            font-size: 1rem;
            color: var(--text-muted);
            margin-top: 10px;
        }
        .hero-section {
            background: linear-gradient(135deg, var(--hb-brown) 0%, var(--hb-gold) 100%);
            color: white;
            padding: 40px 0;
            margin-bottom: 30px;
            border-radius: 15px;
        }
        .navigation-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .nav-card {
            background: var(--bg-secondary);
            border-radius: 15px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            text-decoration: none;
            color: var(--text-primary);
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        .nav-card:hover {
            transform: translateY(-5px);
            border-color: var(--hb-brown);
            color: var(--text-primary);
        }
        .nav-card.active {
            border-color: var(--hb-brown);
            background: var(--bg-tertiary);
        }
        .nav-card i {
            font-size: 2rem;
            color: var(--hb-brown);
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <?= getNavigationForRole('admin_price_distribution.php') ?>

    <main class="container py-4">
        <!-- Hero Section -->
        <div class="hero-section text-center">
            <h1 class="display-5 mb-3"><i class="bi bi-pie-chart"></i> Price Distribution Analytics</h1>
            <p class="lead">Detailed analysis of rental price distribution across the market</p>
        </div>

        <!-- Navigation Cards -->
        <div class="navigation-cards">
            <a href="admin_price_distribution.php" class="nav-card active">
                <i class="bi bi-pie-chart"></i>
                <h5>Price Distribution</h5>
                <p class="small text-muted">Pie chart showing market price categories</p>
            </a>
            <a href="admin_property_type_analytics.php" class="nav-card">
                <i class="bi bi-bar-chart"></i>
                <h5>Property Type Analysis</h5>
                <p class="small text-muted">Bar chart comparing property types</p>
            </a>
            <a href="admin_market_overview.php" class="nav-card">
                <i class="bi bi-graph-up"></i>
                <h5>Market Overview</h5>
                <p class="small text-muted">Overall market trends and statistics</p>
            </a>
            <a href="admin_price_analytics.php" class="nav-card">
                <i class="bi bi-grid-3x3"></i>
                <h5>Combined View</h5>
                <p class="small text-muted">All analytics in one dashboard</p>
            </a>
        </div>

        <!-- Summary Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <div class="metric-value"><?= $total_listings ?></div>
                    <div class="metric-label">Total Listings Analyzed</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <div class="metric-value text-success"><?= $total_underpriced ?></div>
                    <div class="metric-label">Below Market (>10%)</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <div class="metric-value text-info"><?= $total_fair ?></div>
                    <div class="metric-label">Fair Pricing (±10%)</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <div class="metric-value text-warning"><?= $total_overpriced ?></div>
                    <div class="metric-label">Above Market (>10%)</div>
                </div>
            </div>
        </div>

        <!-- Price Distribution Chart -->
        <div class="chart-container">
            <h3 class="mb-4"><i class="bi bi-pie-chart"></i> Price Distribution Analysis</h3>
            <div style="position: relative; height: 400px;">
                <canvas id="priceDistributionChart"></canvas>
            </div>
            <div class="mt-4">
                <h5>Market Insights</h5>
                <div class="row">
                    <div class="col-md-4">
                        <div class="alert alert-success">
                            <h6><i class="bi bi-check-circle"></i> Below Market (<?= $total_underpriced ?>)</h6>
                            <p class="small mb-0">Properties priced more than 10% below ML prediction. These may represent good deals for tenants.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="alert alert-info">
                            <h6><i class="bi bi-info-circle"></i> Fair Pricing (<?= $total_fair ?>)</h6>
                            <p class="small mb-0">Properties within ±10% of ML prediction. These represent market-appropriate pricing.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="alert alert-warning">
                            <h6><i class="bi bi-exclamation-triangle"></i> Above Market (<?= $total_overpriced ?>)</h6>
                            <p class="small mb-0">Properties priced more than 10% above ML prediction. These may have difficulty attracting tenants.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Breakdown -->
        <div class="chart-container">
            <h3 class="mb-4"><i class="bi bi-list-ul"></i> Detailed Price Category Breakdown</h3>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Price Category</th>
                            <th>Count</th>
                            <th>Percentage</th>
                            <th>Average Listed Price</th>
                            <th>Average ML Predicted</th>
                            <th>Market Variance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $categories = [
                            'underpriced' => ['Below Market', $total_underpriced, 'success'],
                            'fair' => ['Fair Pricing', $total_fair, 'info'],
                            'overpriced' => ['Above Market', $total_overpriced, 'warning']
                        ];
                        
                        foreach ($categories as $key => $data):
                            $count = $data[1];
                            $percentage = $total_listings > 0 ? ($count / $total_listings) * 100 : 0;
                            
                            // Calculate averages for this category
                            $category_listings = array_filter($listings, function($listing) use ($key) {
                                return isset($listing['price_category']) && $listing['price_category'] === $key;
                            });
                            
                            $avg_listed = 0;
                            $avg_predicted = 0;
                            if (!empty($category_listings)) {
                                $total_listed = array_sum(array_column($category_listings, 'price'));
                                $total_predicted = array_sum(array_column($category_listings, 'ml_prediction'));
                                $avg_listed = $total_listed / count($category_listings);
                                $avg_predicted = $total_predicted / count($category_listings);
                            }
                            
                            $variance = $avg_predicted > 0 ? (($avg_listed - $avg_predicted) / $avg_predicted) * 100 : 0;
                        ?>
                            <tr>
                                <td>
                                    <span class="badge bg-<?= $data[2] ?>"><?= $data[0] ?></span>
                                </td>
                                <td><strong><?= $count ?></strong></td>
                                <td><?= number_format($percentage, 1) ?>%</td>
                                <td>₱<?= number_format($avg_listed, 2) ?></td>
                                <td>₱<?= number_format($avg_predicted, 2) ?></td>
                                <td class="<?= $variance > 0 ? 'text-warning' : ($variance < 0 ? 'text-success' : 'text-info') ?>">
                                    <strong><?= $variance > 0 ? '+' : '' ?><?= number_format($variance, 1) ?>%</strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Dark mode detection helper
        function isDarkMode() {
            return document.documentElement.getAttribute('data-theme') === 'dark';
        }

        function getChartColors() {
            const dark = isDarkMode();
            return {
                textColor: dark ? '#f9fafb' : '#1f2937',
                gridColor: dark ? '#374151' : '#e5e7eb',
                brown: dark ? '#d97706' : '#8B4513',
                gold: dark ? '#fbbf24' : '#F1C64F',
                green: dark ? '#10b981' : '#28a745',
                blue: dark ? '#3b82f6' : '#007bff',
                orange: dark ? '#f59e0b' : '#ffc107',
                cyan: dark ? '#06b6d4' : '#17a2b8'
            };
        }

        // Price Distribution Pie Chart
        const priceDistCtx = document.getElementById('priceDistributionChart');
        if (priceDistCtx) {
            const colors = getChartColors();
            new Chart(priceDistCtx, {
                type: 'pie',
                data: {
                    labels: ['Below Market', 'Fair Price', 'Above Market'],
                    datasets: [{
                        data: [<?= $total_underpriced ?>, <?= $total_fair ?>, <?= $total_overpriced ?>],
                        backgroundColor: [colors.green, colors.cyan, colors.orange],
                        borderWidth: 2,
                        borderColor: dark ? '#374151' : '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                font: {
                                    size: 14,
                                    weight: 'bold'
                                },
                                color: colors.textColor,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            backgroundColor: dark ? '#374151' : '#ffffff',
                            titleColor: colors.textColor,
                            bodyColor: colors.textColor,
                            borderColor: colors.borderColor,
                            borderWidth: 1,
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }

        // Listen for theme changes and update charts
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'data-theme') {
                    // Recreate charts with new colors
                    location.reload();
                }
            });
        });
        observer.observe(document.documentElement, { attributes: true });
    </script>
    <script src="darkmode.js"></script>
</body>
</html>
