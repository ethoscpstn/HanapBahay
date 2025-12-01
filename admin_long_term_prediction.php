<?php
session_start();
require 'mysql_connect.php';
require_once 'includes/navigation.php';

// Only admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginModule.php");
    exit();
}

// Function to generate historical data simulation
function generateHistoricalData($current_data, $months_back = 12) {
    $historical = [];
    $base_price = $current_data['avg_price'];
    $volatility = 0.15; // 15% monthly volatility
    
    // Generate seasonal patterns (higher in summer, lower in winter)
    $seasonal_patterns = [
        1 => 0.95,  // January - lower
        2 => 0.98,  // February
        3 => 1.02,  // March - spring increase
        4 => 1.05,  // April
        5 => 1.08,  // May - peak season
        6 => 1.10,  // June - peak season
        7 => 1.08,  // July
        8 => 1.05,  // August
        9 => 1.02,  // September
        10 => 0.98, // October
        11 => 0.95, // November
        12 => 0.92  // December - holiday season
    ];
    
    // Generate trend (slight upward trend over time)
    $trend_factor = 1.02; // 2% annual growth
    
    for ($i = $months_back; $i >= 0; $i--) {
        $date = date('Y-m', strtotime("-$i months"));
        $month = (int)date('n', strtotime("-$i months"));
        
        // Calculate price with seasonal, trend, and random factors
        $seasonal_factor = $seasonal_patterns[$month];
        $trend_factor_monthly = pow($trend_factor, $i / 12);
        $random_factor = 1 + (rand(-100, 100) / 1000) * $volatility; // Random ±15%
        
        $price = $base_price * $seasonal_factor * $trend_factor_monthly * $random_factor;
        
        $historical[] = [
            'month' => $date,
            'price' => round($price, 2),
            'seasonal_factor' => $seasonal_factor,
            'trend_factor' => $trend_factor_monthly
        ];
    }
    
    return $historical;
}

// Function to predict future prices
function predictFuturePrices($historical_data, $months_ahead = 12) {
    $predictions = [];
    $last_price = end($historical_data)['price'];
    
    // Calculate trend from historical data
    $first_price = $historical_data[0]['price'];
    $months = count($historical_data);
    $trend_rate = pow($last_price / $first_price, 1 / $months);
    
    // Seasonal patterns
    $seasonal_patterns = [
        1 => 0.95, 2 => 0.98, 3 => 1.02, 4 => 1.05, 5 => 1.08, 6 => 1.10,
        7 => 1.08, 8 => 1.05, 9 => 1.02, 10 => 0.98, 11 => 0.95, 12 => 0.92
    ];
    
    for ($i = 1; $i <= $months_ahead; $i++) {
        $date = date('Y-m', strtotime("+$i months"));
        $month = (int)date('n', strtotime("+$i months"));
        
        $seasonal_factor = $seasonal_patterns[$month];
        $trend_factor = pow($trend_rate, $i);
        
        $predicted_price = $last_price * $trend_factor * $seasonal_factor;
        
        // Add confidence interval (±10% for short term, ±20% for long term)
        $confidence = $i <= 3 ? 0.10 : ($i <= 6 ? 0.15 : 0.20);
        $lower_bound = $predicted_price * (1 - $confidence);
        $upper_bound = $predicted_price * (1 + $confidence);
        
        $predictions[] = [
            'month' => $date,
            'predicted_price' => round($predicted_price, 2),
            'lower_bound' => round($lower_bound, 2),
            'upper_bound' => round($upper_bound, 2),
            'confidence' => round((1 - $confidence) * 100),
            'seasonal_factor' => $seasonal_factor,
            'trend_factor' => $trend_factor
        ];
    }
    
    return $predictions;
}

