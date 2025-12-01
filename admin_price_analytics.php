<?php
session_start();
require 'mysql_connect.php';
require_once 'includes/navigation.php';

// Only admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginModule.php");
    exit();
}

// Handle sorting parameters
$sort = $_GET['sort'] ?? 'created_at_desc';
$sort_column = 'created_at';
$sort_direction = 'DESC';

switch ($sort) {
    case 'title_asc':
        $sort_column = 'title';
        $sort_direction = 'ASC';
        break;
    case 'title_desc':
        $sort_column = 'title';
        $sort_direction = 'DESC';
        break;
    case 'type_asc':
        $sort_column = 'title'; // We'll sort by derived type
        $sort_direction = 'ASC';
        break;
    case 'type_desc':
        $sort_column = 'title'; // We'll sort by derived type
        $sort_direction = 'DESC';
        break;
    case 'status_asc':
        $sort_column = 'is_available';
        $sort_direction = 'ASC';
        break;
    case 'status_desc':
        $sort_column = 'is_available';
        $sort_direction = 'DESC';
        break;
    case 'price_asc':
        $sort_column = 'price';
        $sort_direction = 'ASC';
        break;
    case 'price_desc':
        $sort_column = 'price';
        $sort_direction = 'DESC';
        break;
    case 'id_asc':
        $sort_column = 'id';
        $sort_direction = 'ASC';
        break;
    case 'id_desc':
        $sort_column = 'id';
        $sort_direction = 'DESC';
        break;
    case 'created_at_asc':
        $sort_column = 'created_at';
        $sort_direction = 'ASC';
        break;
    default:
        $sort_column = 'created_at';
        $sort_direction = 'DESC';
        break;
}

