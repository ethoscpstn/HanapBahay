<?php
session_start();
require 'mysql_connect.php';
require_once 'includes/navigation.php';

// Only admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginModule.php");
    exit();
}

// Fetch analytics data (same as previous file)
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
$property_type_stats = [];

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
        // Aggregate stats by property type
        if (!isset($property_type_stats[$property_type])) {
            $property_type_stats[$property_type] = [
                'count' => 0,
                'total_actual' => 0,
                'total_predicted' => 0,
                'min_actual' => PHP_FLOAT_MAX,
                'max_actual' => 0,
                'min_predicted' => PHP_FLOAT_MAX,
                'max_predicted' => 0
            ];
        }
        $property_type_stats[$property_type]['count']++;
        $property_type_stats[$property_type]['total_actual'] += $actual_price;
        $property_type_stats[$property_type]['total_predicted'] += $ml_prediction;
        
        // Track min/max values
        $property_type_stats[$property_type]['min_actual'] = min($property_type_stats[$property_type]['min_actual'], $actual_price);
        $property_type_stats[$property_type]['max_actual'] = max($property_type_stats[$property_type]['max_actual'], $actual_price);
        $property_type_stats[$property_type]['min_predicted'] = min($property_type_stats[$property_type]['min_predicted'], $ml_prediction);
        $property_type_stats[$property_type]['max_predicted'] = max($property_type_stats[$property_type]['max_predicted'], $ml_prediction);
    }

    $listings[] = $row;
}
$stmt->close();

// Calculate averages and other metrics for property types
foreach ($property_type_stats as $type => &$stats) {
    $stats['avg_actual'] = $stats['count'] > 0 ? $stats['total_actual'] / $stats['count'] : 0;
    $stats['avg_predicted'] = $stats['count'] > 0 ? $stats['total_predicted'] / $stats['count'] : 0;
    $stats['variance'] = $stats['avg_predicted'] > 0 ? (($stats['avg_actual'] - $stats['avg_predicted']) / $stats['avg_predicted']) * 100 : 0;
    
    // Reset min values if no data
    if ($stats['min_actual'] === PHP_FLOAT_MAX) $stats['min_actual'] = 0;
    if ($stats['min_predicted'] === PHP_FLOAT_MAX) $stats['min_predicted'] = 0;
}

