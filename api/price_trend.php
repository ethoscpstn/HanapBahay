<?php
/**
 * Price Trend & Analytics API
 * Returns rental market trends, demand patterns, and competitive pricing data
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

$property_helpers_path = __DIR__ . '/../includes/property_helpers.php';
if (file_exists($property_helpers_path)) {
    require_once $property_helpers_path;
} else {
    if (!function_exists('normalize_property_type_label')) {
        function normalize_property_type_label($value) {
            $value = strtolower(trim((string)$value));
            if ($value === '') {
                return '';
            }

            $map = [
                'studio' => 'Studio',
                'condominium' => 'Condominium',
                'condo' => 'Condominium',
                'apartment' => 'Apartment',
                'apt' => 'Apartment',
                'house' => 'House',
                'townhouse' => 'House',
                'room' => 'Room',
                'bedspace' => 'Room',
                'dorm' => 'Dormitory',
                'loft' => 'Loft',
                'boarding' => 'Boarding House',
                'boarding house' => 'Boarding House'
            ];

            foreach ($map as $needle => $label) {
                if (strpos($value, $needle) !== false) {
                    return $label;
                }
            }

            return ucwords($value);
        }
    }

    if (!function_exists('infer_property_type_from_title')) {
        function infer_property_type_from_title($title) {
            $title = (string)$title;
            if ($title === '') {
                return '';
            }
            return normalize_property_type_label($title);
        }
    }
}

// Optional filter by owner_id
$owner_id = isset($_GET['owner_id']) ? (int)$_GET['owner_id'] : null;

// Optional filter by property type
$requested_type_raw = isset($_GET['property_type']) ? $_GET['property_type'] : '';
$requested_property_type = $requested_type_raw !== ''
    ? normalize_property_type_label($requested_type_raw)
    : '';

if (strcasecmp($requested_property_type, 'All Property Types') === 0) {
    $requested_property_type = '';
}

// Get date range (default: last 12 months)
$months_back = isset($_GET['months']) ? min(24, max(1, (int)$_GET['months'])) : 12;
$start_date = date('Y-m-01', strtotime("-{$months_back} months"));

// ========== 1. MONTHLY DEMAND TRENDS ==========
$demand_sql = "
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') as month,
        DATE_FORMAT(created_at, '%b %Y') as month_label,
        COUNT(*) as listing_count,
        AVG(price) as avg_price
    FROM tblistings
    WHERE created_at >= ?
      AND is_archived = 0
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC
";

$stmt = $conn->prepare($demand_sql);
$stmt->bind_param('s', $start_date);
$stmt->execute();
$result = $stmt->get_result();

$demand_trends = [];
while ($row = $result->fetch_assoc()) {
    $demand_trends[] = [
        'month' => $row['month'],
        'month_label' => $row['month_label'],
        'listing_count' => (int)$row['listing_count'],
        'avg_price' => round((float)$row['avg_price'], 2)
    ];
}
$stmt->close();

// ========== 2. PEAK RENTAL MONTHS ==========
$peak_months = [];
if (!empty($demand_trends)) {
    $sorted = $demand_trends;
    usort($sorted, function ($a, $b) {
        if ($a['listing_count'] === $b['listing_count']) {
            return 0;
        }
        return ($a['listing_count'] < $b['listing_count']) ? 1 : -1;
    });
    $peak_months = array_slice($sorted, 0, 3);
}

// ========== 3. PRICE DISTRIBUTION BY LOCATION ==========
$location_sql = "
    SELECT
        address,
        COUNT(*) as count,
        AVG(price) as avg_price,
        MIN(price) as min_price,
        MAX(price) as max_price
    FROM tblistings
    WHERE created_at >= ?
      AND is_archived = 0
      AND price > 0
    GROUP BY address
    HAVING count >= 1
    ORDER BY avg_price DESC
    LIMIT 10
";

$stmt = $conn->prepare($location_sql);
$stmt->bind_param('s', $start_date);
$stmt->execute();
$result = $stmt->get_result();

$metro_manila_cities = [
    'Manila', 'Quezon City', 'Caloocan', 'Las Piñas', 'Makati', 'Malabon',
    'Mandaluyong', 'Marikina', 'Muntinlupa', 'Navotas', 'Parañaque', 'Pasay',
    'Pasig', 'Pateros', 'San Juan', 'Taguig', 'Valenzuela'
];

$location_trends = [];
while ($row = $result->fetch_assoc()) {
    $address_parts = array_map('trim', explode(',', $row['address']));
    $city = '';

    foreach ($address_parts as $part) {
        foreach ($metro_manila_cities as $mm_city) {
            if (stripos($part, $mm_city) !== false) {
                $city = $mm_city;
                break 2;
            }
        }
    }

    if (empty($city) && count($address_parts) >= 2) {
        $potential_city = $address_parts[count($address_parts) - 2];
        foreach ($metro_manila_cities as $mm_city) {
            if (stripos($potential_city, $mm_city) !== false) {
                $city = $mm_city;
                break;
            }
        }
    }

    if (empty($city)) {
        continue;
    }

    $found = false;
    foreach ($location_trends as &$trend) {
        if ($trend['location'] === $city) {
            $total_count = $trend['count'] + (int)$row['count'];
            $trend['avg_price'] = round((($trend['avg_price'] * $trend['count']) + ((float)$row['avg_price'] * (int)$row['count'])) / $total_count, 2);
            $trend['count'] = $total_count;
            $trend['min_price'] = min($trend['min_price'], round((float)$row['min_price'], 2));
            $trend['max_price'] = max($trend['max_price'], round((float)$row['max_price'], 2));
            $found = true;
            break;
        }
    }
    unset($trend);

    if (!$found) {
        $location_trends[] = [
            'location' => $city,
            'full_address' => $row['address'],
            'count' => (int)$row['count'],
            'avg_price' => round((float)$row['avg_price'], 2),
            'min_price' => round((float)$row['min_price'], 2),
            'max_price' => round((float)$row['max_price'], 2)
        ];
    }
}
$stmt->close();

usort($location_trends, function ($a, $b) {
    if ($a['avg_price'] === $b['avg_price']) {
        return 0;
    }
    return ($a['avg_price'] < $b['avg_price']) ? 1 : -1;
});
$location_trends = array_slice($location_trends, 0, 10);

// ========== 4. COMPETITIVE PRICING ==========
$competitive_data = null;
if ($owner_id) {
    $owner_sql = "
        SELECT
            id,
            title,
            price,
            capacity,
            address
        FROM tblistings
        WHERE owner_id = ?
          AND is_archived = 0
          AND price > 0
        ORDER BY created_at DESC
    ";

    $stmt = $conn->prepare($owner_sql);
    $stmt->bind_param('i', $owner_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $owner_listings = [];
    $owner_totals_by_type = [];
    $owner_total = 0;
    $owner_count = 0;

    while ($row = $result->fetch_assoc()) {
        $price = round((float)$row['price'], 2);
        $type = infer_property_type_from_title($row['title'] ?? '');
        if ($type === '') {
            $type = 'Listing';
        }

        $owner_listings[] = [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'price' => $price,
            'capacity' => (int)$row['capacity'],
            'address' => $row['address'],
            'property_type' => $type
        ];

        $owner_total += $price;
        $owner_count++;

        if (!isset($owner_totals_by_type[$type])) {
            $owner_totals_by_type[$type] = ['total' => 0, 'count' => 0];
        }
        $owner_totals_by_type[$type]['total'] += $price;
        $owner_totals_by_type[$type]['count']++;
    }
    $stmt->close();

    $owner_avg_by_type = [];
    foreach ($owner_totals_by_type as $type => $data) {
        $owner_avg_by_type[$type] = $data['count'] > 0
            ? round($data['total'] / $data['count'], 2)
            : 0;
    }

    $owner_filtered = $owner_listings;
    if ($requested_property_type !== '') {
        $owner_filtered = array_values(array_filter(
            $owner_listings,
            function ($listing) use ($requested_property_type) {
                return $listing['property_type'] === $requested_property_type;
            }
        ));
    }

    $owner_avg_price = 0;
    if (!empty($owner_filtered)) {
        $owner_avg_price = round(array_sum(array_column($owner_filtered, 'price')) / count($owner_filtered), 2);
    } elseif (!empty($owner_listings)) {
        $owner_avg_price = round(array_sum(array_column($owner_listings, 'price')) / count($owner_listings), 2);
    }

    $market_totals_by_type = [];
    $market_total = 0;
    $market_count = 0;
    $market_sql = "
        SELECT title, price
        FROM tblistings
        WHERE is_archived = 0
          AND price > 0
    ";
    $market_result = $conn->query($market_sql);
    if ($market_result) {
        while ($row = $market_result->fetch_assoc()) {
            $type = infer_property_type_from_title($row['title'] ?? '');
            if ($type === '') {
                $type = 'Listing';
            }
            $price = (float)$row['price'];
            if ($price <= 0) {
                continue;
            }

            $market_total += $price;
            $market_count++;

            if (!isset($market_totals_by_type[$type])) {
                $market_totals_by_type[$type] = ['total' => 0, 'count' => 0];
            }
            $market_totals_by_type[$type]['total'] += $price;
            $market_totals_by_type[$type]['count']++;
        }
    }

    $market_avg_by_type = [];
    foreach ($market_totals_by_type as $type => $data) {
        $market_avg_by_type[$type] = $data['count'] > 0
            ? round($data['total'] / $data['count'], 2)
            : 0;
    }

    $market_avg_overall = $market_count > 0
        ? round($market_total / $market_count, 2)
        : 0;

    $property_types = array_unique(array_merge(
        array_keys($market_avg_by_type),
        array_keys($owner_avg_by_type)
    ));
    $property_types = array_values($property_types);
    sort($property_types);

    if ($requested_property_type !== '' && !in_array($requested_property_type, $property_types, true)) {
        $property_types[] = $requested_property_type;
        sort($property_types);
    }

    $selected_type = '';
    if ($requested_property_type !== '' && in_array($requested_property_type, $property_types, true)) {
        $selected_type = $requested_property_type;
    }

    $market_avg_price = $selected_type !== '' && isset($market_avg_by_type[$selected_type])
        ? $market_avg_by_type[$selected_type]
        : $market_avg_overall;

    $price_difference = round($owner_avg_price - $market_avg_price, 2);
    $price_difference_pct = $market_avg_price > 0
        ? round(($price_difference / $market_avg_price) * 100, 1)
        : 0;

    $status = 'at_market';
    if ($market_avg_price <= 0 && $owner_avg_price <= 0) {
        $status = 'no_data';
    } elseif ($market_avg_price <= 0) {
        $status = 'no_market_data';
    } elseif ($owner_avg_price <= 0) {
        $status = 'no_owner_data';
    } elseif ($price_difference_pct > 5) {
        $status = 'above_market';
    } elseif ($price_difference_pct < -5) {
        $status = 'below_market';
    }

    $competitive_data = [
        'owner_avg_price' => $owner_avg_price,
        'market_avg_price' => $market_avg_price,
        'price_difference' => $price_difference,
        'price_difference_pct' => $price_difference_pct,
        'owner_listings' => $owner_filtered,
        'status' => $status,
        'property_types' => $property_types,
        'selected_type' => $selected_type,
        'owner_avg_by_type' => $owner_avg_by_type,
        'market_avg_by_type' => $market_avg_by_type
    ];
}

// ========== 5. PRICE TRENDS OVER TIME ==========
$price_trend_sql = "
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') as month,
        DATE_FORMAT(created_at, '%b %Y') as month_label,
        AVG(price) as avg_price,
        MIN(price) as min_price,
        MAX(price) as max_price
    FROM tblistings
    WHERE created_at >= ?
      AND is_archived = 0
      AND price > 0
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC
";

$stmt = $conn->prepare($price_trend_sql);
$stmt->bind_param('s', $start_date);
$stmt->execute();
$result = $stmt->get_result();

$price_trends = [];
while ($row = $result->fetch_assoc()) {
    $price_trends[] = [
        'month' => $row['month'],
        'month_label' => $row['month_label'],
        'avg_price' => round((float)$row['avg_price'], 2),
        'min_price' => round((float)$row['min_price'], 2),
        'max_price' => round((float)$row['max_price'], 2)
    ];
}
$stmt->close();

// ========== RESPONSE ==========
$response = [
    'success' => true,
    'data' => [
        'demand_trends' => $demand_trends,
        'peak_months' => $peak_months,
        'location_trends' => $location_trends,
        'price_trends' => $price_trends,
        'competitive_analysis' => $competitive_data
    ],
    'metadata' => [
        'timestamp' => date('Y-m-d H:i:s'),
        'months_analyzed' => $months_back,
        'start_date' => $start_date,
        'owner_id' => $owner_id
    ]
];

echo json_encode($response, JSON_PRETTY_PRINT);
