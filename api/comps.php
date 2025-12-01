<?php
/**
 * Comparables Analysis API
 * Returns detailed competitive analysis for a specific listing
 * by finding and comparing similar properties (comps)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET.']);
    exit;
}

require_once __DIR__ . '/../mysql_connect.php';

// Required parameter: listing_id
$listing_id = isset($_GET['listing_id']) ? (int)$_GET['listing_id'] : null;

if (!$listing_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameter: listing_id']);
    exit;
}

// ========== 1. GET THE TARGET LISTING ==========
$target_sql = "
    SELECT id, title, address, price, capacity, owner_id,
           amenities, bedroom, unit_sqm, is_available
    FROM tblistings
    WHERE id = ? AND is_archived = 0
    LIMIT 1
";

$stmt = $conn->prepare($target_sql);
$stmt->bind_param('i', $listing_id);
$stmt->execute();
$result = $stmt->get_result();
$target = $result->fetch_assoc();
$stmt->close();

if (!$target) {
    http_response_code(404);
    echo json_encode(['error' => 'Listing not found or archived']);
    exit;
}

// Normalize target fields
$target_bedrooms = isset($target['bedroom']) ? (int)$target['bedroom'] : 1;
$target_unit_sqm = isset($target['unit_sqm']) ? (float)$target['unit_sqm'] : 0.0;
$target_amenities_raw = isset($target['amenities']) ? $target['amenities'] : '';
$target_amenities = [];
if (!empty($target_amenities_raw)) {
    $target_amenities = array_values(array_filter(array_map('trim', explode(',', $target_amenities_raw))));
}
$target_property_type = $target['title'] ?? 'Listing';

// Extract city from address
$address_parts = array_map('trim', explode(',', $target['address']));
$metro_manila_cities = [
    'Manila', 'Quezon City', 'Caloocan', 'Las Piñas', 'Makati', 'Malabon',
    'Mandaluyong', 'Marikina', 'Muntinlupa', 'Navotas', 'Parañaque', 'Pasay',
    'Pasig', 'Pateros', 'San Juan', 'Taguig', 'Valenzuela'
];

$target_city = '';
foreach ($address_parts as $part) {
    foreach ($metro_manila_cities as $city) {
        if (stripos($part, $city) !== false) {
            $target_city = $city;
            break 2;
        }
    }
}

// ========== 2. FIND COMPARABLE PROPERTIES ==========
// Criteria: similar type, capacity (±1), same city, price range (±30%)
$capacity_min = max(1, (int)$target['capacity'] - 1);
$capacity_max = (int)$target['capacity'] + 1;
$price_min = (float)$target['price'] * 0.7; // 30% lower
$price_max = (float)$target['price'] * 1.3; // 30% higher

$comps_sql = "
    SELECT id, title, address, price, capacity, amenities, bedroom, unit_sqm, is_available
    FROM tblistings
    WHERE id != ?
      AND is_archived = 0
      AND price > 0
      AND capacity BETWEEN ? AND ?
      AND price BETWEEN ? AND ?
";

// Add city filter if we found one
$city_filter = '';
if ($target_city) {
    $city_filter = " AND address LIKE ?";
    $comps_sql .= $city_filter;
}

$comps_sql .= " ORDER BY ABS(price - ?) ASC LIMIT 10";

$stmt = $conn->prepare($comps_sql);

if ($target_city) {
    $city_pattern = "%{$target_city}%";
    $stmt->bind_param(
        'iiiddsd',
        $listing_id,
        $capacity_min,
        $capacity_max,
        $price_min,
        $price_max,
        $city_pattern,
        $target['price']
    );
} else {
    $stmt->bind_param(
        'iiiddd',
        $listing_id,
        $capacity_min,
        $capacity_max,
        $price_min,
        $price_max,
        $target['price']
    );
}

$stmt->execute();
$result = $stmt->get_result();

$comps = [];
while ($row = $result->fetch_assoc()) {
    $comps[] = [
        'id' => (int)$row['id'],
        'title' => $row['title'],
        'address' => $row['address'],
        'price' => round((float)$row['price'], 2),
        'capacity' => (int)$row['capacity'],
        'bedrooms' => isset($row['bedroom']) ? (int)$row['bedroom'] : 1,
        'unit_sqm' => isset($row['unit_sqm']) ? (float)$row['unit_sqm'] : 0.0,
        'price_per_sqm' => null,
        'property_type' => $row['title'] ?? 'Listing',
        'amenities' => !empty($row['amenities']) ? array_values(array_filter(array_map('trim', explode(',', $row['amenities'])))) : [],
        'is_available' => isset($row['is_available']) ? (int)$row['is_available'] : 1
    ];
}
$stmt->close();

// ========== 3. CALCULATE MARKET POSITIONING ==========
$target_price_per_sqm = null;
if ($target_unit_sqm > 0) {
    $target_price_per_sqm = round((float)$target['price'] / $target_unit_sqm, 2);
}

// Calculate statistics from comps
$comp_prices = array_column($comps, 'price');
$avg_comp_price = !empty($comp_prices) ? round(array_sum($comp_prices) / count($comp_prices), 2) : 0;
$min_comp_price = !empty($comp_prices) ? min($comp_prices) : 0;
$max_comp_price = !empty($comp_prices) ? max($comp_prices) : 0;

// Price positioning
$price_diff = (float)$target['price'] - $avg_comp_price;
$price_diff_pct = $avg_comp_price > 0 ? round(($price_diff / $avg_comp_price) * 100, 1) : 0;

// Rank (where does your listing stand among comps)
$all_prices = array_merge($comp_prices, [(float)$target['price']]);
rsort($all_prices);
$rank = array_search((float)$target['price'], $all_prices) + 1;
$total_compared = count($all_prices);

// Amenities comparison
$comp_amenities_count = [];
foreach ($comps as $comp) {
    foreach ($comp['amenities'] as $amenity) {
        if (!isset($comp_amenities_count[$amenity])) {
            $comp_amenities_count[$amenity] = 0;
        }
        $comp_amenities_count[$amenity]++;
    }
}

// Find common amenities you're missing
$missing_amenities = [];
foreach ($comp_amenities_count as $amenity => $count) {
    // If >50% of comps have it and you don't
    if ($count > (count($comps) / 2) && !in_array($amenity, $target_amenities)) {
        $missing_amenities[] = [
            'amenity' => $amenity,
            'prevalence_pct' => round(($count / count($comps)) * 100, 0)
        ];
    }
}

// ========== 4. GENERATE RECOMMENDATIONS ==========
$recommendations = [];

// Price recommendations
if ($price_diff_pct > 15) {
    $recommendations[] = [
        'type' => 'price',
        'severity' => 'high',
        'message' => "Your price is {$price_diff_pct}% higher than similar properties. Consider reducing to ₱" . number_format($avg_comp_price, 0) . " to be more competitive."
    ];
} elseif ($price_diff_pct > 5) {
    $recommendations[] = [
        'type' => 'price',
        'severity' => 'medium',
        'message' => "Your price is slightly above market ({$price_diff_pct}% higher). You're positioned as a premium option."
    ];
} elseif ($price_diff_pct < -15) {
    $recommendations[] = [
        'type' => 'price',
        'severity' => 'medium',
        'message' => "Your price is {$price_diff_pct}% below market. You may be underpricing - consider increasing to ₱" . number_format($avg_comp_price, 0) . "."
    ];
} else {
    $recommendations[] = [
        'type' => 'price',
        'severity' => 'low',
        'message' => "Your pricing is competitive and aligned with the market."
    ];
}

// Amenities recommendations
if (!empty($missing_amenities)) {
    $top_missing = array_slice($missing_amenities, 0, 3);
    $amenity_list = implode(', ', array_column($top_missing, 'amenity'));
    $recommendations[] = [
        'type' => 'amenities',
        'severity' => 'medium',
        'message' => "Consider adding: {$amenity_list}. Most competitors offer these amenities."
    ];
}

// Availability recommendations
$available_comps = array_filter($comps, fn($c) => $c['is_available'] == 1);
$availability_rate = count($comps) > 0 ? round((count($available_comps) / count($comps)) * 100, 0) : 0;

if ($availability_rate < 30) {
    $recommendations[] = [
        'type' => 'market',
        'severity' => 'low',
        'message' => "Low availability in your area ({$availability_rate}% available). Good time to list!"
    ];
}

// ========== RESPONSE ==========
$response = [
    'success' => true,
    'data' => [
        'target_listing' => [
            'id' => (int)$target['id'],
            'title' => $target['title'],
            'price' => round((float)$target['price'], 2),
            'capacity' => (int)$target['capacity'],
            'bedrooms' => $target_bedrooms,
            'unit_sqm' => $target_unit_sqm,
            'price_per_sqm' => $target_price_per_sqm,
            'property_type' => $target_property_type,
            'city' => $target_city ?: 'Unknown',
            'amenities' => $target_amenities
        ],
        'comparables' => $comps,
        'market_position' => [
            'rank' => $rank,
            'total_compared' => $total_compared,
            'percentile' => round((($total_compared - $rank + 1) / $total_compared) * 100, 0),
            'avg_comp_price' => $avg_comp_price,
            'min_comp_price' => $min_comp_price,
            'max_comp_price' => $max_comp_price,
            'price_difference' => $price_diff,
            'price_difference_pct' => $price_diff_pct,
            'status' => $price_diff_pct > 10 ? 'overpriced' : ($price_diff_pct < -10 ? 'underpriced' : 'competitive')
        ],
        'amenities_analysis' => [
            'your_amenities_count' => count($target_amenities),
            'avg_comp_amenities_count' => count($comps) > 0 ? round(array_sum(array_map(fn($c) => count($c['amenities']), $comps)) / count($comps), 1) : 0,
            'missing_amenities' => $missing_amenities
        ],
        'recommendations' => $recommendations
    ],
    'metadata' => [
        'timestamp' => date('Y-m-d H:i:s'),
        'comps_found' => count($comps)
    ]
];

echo json_encode($response, JSON_PRETTY_PRINT);