// Sort by count descending
uasort($property_type_stats, function($a, $b) {
    return $b['count'] - $a['count'];
});

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Type Analytics - Admin Dashboard</title>
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
        .property-type-card {
            background: var(--bg-tertiary);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid var(--hb-gold);
        }
        .property-type-title {
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--hb-brown);
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <?= getNavigationForRole('admin_property_type_analytics.php') ?>

    <main class="container py-4">
        <!-- Hero Section -->
        <div class="hero-section text-center">
            <h1 class="display-5 mb-3"><i class="bi bi-bar-chart"></i> Property Type Analytics</h1>
            <p class="lead">Detailed analysis of rental prices by property type</p>
        </div>

        <!-- Navigation Cards -->
        <div class="navigation-cards">
            <a href="admin_price_distribution.php" class="nav-card">
                <i class="bi bi-pie-chart"></i>
                <h5>Price Distribution</h5>
                <p class="small text-muted">Pie chart showing market price categories</p>
            </a>
            <a href="admin_property_type_analytics.php" class="nav-card active">
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
                    <div class="metric-value"><?= count($property_type_stats) ?></div>
                    <div class="metric-label">Property Types</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <div class="metric-value"><?= array_sum(array_column($property_type_stats, 'count')) ?></div>
                    <div class="metric-label">Total Listings</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <div class="metric-value"><?= number_format(array_sum(array_column($property_type_stats, 'avg_actual')) / count($property_type_stats), 0) ?></div>
                    <div class="metric-label">Avg Market Price</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <div class="metric-value"><?= number_format(array_sum(array_column($property_type_stats, 'avg_predicted')) / count($property_type_stats), 0) ?></div>
                    <div class="metric-label">Avg ML Predicted</div>
                </div>
            </div>
        </div>

        <!-- Property Type Bar Chart -->
        <div class="chart-container">
            <h3 class="mb-4"><i class="bi bi-bar-chart"></i> Average Prices by Property Type</h3>
            <div style="position: relative; height: 400px;">
                <canvas id="propertyTypeChart"></canvas>
            </div>
            <div class="mt-4">
                <h5>Key Insights</h5>
                <div class="row">
                    <div class="col-md-6">
                        <div class="alert alert-info">
                            <h6><i class="bi bi-info-circle"></i> Most Popular Type</h6>
                            <p class="small mb-0">
                                <?php 
                                $most_popular = array_keys($property_type_stats)[0];
                                $most_popular_count = $property_type_stats[$most_popular]['count'];
                                echo "<strong>$most_popular</strong> with $most_popular_count listings";
                                ?>
                            </p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="alert alert-success">
                            <h6><i class="bi bi-check-circle"></i> Best Value Type</h6>
                            <p class="small mb-0">
                                <?php 
                                $best_value = null;
                                $best_variance = PHP_FLOAT_MAX;
                                foreach ($property_type_stats as $type => $stats) {
                                    if (abs($stats['variance']) < abs($best_variance)) {
                                        $best_variance = $stats['variance'];
                                        $best_value = $type;
                                    }
                                }
                                echo "<strong>$best_value</strong> (closest to ML prediction)";
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Property Type Breakdown -->
        <div class="chart-container">
            <h3 class="mb-4"><i class="bi bi-list-ul"></i> Detailed Property Type Analysis</h3>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Property Type</th>
                            <th>Count</th>
                            <th>Avg Listed Price</th>
                            <th>Avg ML Predicted</th>
                            <th>Price Range (Listed)</th>
                            <th>Price Range (Predicted)</th>
                            <th>Variance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($property_type_stats as $type => $stats): ?>
                            <?php
                            $variance_class = $stats['variance'] > 10 ? 'text-warning' : ($stats['variance'] < -10 ? 'text-success' : 'text-info');
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($type) ?></strong></td>
                                <td><?= $stats['count'] ?></td>
                                <td>₱<?= number_format($stats['avg_actual'], 2) ?></td>
                                <td>₱<?= number_format($stats['avg_predicted'], 2) ?></td>
                                <td>₱<?= number_format($stats['min_actual'], 0) ?> - ₱<?= number_format($stats['max_actual'], 0) ?></td>
                                <td>₱<?= number_format($stats['min_predicted'], 0) ?> - ₱<?= number_format($stats['max_predicted'], 0) ?></td>
                                <td class="<?= $variance_class ?>">
                                    <strong><?= $stats['variance'] > 0 ? '+' : '' ?><?= number_format($stats['variance'], 1) ?>%</strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Individual Property Type Cards -->
        <div class="chart-container">
            <h3 class="mb-4"><i class="bi bi-grid-3x3"></i> Property Type Breakdown</h3>
            <div class="row">
                <?php foreach ($property_type_stats as $type => $stats): ?>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="property-type-card">
                            <div class="property-type-title"><?= htmlspecialchars($type) ?></div>
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="small text-muted">Listings</div>
                                    <div class="h5 mb-0"><?= $stats['count'] ?></div>
                                </div>
                                <div class="col-6">
                                    <div class="small text-muted">Avg Price</div>
                                    <div class="h5 mb-0">₱<?= number_format($stats['avg_actual'], 0) ?></div>
                                </div>
                            </div>
                            <div class="mt-2">
                                <div class="small text-muted">ML Predicted: ₱<?= number_format($stats['avg_predicted'], 0) ?></div>
                                <div class="small <?= $stats['variance'] > 0 ? 'text-warning' : ($stats['variance'] < 0 ? 'text-success' : 'text-info') ?>">
                                    Variance: <?= $stats['variance'] > 0 ? '+' : '' ?><?= number_format($stats['variance'], 1) ?>%
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
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

        // Property Type Bar Chart
        const propertyTypeCtx = document.getElementById('propertyTypeChart');
        if (propertyTypeCtx) {
            const colors = getChartColors();
            new Chart(propertyTypeCtx, {
                type: 'bar',
                data: {
                    labels: [<?php foreach (array_keys($property_type_stats) as $type) echo "'$type',"; ?>],
                    datasets: [
                        {
                            label: 'Listed Price',
                            data: [<?php foreach ($property_type_stats as $stats) echo $stats['avg_actual'] . ','; ?>],
                            backgroundColor: colors.brown,
                            borderWidth: 0,
                            borderRadius: 8,
                            borderSkipped: false,
                        },
                        {
                            label: 'ML Predicted',
                            data: [<?php foreach ($property_type_stats as $stats) echo $stats['avg_predicted'] . ','; ?>],
                            backgroundColor: colors.blue,
                            borderWidth: 0,
                            borderRadius: 8,
                            borderSkipped: false,
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