// Fetch all approved listings with ML predictions
$stmt = $conn->prepare("
    SELECT id, title, address, price, capacity, bedroom, unit_sqm, kitchen, kitchen_type,
           gender_specific, pets, created_at, is_available
    FROM tblistings
    WHERE is_verified = 1 AND is_archived = 0
    ORDER BY {$sort_column} {$sort_direction}
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
    <title>Price Analytics - Admin Dashboard</title>
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
        .stat-card {
            background: var(--bg-secondary);
            border-radius: 10px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            border-left: 4px solid var(--hb-brown);
            color: var(--text-primary);
        }
        .chart-container {
            background: var(--bg-secondary);
            border-radius: 10px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            margin-bottom: 20px;
            position: relative;
            height: 400px;
            color: var(--text-primary);
        }
        .metric-box {
            text-align: center;
            padding: 15px;
            border-radius: 8px;
            background: var(--bg-tertiary);
        }
        .metric-value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--hb-brown);
        }
        .metric-label {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-top: 5px;
        }
        
        /* Dark mode specific adjustments */
        [data-theme="dark"] .stat-card {
            border-left-color: var(--hb-gold);
        }
        [data-theme="dark"] .metric-value {
            color: var(--hb-gold);
        }
        [data-theme="dark"] .topbar {
            background: var(--hb-brown);
        }
        
        /* Table styling for dark mode */
        [data-theme="dark"] .table-light {
            background-color: var(--bg-tertiary) !important;
            color: var(--text-primary) !important;
        }
        [data-theme="dark"] .table-light th {
            color: var(--text-primary) !important;
            border-color: var(--border-color) !important;
        }
        [data-theme="dark"] .table-hover tbody tr:hover {
            background-color: var(--bg-tertiary) !important;
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <?= getNavigationForRole('admin_price_analytics.php') ?>

    <main class="container-fluid py-4">
        <h3 class="mb-4"><i class="bi bi-graph-up-arrow"></i> Rental Price Analytics Dashboard</h3>

        <!-- Overall Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="metric-box">
                        <div class="metric-value"><?= $total_listings ?></div>
                        <div class="metric-label">Total Listings Analyzed</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="metric-box">
                        <div class="metric-value text-success"><?= $total_underpriced ?></div>
                        <div class="metric-label">Below Market (>10%)</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="metric-box">
                        <div class="metric-value text-info"><?= $total_fair ?></div>
                        <div class="metric-label">Fair Pricing (±10%)</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="metric-box">
                        <div class="metric-value text-warning"><?= $total_overpriced ?></div>
                        <div class="metric-label">Above Market (>10%)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Market Overview -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="chart-container">
                    <h5 class="mb-3"><i class="bi bi-pie-chart"></i> Price Distribution</h5>
                    <div style="position: relative; height: 300px;">
                        <canvas id="priceDistributionChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="chart-container">
                    <h5 class="mb-3"><i class="bi bi-bar-chart"></i> Average Prices by Property Type</h5>
                    <div style="position: relative; height: 300px;">
                        <canvas id="propertyTypeChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Overall Market Comparison -->
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="chart-container">
                    <h5 class="mb-3"><i class="bi bi-graph-up"></i> Overall Market Analysis</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="metric-box">
                                <div class="metric-label">Average Listed Price</div>
                                <div class="metric-value">₱<?= number_format($overall_avg_actual, 2) ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="metric-box">
                                <div class="metric-label">Average ML Predicted Price</div>
                                <div class="metric-value text-primary">₱<?= number_format($overall_avg_predicted, 2) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i>
                            Market variance: <?= $overall_avg_predicted > 0 ? number_format((($overall_avg_actual - $overall_avg_predicted) / $overall_avg_predicted) * 100, 1) : 0 ?>%
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Property Type Breakdown -->
        <div class="chart-container">
            <h5 class="mb-3"><i class="bi bi-list-ul"></i> Property Type Breakdown</h5>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Property Type</th>
                            <th>Count</th>
                            <th>Avg Listed Price</th>
                            <th>Avg ML Predicted</th>
                            <th>Variance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($property_type_stats as $type => $stats): ?>
                            <?php
                            $variance = $stats['avg_predicted'] > 0
                                ? (($stats['avg_actual'] - $stats['avg_predicted']) / $stats['avg_predicted']) * 100
                                : 0;
                            $variance_class = $variance > 10 ? 'text-warning' : ($variance < -10 ? 'text-success' : 'text-info');
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($type) ?></strong></td>
                                <td><?= $stats['count'] ?></td>
                                <td>₱<?= number_format($stats['avg_actual'], 2) ?></td>
                                <td>₱<?= number_format($stats['avg_predicted'], 2) ?></td>
                                <td class="<?= $variance_class ?>">
                                    <strong><?= $variance > 0 ? '+' : '' ?><?= number_format($variance, 1) ?>%</strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Listings with Pricing Analysis -->
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Listings Analysis</h5>
                <form method="get" class="d-flex align-items-center gap-2">
                    <label class="form-label small mb-0">Sort by:</label>
                    <select name="sort" class="form-select form-select-sm" style="width: 200px;" onchange="this.form.submit()">
                        <option value="created_at_desc" <?= $sort==='created_at_desc'?'selected':'' ?>>Created: Newest First</option>
                        <option value="created_at_asc" <?= $sort==='created_at_asc'?'selected':'' ?>>Created: Oldest First</option>
                        <option value="title_asc" <?= $sort==='title_asc'?'selected':'' ?>>Title: A-Z</option>
                        <option value="title_desc" <?= $sort==='title_desc'?'selected':'' ?>>Title: Z-A</option>
                        <option value="type_asc" <?= $sort==='type_asc'?'selected':'' ?>>Type: A-Z</option>
                        <option value="type_desc" <?= $sort==='type_desc'?'selected':'' ?>>Type: Z-A</option>
                        <option value="status_asc" <?= $sort==='status_asc'?'selected':'' ?>>Status: Available First</option>
                        <option value="status_desc" <?= $sort==='status_desc'?'selected':'' ?>>Status: Unavailable First</option>
                        <option value="price_asc" <?= $sort==='price_asc'?'selected':'' ?>>Price: Low to High</option>
                        <option value="price_desc" <?= $sort==='price_desc'?'selected':'' ?>>Price: High to Low</option>
                        <option value="id_asc" <?= $sort==='id_asc'?'selected':'' ?>>ID: Low to High</option>
                        <option value="id_desc" <?= $sort==='id_desc'?'selected':'' ?>>ID: High to Low</option>
                    </select>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>
                                <a href="?sort=<?= $sort === 'title_asc' ? 'title_desc' : 'title_asc' ?>" class="text-decoration-none">
                                    Title <i class="bi bi-arrow-<?= $sort === 'title_asc' ? 'up' : ($sort === 'title_desc' ? 'down' : 'up-down') ?>"></i>
                                </a>
                            </th>
                            <th>
                                <a href="?sort=<?= $sort === 'price_asc' ? 'price_desc' : 'price_asc' ?>" class="text-decoration-none">
                                    Listed Price <i class="bi bi-arrow-<?= $sort === 'price_asc' ? 'up' : ($sort === 'price_desc' ? 'down' : 'up-down') ?>"></i>
                                </a>
                            </th>
                            <th>ML Predicted</th>
                            <th>Difference</th>
                            <th>
                                <a href="?sort=<?= $sort === 'status_asc' ? 'status_desc' : 'status_asc' ?>" class="text-decoration-none">
                                    Status <i class="bi bi-arrow-<?= $sort === 'status_asc' ? 'up' : ($sort === 'status_desc' ? 'down' : 'up-down') ?>"></i>
                                </a>
                            </th>
                            <th>
                                <a href="?sort=<?= $sort === 'created_at_asc' ? 'created_at_desc' : 'created_at_asc' ?>" class="text-decoration-none">
                                    Created <i class="bi bi-arrow-<?= $sort === 'created_at_asc' ? 'up' : ($sort === 'created_at_desc' ? 'down' : 'up-down') ?>"></i>
                                </a>
                            </th>
                            <th>
                                <a href="?sort=<?= $sort === 'id_asc' ? 'id_desc' : 'id_asc' ?>" class="text-decoration-none">
                                    ID <i class="bi bi-arrow-<?= $sort === 'id_asc' ? 'up' : ($sort === 'id_desc' ? 'down' : 'up-down') ?>"></i>
                                </a>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $recent = array_slice($listings, 0, 20);
                        foreach ($recent as $listing):
                            if (!$listing['ml_prediction']) continue;
                            $badge_class = $listing['price_category'] === 'overpriced' ? 'warning' : ($listing['price_category'] === 'underpriced' ? 'success' : 'info');
                            $badge_text = $listing['price_category'] === 'overpriced' ? 'Above Market' : ($listing['price_category'] === 'underpriced' ? 'Below Market' : 'Fair Price');
                        ?>
                            <tr>
                                <td>
                                    <div class="text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($listing['title']) ?>">
                                        <?= htmlspecialchars($listing['title']) ?>
                                    </div>
                                    <small class="text-muted"><?= htmlspecialchars($listing['property_type']) ?></small>
                                </td>
                                <td><strong>₱<?= number_format($listing['price'], 2) ?></strong></td>
                                <td class="text-primary">₱<?= number_format($listing['ml_prediction'], 2) ?></td>
                                <td>
                                    <span class="badge bg-<?= $badge_class ?>">
                                        <?= $listing['diff_percent'] > 0 ? '+' : '' ?><?= number_format($listing['diff_percent'], 1) ?>%
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $badge_class ?>"><?= $badge_text ?></span>
                                </td>
                                <td><small><?= date('M d, Y', strtotime($listing['created_at'])) ?></small></td>
                                <td><small class="text-muted">#<?= $listing['id'] ?></small></td>
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
                        backgroundColor: [colors.green, colors.cyan, colors.orange]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 10,
                                font: {
                                    size: 12
                                },
                                color: colors.textColor
                            }
                        }
                    }
                }
            });
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
                            borderWidth: 0
                        },
                        {
                            label: 'ML Predicted',
                            data: [<?php foreach ($property_type_stats as $stats) echo $stats['avg_predicted'] . ','; ?>],
                            backgroundColor: colors.blue,
                            borderWidth: 0
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 10,
                                font: {
                                    size: 12
                                },
                                color: colors.textColor
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
                                color: colors.textColor
                            },
                            grid: {
                                display: true,
                                color: colors.gridColor
                            }
                        },
                        x: {
                            ticks: {
                                color: colors.textColor
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