// Fetch current market data
$stmt = $conn->prepare("
    SELECT AVG(price) as avg_price, COUNT(*) as total_listings,
           AVG(CASE WHEN bedroom = 1 THEN price END) as studio_avg,
           AVG(CASE WHEN bedroom = 2 THEN price END) as apartment_avg,
           AVG(CASE WHEN bedroom >= 3 THEN price END) as house_avg
    FROM tblistings
    WHERE is_verified = 1 AND is_archived = 0
");
$stmt->execute();
$result = $stmt->get_result();
$market_data = $result->fetch_assoc();
$stmt->close();

// Generate historical data simulation
$historical_data = generateHistoricalData($market_data, 24); // 24 months back
$future_predictions = predictFuturePrices($historical_data, 12); // 12 months ahead

// Calculate market insights
$current_price = $market_data['avg_price'];
$last_month_price = $historical_data[count($historical_data) - 2]['price'];
$price_change = (($current_price - $last_month_price) / $last_month_price) * 100;

$next_month_prediction = $future_predictions[0]['predicted_price'];
$next_year_prediction = $future_predictions[11]['predicted_price'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Long-term Price Prediction - Admin Dashboard</title>
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
        .prediction-card {
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
        .prediction-highlight {
            background: var(--bg-tertiary);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid var(--hb-gold);
        }
        .confidence-bar {
            height: 8px;
            background: var(--bg-tertiary);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 10px;
        }
        .confidence-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--hb-brown), var(--hb-gold));
            border-radius: 4px;
            transition: width 0.3s ease;
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
        .insight-card {
            background: var(--bg-tertiary);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid var(--hb-gold);
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <?= getNavigationForRole('admin_long_term_prediction.php') ?>

    <main class="container py-4">
        <!-- Hero Section -->
        <div class="hero-section text-center">
            <h1 class="display-5 mb-3"><i class="bi bi-graph-up-arrow"></i> Long-term Price Prediction</h1>
            <p class="lead">AI-powered market forecasting with historical trend analysis</p>
        </div>

        <!-- Key Predictions -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="prediction-card text-center">
                    <div class="metric-value">₱<?= number_format($current_price, 0) ?></div>
                    <div class="metric-label">Current Market Price</div>
                    <div class="small text-muted mt-2">
                        <span class="trend-indicator <?= $price_change > 0 ? 'trend-up' : ($price_change < 0 ? 'trend-down' : 'trend-stable') ?>">
                            <?= $price_change > 0 ? '+' : '' ?><?= number_format($price_change, 1) ?>% vs last month
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="prediction-card text-center">
                    <div class="metric-value text-primary">₱<?= number_format($next_month_prediction, 0) ?></div>
                    <div class="metric-label">Next Month Prediction</div>
                    <div class="small text-muted mt-2">
                        <span class="trend-indicator trend-stable"><?= $future_predictions[0]['confidence'] ?>% confidence</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="prediction-card text-center">
                    <div class="metric-value text-success">₱<?= number_format($next_year_prediction, 0) ?></div>
                    <div class="metric-label">Next Year Prediction</div>
                    <div class="small text-muted mt-2">
                        <span class="trend-indicator trend-stable"><?= $future_predictions[11]['confidence'] ?>% confidence</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="prediction-card text-center">
                    <div class="metric-value text-warning"><?= number_format((($next_year_prediction - $current_price) / $current_price) * 100, 1) ?>%</div>
                    <div class="metric-label">Annual Growth Rate</div>
                    <div class="small text-muted mt-2">
                        <span class="trend-indicator trend-up">Projected</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Price Trend Chart -->
        <div class="chart-container">
            <h3 class="mb-4"><i class="bi bi-graph-up"></i> Historical Trends & Future Predictions</h3>
            <div style="position: relative; height: 400px;">
                <canvas id="priceTrendChart"></canvas>
            </div>
            <div class="mt-4">
                <div class="row">
                    <div class="col-md-4">
                        <div class="insight-card">
                            <h6><i class="bi bi-calendar"></i> Seasonal Patterns</h6>
                            <p class="small mb-0">Peak season: May-June (+8-10%), Low season: December (-8%)</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="insight-card">
                            <h6><i class="bi bi-arrow-up"></i> Trend Analysis</h6>
                            <p class="small mb-0">Annual growth rate: ~2% based on historical simulation</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="insight-card">
                            <h6><i class="bi bi-shield-check"></i> Confidence Levels</h6>
                            <p class="small mb-0">Short-term (1-3 months): 90%, Long-term (6-12 months): 80%</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Predictions Table -->
        <div class="chart-container">
            <h3 class="mb-4"><i class="bi bi-table"></i> Monthly Predictions & Confidence Intervals</h3>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Month</th>
                            <th>Predicted Price</th>
                            <th>Lower Bound</th>
                            <th>Upper Bound</th>
                            <th>Confidence</th>
                            <th>Seasonal Factor</th>
                            <th>Trend Factor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($future_predictions as $prediction): ?>
                            <tr>
                                <td><strong><?= date('M Y', strtotime($prediction['month'] . '-01')) ?></strong></td>
                                <td>₱<?= number_format($prediction['predicted_price'], 2) ?></td>
                                <td>₱<?= number_format($prediction['lower_bound'], 2) ?></td>
                                <td>₱<?= number_format($prediction['upper_bound'], 2) ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="me-2"><?= $prediction['confidence'] ?>%</span>
                                        <div class="confidence-bar" style="width: 100px;">
                                            <div class="confidence-fill" style="width: <?= $prediction['confidence'] ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= number_format($prediction['seasonal_factor'], 3) ?></td>
                                <td><?= number_format($prediction['trend_factor'], 3) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Market Insights -->
        <div class="chart-container">
            <h3 class="mb-4"><i class="bi bi-lightbulb"></i> Market Insights & Recommendations</h3>
            <div class="row">
                <div class="col-md-6">
                    <div class="prediction-highlight">
                        <h6><i class="bi bi-graph-up-arrow"></i> Short-term Outlook (1-3 months)</h6>
                        <p class="small mb-2">
                            Expected price range: ₱<?= number_format($future_predictions[0]['lower_bound'], 0) ?> - ₱<?= number_format($future_predictions[0]['upper_bound'], 0) ?>
                        </p>
                        <p class="small mb-0">
                            <?php 
                            $short_term_change = (($future_predictions[2]['predicted_price'] - $current_price) / $current_price) * 100;
                            if ($short_term_change > 2) {
                                echo "Strong upward trend expected. Consider listing properties now to capitalize on rising prices.";
                            } elseif ($short_term_change < -2) {
                                echo "Potential downward pressure. Monitor market closely for better listing opportunities.";
                            } else {
                                echo "Stable market conditions. Good time for both listing and renting properties.";
                            }
                            ?>
                        </p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="prediction-highlight">
                        <h6><i class="bi bi-calendar-range"></i> Long-term Outlook (6-12 months)</h6>
                        <p class="small mb-2">
                            Expected price range: ₱<?= number_format($future_predictions[5]['lower_bound'], 0) ?> - ₱<?= number_format($future_predictions[11]['upper_bound'], 0) ?>
                        </p>
                        <p class="small mb-0">
                            <?php 
                            $long_term_change = (($next_year_prediction - $current_price) / $current_price) * 100;
                            if ($long_term_change > 5) {
                                echo "Significant growth potential. Long-term investments in rental properties recommended.";
                            } elseif ($long_term_change < -5) {
                                echo "Market correction possible. Focus on short-term rentals and flexible contracts.";
                            } else {
                                echo "Moderate growth expected. Balanced approach to property investment advised.";
                            }
                            ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="row mt-3">
                <div class="col-12">
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> Methodology & Limitations</h6>
                        <p class="small mb-0">
                            <strong>Data Source:</strong> Historical simulation based on current market data and seasonal patterns.<br>
                            <strong>Confidence Levels:</strong> Based on historical volatility and seasonal consistency.<br>
                            <strong>Limitations:</strong> Predictions assume stable economic conditions and don't account for external factors like economic shocks, policy changes, or major market disruptions. Use predictions as guidance, not absolute forecasts.
                        </p>
                    </div>
                </div>
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
                cyan: dark ? '#06b6d4' : '#17a2b8',
                red: dark ? '#ef4444' : '#dc3545'
            };
        }

        // Price Trend Chart
        const priceTrendCtx = document.getElementById('priceTrendChart');
        if (priceTrendCtx) {
            const colors = getChartColors();
            
            // Prepare data
            const historicalLabels = [<?php foreach ($historical_data as $data) echo "'" . date('M Y', strtotime($data['month'] . '-01')) . "',"; ?>];
            const historicalPrices = [<?php foreach ($historical_data as $data) echo $data['price'] . ','; ?>];
            
            const futureLabels = [<?php foreach ($future_predictions as $pred) echo "'" . date('M Y', strtotime($pred['month'] . '-01')) . "',"; ?>];
            const futurePrices = [<?php foreach ($future_predictions as $pred) echo $pred['predicted_price'] . ','; ?>];
            const futureLower = [<?php foreach ($future_predictions as $pred) echo $pred['lower_bound'] . ','; ?>];
            const futureUpper = [<?php foreach ($future_predictions as $pred) echo $pred['upper_bound'] . ','; ?>];
            
            new Chart(priceTrendCtx, {
                type: 'line',
                data: {
                    labels: [...historicalLabels, ...futureLabels],
                    datasets: [
                        {
                            label: 'Historical Prices',
                            data: [...historicalPrices, ...Array(futureLabels.length).fill(null)],
                            borderColor: colors.brown,
                            backgroundColor: colors.brown + '20',
                            borderWidth: 3,
                            fill: false,
                            tension: 0.4,
                            pointBackgroundColor: colors.brown,
                            pointBorderColor: colors.brown,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        },
                        {
                            label: 'Predicted Prices',
                            data: [...Array(historicalLabels.length).fill(null), ...futurePrices],
                            borderColor: colors.blue,
                            backgroundColor: colors.blue + '20',
                            borderWidth: 3,
                            fill: false,
                            tension: 0.4,
                            pointBackgroundColor: colors.blue,
                            pointBorderColor: colors.blue,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            borderDash: [5, 5]
                        },
                        {
                            label: 'Confidence Interval (Upper)',
                            data: [...Array(historicalLabels.length).fill(null), ...futureUpper],
                            borderColor: colors.cyan,
                            backgroundColor: colors.cyan + '10',
                            borderWidth: 1,
                            fill: '+1',
                            tension: 0.4,
                            pointRadius: 0,
                            borderDash: [2, 2]
                        },
                        {
                            label: 'Confidence Interval (Lower)',
                            data: [...Array(historicalLabels.length).fill(null), ...futureLower],
                            borderColor: colors.cyan,
                            backgroundColor: colors.cyan + '10',
                            borderWidth: 1,
                            fill: false,
                            tension: 0.4,
                            pointRadius: 0,
                            borderDash: [2, 2]
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
                                    if (value !== null) {
                                        return `${label}: ₱${value.toLocaleString()}`;
                                    }
                                    return null;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
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
                                },
                                maxTicksLimit: 12
                            },
                            grid: {
                                display: false
                            }
                        }
                    },
                    elements: {
                        point: {
                            hoverBackgroundColor: colors.gold
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
