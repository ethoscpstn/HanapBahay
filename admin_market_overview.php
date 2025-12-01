<?php
session_start();
require 'mysql_connect.php';
require_once 'includes/navigation.php';

// Only admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginModule.php");
    exit();
}

// Fetch comprehensive analytics data
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
$monthly_stats = [];
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

        // Monthly stats
        $month = date('Y-m', strtotime($row['created_at']));
        if (!isset($monthly_stats[$month])) {
            $monthly_stats[$month] = [
                'count' => 0,
                'total_actual' => 0,
                'total_predicted' => 0
            ];
        }
        $monthly_stats[$month]['count']++;
        $monthly_stats[$month]['total_actual'] += $actual_price;
        $monthly_stats[$month]['total_predicted'] += $ml_prediction;

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

// Calculate monthly averages
foreach ($monthly_stats as $month => &$stats) {
    $stats['avg_actual'] = $stats['count'] > 0 ? $stats['total_actual'] / $stats['count'] : 0;
    $stats['avg_predicted'] = $stats['count'] > 0 ? $stats['total_predicted'] / $stats['count'] : 0;
}

// Overall statistics
$overall_avg_actual = 0;
$overall_avg_predicted = 0;
$count = 0;
foreach ($listings as $listing) {
    if ($listing['ml_prediction']) {
        $overall_avg_actual += (float)$listing['price'];
        $overall_avg_predicted += $listing['ml_prediction'];
        $count++;
    }
}
$overall_avg_actual = $count > 0 ? $overall_avg_actual / $count : 0;
$overall_avg_predicted = $count > 0 ? $overall_avg_predicted / $count : 0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Market Overview Analytics - Admin Dashboard</title>
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
        .market-insight {
            background: var(--bg-tertiary);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid var(--hb-gold);
        }
        .trend-indicator {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        .trend-up {
            background: #d4edda;
            color: #155724;
        }
        .trend-down {
            background: #f8d7da;
            color: #721c24;
        }
        .trend-stable {
            background: #d1ecf1;
            color: #0c5460;
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <?= getNavigationForRole('admin_market_overview.php') ?>

    <main class="container py-4">
        <!-- Hero Section -->
        <div class="hero-section text-center">
            <h1 class="display-5 mb-3"><i class="bi bi-graph-up"></i> Market Overview Analytics</h1>
            <p class="lead">Comprehensive market trends and performance metrics</p>
        </div>

        <!-- Navigation Cards -->
        <div class="navigation-cards">
            <a href="admin_price_distribution.php" class="nav-card">
                <i class="bi bi-pie-chart"></i>
                <h5>Price Distribution</h5>
                <p class="small text-muted">Pie chart showing market price categories</p>
            </a>
            <a href="admin_property_type_analytics.php" class="nav-card">
                <i class="bi bi-bar-chart"></i>
                <h5>Property Type Analysis</h5>
                <p class="small text-muted">Bar chart comparing property types</p>
            </a>
            <a href="admin_market_overview.php" class="nav-card active">
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

        <!-- Key Metrics -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <div class="metric-value"><?= $total_listings ?></div>
                    <div class="metric-label">Total Listings Analyzed</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <div class="metric-value">₱<?= number_format($overall_avg_actual, 0) ?></div>
                    <div class="metric-label">Average Listed Price</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <div class="metric-value text-primary">₱<?= number_format($overall_avg_predicted, 0) ?></div>
                    <div class="metric-label">Average ML Predicted</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <div class="metric-value <?= (($overall_avg_actual - $overall_avg_predicted) / $overall_avg_predicted) * 100 > 0 ? 'text-warning' : 'text-success' ?>">
                        <?= $overall_avg_predicted > 0 ? number_format((($overall_avg_actual - $overall_avg_predicted) / $overall_avg_predicted) * 100, 1) : 0 ?>%
                    </div>
                    <div class="metric-label">Market Variance</div>
                </div>
            </div>
        </div>

        <!-- Market Trends Chart -->
        <div class="chart-container">
            <h3 class="mb-4"><i class="bi bi-graph-up"></i> Monthly Market Trends</h3>
            <div style="position: relative; height: 400px;">
                <canvas id="marketTrendsChart"></canvas>
            </div>
            <div class="mt-4">
                <h5>Trend Analysis</h5>
                <div class="row">
                    <div class="col-md-4">
                        <div class="market-insight">
                            <h6><i class="bi bi-calendar"></i> Recent Activity</h6>
                            <p class="small mb-0">
                                <?php 
                                $recent_months = array_slice($monthly_stats, -3, 3, true);
                                $total_recent = array_sum(array_column($recent_months, 'count'));
                                echo "Last 3 months: <strong>$total_recent</strong> new listings";
                                ?>
                            </p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="market-insight">
                            <h6><i class="bi bi-arrow-up"></i> Price Trend</h6>
                            <p class="small mb-0">
                                <?php 
                                $months = array_keys($monthly_stats);
                                if (count($months) >= 2) {
                                    $latest = end($monthly_stats);
                                    $previous = prev($monthly_stats);
                                    $trend = $latest['avg_actual'] - $previous['avg_actual'];
                                    $trend_class = $trend > 0 ? 'trend-up' : ($trend < 0 ? 'trend-down' : 'trend-stable');
                                    $trend_text = $trend > 0 ? 'Rising' : ($trend < 0 ? 'Falling' : 'Stable');
                                    echo "<span class='trend-indicator $trend_class'>$trend_text</span> by ₱" . number_format(abs($trend), 0);
                                } else {
                                    echo "<span class='trend-indicator trend-stable'>Insufficient Data</span>";
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="market-insight">
                            <h6><i class="bi bi-graph-up-arrow"></i> Market Health</h6>
                            <p class="small mb-0">
                                <?php 
                                $fair_percentage = $total_listings > 0 ? ($total_fair / $total_listings) * 100 : 0;
                                $health_class = $fair_percentage > 60 ? 'trend-up' : ($fair_percentage > 40 ? 'trend-stable' : 'trend-down');
                                $health_text = $fair_percentage > 60 ? 'Healthy' : ($fair_percentage > 40 ? 'Moderate' : 'Concerning');
                                echo "<span class='trend-indicator $health_class'>$health_text</span> - " . number_format($fair_percentage, 1) . "% fair pricing";
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Overall Market Comparison -->
        <div class="chart-container">
            <h3 class="mb-4"><i class="bi bi-bar-chart-line"></i> Market Performance Overview</h3>
            <div class="row">
                <div class="col-md-6">
                    <div class="metric-box text-center p-4">
                        <div class="metric-label">Average Listed Price</div>
                        <div class="metric-value">₱<?= number_format($overall_avg_actual, 2) ?></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="metric-box text-center p-4">
                        <div class="metric-label">Average ML Predicted Price</div>
                        <div class="metric-value text-primary">₱<?= number_format($overall_avg_predicted, 2) ?></div>
                    </div>
                </div>
            </div>
            <div class="mt-4">
                <div class="alert alert-info">
                    <h6><i class="bi bi-info-circle"></i> Market Variance Analysis</h6>
                    <p class="mb-0">
                        The market shows a variance of 
                        <strong><?= $overall_avg_predicted > 0 ? number_format((($overall_avg_actual - $overall_avg_predicted) / $overall_avg_predicted) * 100, 1) : 0 ?>%</strong>
                        between listed and predicted prices. 
                        <?php if ((($overall_avg_actual - $overall_avg_predicted) / $overall_avg_predicted) * 100 > 5): ?>
                            This suggests the market may be slightly overpriced overall.
                        <?php elseif ((($overall_avg_actual - $overall_avg_predicted) / $overall_avg_predicted) * 100 < -5): ?>
                            This suggests the market may be slightly underpriced overall.
                        <?php else: ?>
                            This indicates a well-balanced market with fair pricing.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Property Type Performance -->
        <div class="chart-container">
            <h3 class="mb-4"><i class="bi bi-list-ul"></i> Property Type Performance Summary</h3>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Property Type</th>
                            <th>Count</th>
                            <th>Avg Listed Price</th>
                            <th>Avg ML Predicted</th>
                            <th>Variance</th>
                            <th>Market Share</th>
                            <th>Performance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($property_type_stats as $type => $stats): ?>
                            <?php
                            $variance = $stats['avg_predicted'] > 0 ? (($stats['avg_actual'] - $stats['avg_predicted']) / $stats['avg_predicted']) * 100 : 0;
                            $variance_class = $variance > 10 ? 'text-warning' : ($variance < -10 ? 'text-success' : 'text-info');
                            $market_share = $total_listings > 0 ? ($stats['count'] / $total_listings) * 100 : 0;
                            $performance_class = $variance > 10 ? 'trend-down' : ($variance < -10 ? 'trend-up' : 'trend-stable');
                            $performance_text = $variance > 10 ? 'Overpriced' : ($variance < -10 ? 'Underpriced' : 'Fair');
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($type) ?></strong></td>
                                <td><?= $stats['count'] ?></td>
                                <td>₱<?= number_format($stats['avg_actual'], 2) ?></td>
                                <td>₱<?= number_format($stats['avg_predicted'], 2) ?></td>
                                <td class="<?= $variance_class ?>">
                                    <strong><?= $variance > 0 ? '+' : '' ?><?= number_format($variance, 1) ?>%</strong>
                                </td>
                                <td><?= number_format($market_share, 1) ?>%</td>
                                <td>
                                    <span class="trend-indicator <?= $performance_class ?>"><?= $performance_text ?></span>
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

        // Market Trends Line Chart
        const marketTrendsCtx = document.getElementById('marketTrendsChart');
        if (marketTrendsCtx) {
            const colors = getChartColors();
            new Chart(marketTrendsCtx, {
                type: 'line',
                data: {
                    labels: [<?php foreach (array_keys($monthly_stats) as $month) echo "'$month',"; ?>],
                    datasets: [
                        {
                            label: 'Listed Price',
                            data: [<?php foreach ($monthly_stats as $stats) echo $stats['avg_actual'] . ','; ?>],
                            borderColor: colors.brown,
                            backgroundColor: colors.brown + '20',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: colors.brown,
                            pointBorderColor: colors.brown,
                            pointRadius: 6,
                            pointHoverRadius: 8
                        },
                        {
                            label: 'ML Predicted',
                            data: [<?php foreach ($monthly_stats as $stats) echo $stats['avg_predicted'] . ','; ?>],
                            borderColor: colors.blue,
                            backgroundColor: colors.blue + '20',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: colors.blue,
                            pointBorderColor: colors.blue,
                            pointRadius: 6,
                            pointHoverRadius: 8
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
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
                                    const label = context.dataset.label || '';
                                    const value = context.parsed.y;
                                    return `${label}: ₱${value.toLocaleString()}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toLocaleString();
                                },
                                color: colors.textColor,
                                font: {
                                    size: 12
                                }
                            },
                            grid: {
                                display: true,
                                color: colors.gridColor
                            }
                        },
                        x: {
                            ticks: {
                                color: colors.textColor,
                                font: {
                                    size: 12
                                }
                            },
                            grid: {
                                display: false
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
