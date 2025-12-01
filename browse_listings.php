<?php
// browse_listings.php — Public page (no login required)
require 'mysql_connect.php';
require 'app_config.php';
require_once 'includes/location_utils.php';

// Enable error reporting in debug mode
if ($APP_CONFIG['debug_mode'] ?? false) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Initialize error array
$errors = [];

// Read filters safely
$location_query = '';
if (isset($_GET['location'])) {
  $location_query = trim($_GET['location']);
} elseif (isset($_GET['q'])) {
  // Backwards compatibility with older query parameter
  $location_query = trim($_GET['q']);
}
$q = $location_query;
$min_price = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? (float)str_replace(',', '', $_GET['min_price']) : null;
$max_price = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? (float)str_replace(',', '', $_GET['max_price']) : null;
$min_cap   = isset($_GET['min_cap'])   && $_GET['min_cap']   !== '' ? (int)$_GET['min_cap'] : null;

// Handle amenity filters
$selected_amenities = [];
if (isset($_GET['amenities']) && is_array($_GET['amenities'])) {
    $selected_amenities = array_filter(array_map('trim', $_GET['amenities']));
}

$sort   = $_GET['sort'] ?? 'newest';  // newest | price_asc | price_desc | capacity_desc
$page   = max(1, (int)($_GET['page'] ?? 1));
$pp     = 9;                           // per-page
$offset = ($page - 1) * $pp;

// Whitelist order by
switch ($sort) {
  case 'price_asc':      $order_by = 'price ASC, (total_units - occupied_units) DESC, id DESC'; break;
  case 'price_desc':     $order_by = 'price DESC, (total_units - occupied_units) DESC, id DESC'; break;
  case 'capacity_desc':  $order_by = 'capacity DESC, (total_units - occupied_units) DESC, id DESC'; break;
  default:               $order_by = '(total_units - occupied_units) DESC, id DESC'; // newest
}

// WHERE parts - ONLY show approved, non-archived listings
$where = [
  "is_archived = 0",
  "is_verified = 1",
  "(verification_status IS NULL OR verification_status = 'approved')",
  "is_available = 1",
  "(total_units - occupied_units) > 0"
];
if ($q !== '') {
  $q_esc = $conn->real_escape_string($q);
  $like  = "%{$q_esc}%";
  $where[] = "(title LIKE '{$like}' OR description LIKE '{$like}' OR address LIKE '{$like}')";
}

if ($location_query !== '') {
  $location_terms = array_filter(array_map('trim', preg_split('/[;,]+/', $location_query)));
  foreach ($location_terms as $term) {
    if ($term === '') {
      continue;
    }
    $term_esc = $conn->real_escape_string($term);
    $like_loc = "%{$term_esc}%";
    $where[] = "(address LIKE '{$like_loc}' OR title LIKE '{$like_loc}' OR description LIKE '{$like_loc}')";
  }
}

// Add amenity filtering
if (!empty($selected_amenities)) {
    $amenity_conditions = [];
    foreach ($selected_amenities as $amenity) {
        $amenity_esc = $conn->real_escape_string($amenity);
        $amenity_conditions[] = "amenities LIKE '%{$amenity_esc}%'";
    }
    if (!empty($amenity_conditions)) {
        $where[] = "(" . implode(" AND ", $amenity_conditions) . ")";
    }
}

$where_sql = implode(" AND ", $where);

// Numeric filters via prepared params
$types = '';
$params = [];
if (!is_null($min_price)) { $types .= 'd'; $params[] = $min_price; $where_sql .= " AND price >= ?"; }
if (!is_null($max_price)) { $types .= 'd'; $params[] = $max_price; $where_sql .= " AND price <= ?"; }
if (!is_null($min_cap))   { $types .= 'i'; $params[] = $min_cap;   $where_sql .= " AND capacity >= ?"; }

// ---------- COUNT for pagination ----------
$count_sql = "SELECT COUNT(*) FROM tblistings WHERE {$where_sql}";
$count_stmt = $conn->prepare($count_sql);
if (!$count_stmt) { die('Prepare failed (count).'); }

// Bind parameters for count query dynamically
if (!empty($params)) {
    // Create array with $types as first element
    $bind_params = array($types);
    // Add references to each parameter (required for bind_param)
    foreach ($params as &$param) {
        $bind_params[] = &$param;
    }
    call_user_func_array(array($count_stmt, 'bind_param'), $bind_params);
}

$count_stmt->execute();
$count_stmt->store_result();
$count_stmt->bind_result($total_rows);
$count_stmt->fetch();
$count_stmt->free_result();
$count_stmt->close();

// Log query info in debug mode
if ($APP_CONFIG['debug_mode'] ?? false) {
    error_log("Browse Listings Debug:");
    error_log("Where SQL: " . $where_sql);
    error_log("Types: " . $types);
    error_log("Params: " . print_r($params, true));
    error_log("Total Rows: " . $total_rows);
}

$total_pages = max(1, (int)ceil($total_rows / $pp));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $pp; }

// ---------- MAIN query ----------
$sql = "SELECT id, title, description, address, price, capacity, property_photos, amenities,
               total_units, occupied_units,
               (SELECT GROUP_CONCAT(DISTINCT t2.title) 
                FROM tblistings t2 
                WHERE t2.address = tblistings.address 
                  AND t2.id != tblistings.id 
                  AND t2.is_archived = 0 
                  AND t2.is_verified = 1 
                  AND (t2.verification_status IS NULL OR t2.verification_status = 'approved')
               ) as other_types_at_address
        FROM tblistings
        WHERE {$where_sql}
        ORDER BY {$order_by}
        LIMIT ?, ?";
$stmt = $conn->prepare($sql);
if (!$stmt) { die('Prepare failed (main).'); }

// Bind parameters for main query dynamically
$types_main = $types . 'ii'; // Add types for LIMIT ?, ?
$params_main = array_merge($params, [$offset, $pp]);

// Use call_user_func_array for dynamic parameter binding
if (!empty($params_main)) {
    // Create array with $types_main as first element
    $bind_params = array($types_main);
    // Add references to each parameter (required for bind_param)
    foreach ($params_main as &$param) {
        $bind_params[] = &$param;
    }
    call_user_func_array(array($stmt, 'bind_param'), $bind_params);
}

try {
    $stmt->execute();
} catch (Exception $e) {
    if ($APP_CONFIG['debug_mode'] ?? false) {
        error_log("Query execution error: " . $e->getMessage());
        error_log("SQL: " . $sql);
        error_log("Types: " . $types_main);
        error_log("Params: " . print_r($params_main, true));
    }
    $errors[] = "An error occurred while fetching listings. Please try again.";
}
$stmt->store_result();
$stmt->bind_result($id, $title, $description, $address, $price, $capacity, $property_photos, $amenities, $total_units, $occupied_units, $other_types_at_address);

$listings = [];
while ($stmt->fetch()) {
  $photos_array = [];
  if (!empty($property_photos)) {
    $photos_array = json_decode($property_photos, true) ?: [];
  }
  $amenities_array = [];
  if (!empty($amenities)) {
    $amenities_array = array_map('trim', explode(',', $amenities));
  }
  $other_types = $other_types_at_address ? explode(',', $other_types_at_address) : [];
  $available_units = max(0, (int)$total_units - (int)$occupied_units);
  $location_tokens = hb_extract_location_tokens($address);
  $listings[] = compact('id','title','description','address','price','capacity','photos_array','amenities_array',
                       'total_units','occupied_units','available_units','other_types','location_tokens');
}
$stmt->free_result();
$stmt->close();

// helpers
function build_qs($overrides = []) {
  $qs = $_GET;
  unset($qs['page']);
  foreach ($overrides as $k=>$v) { $qs[$k] = $v; }
  return htmlspecialchars(http_build_query($qs));
}

// Location suggestions removed - users can now type freely

// Define all available amenities with their display labels
$ALL_AMENITIES = [
  'wifi' => 'Wi-Fi', 
  'parking' => 'Parking', 
  'aircon' => 'Air Conditioning',
  'kitchen' => 'Kitchen', 
  'laundry' => 'Laundry', 
  'furnished' => 'Furnished',
  'elevator' => 'Elevator', 
  'security' => 'Security/CCTV', 
  'balcony' => 'Balcony',
  'gym' => 'Gym', 
  'pool' => 'Pool', 
  'pet_friendly' => 'Pet Friendly',
  'bathroom' => 'Bathroom', 
  'sink' => 'Sink',
  'electricity' => 'Electricity (Submeter)', 
  'water' => 'Water (Submeter)'
];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Browse Listings • HanapBahay</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <link rel="stylesheet" href="darkmode.css" />
  <style>
    body { background:#f7f7fb; }
    .topbar { position: sticky; top:0; z-index: 1000; background: #8B4513; color: #fff; }
    .logo { height:42px; }
    .card-listing { border: 1px solid #eee; }
    .price { font-weight: 700; font-size: 1.1rem; }
    .badge-cap { background: #f1c64f; color:#222; }
    .search-row .form-control, .search-row .form-select { height: 44px; }
    a.card:hover { text-decoration:none; border-color:#8B4513; }
    .listing-location-tags .badge {
      background: #f8f9fa;
      color: #374151;
      border: 1px solid rgba(139, 69, 19, 0.2);
      font-size: 0.7rem;
    }
    
    /* Amenity filter styles - matching the image design exactly */
    .amenity-filters {
      background: transparent;
      border: none;
      padding: 0;
      margin-bottom: 24px;
    }
    
    .amenity-header {
      margin-bottom: 16px;
    }
    
    .amenity-title {
      font-size: 1.1rem;
      font-weight: 700;
      color: #374151;
      margin: 0;
    }
    
    .amenity-columns {
      display: grid;
      grid-template-columns: repeat(6, 1fr);
      gap: 16px;
      padding: 0;
    }
    
    .amenity-column {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    
    .amenity-item {
      background: transparent;
      border: none;
      padding: 0;
      display: flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
      transition: none;
      box-shadow: none;
      min-height: auto;
    }
    
    .amenity-item:hover {
      background: transparent;
      border: none;
      transform: none;
      box-shadow: none;
    }
    
    .amenity-checkbox {
      width: 16px;
      height: 16px;
      margin: 0;
      cursor: pointer;
      accent-color: #3b82f6;
      flex-shrink: 0;
      border: 1px solid #d1d5db;
      background: white;
    }
    
    .amenity-checkbox:checked {
      accent-color: #3b82f6;
      border-color: #3b82f6;
    }
    
    .amenity-label {
      font-size: 0.9rem;
      font-weight: 400;
      color: #374151;
      cursor: pointer;
      margin: 0;
      flex: 1;
      user-select: none;
      line-height: 1.2;
    }
    
    .amenity-item:has(.amenity-checkbox:checked) {
      background: transparent;
      border: none;
    }
    
    .amenity-item:has(.amenity-checkbox:checked) .amenity-label {
      color: #374151;
      font-weight: 400;
    }
    
    /* Dark mode adjustments */
    [data-theme="dark"] .amenity-title {
      color: var(--text-primary);
    }
    
    [data-theme="dark"] .amenity-label {
      color: var(--text-primary);
    }
    
    [data-theme="dark"] .amenity-checkbox {
      background: var(--bg-primary);
      border-color: var(--border-color);
    }
    
    [data-theme="dark"] .amenity-checkbox:checked {
      accent-color: var(--hb-gold);
      border-color: var(--hb-gold);
    }
    
    /* Responsive adjustments */
    @media (max-width: 1400px) {
      .amenity-columns {
        grid-template-columns: repeat(5, 1fr);
      }
    }
    
    @media (max-width: 1200px) {
      .amenity-columns {
        grid-template-columns: repeat(4, 1fr);
      }
    }
    
    @media (max-width: 992px) {
      .amenity-columns {
        grid-template-columns: repeat(3, 1fr);
      }
    }
    
    @media (max-width: 768px) {
      .amenity-columns {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
      }
      
      .amenity-checkbox {
        width: 14px;
        height: 14px;
      }
      
      .amenity-label {
        font-size: 0.85rem;
      }
    }
    
    @media (max-width: 576px) {
      .amenity-columns {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <nav class="topbar py-2">
    <div class="container d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-3">
        <img src="Assets/Logo1.png" class="logo" alt="HanapBahay" style="height:42px;">
      </div>
      <div class="d-flex align-items-center gap-2">
        <a href="index.php" class="btn btn-outline-light btn-sm">
          <i class="bi bi-house"></i> Home
        </a>
        <a href="LoginModule.php" class="btn btn-outline-light btn-sm">Login</a>
      </div>
    </div>
  </nav>

  <main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-3">
      <div class="mb-2">
        <h1 class="h4 mb-1">Browse Properties</h1>
        <p class="text-muted mb-0">You can view properties without logging in. Log in when you want to apply or message an owner.</p>
      </div>

      <!-- Sort dropdown -->
      <form method="get" class="mb-2">
        <?php foreach (['location','min_price','max_price','min_cap'] as $keep): ?>
          <?php if (isset($_GET[$keep]) && $_GET[$keep] !== ''): ?>
            <input type="hidden" name="<?= $keep ?>" value="<?= htmlspecialchars($_GET[$keep]) ?>">
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!empty($selected_amenities)): ?>
          <?php foreach ($selected_amenities as $amenity): ?>
            <input type="hidden" name="amenities[]" value="<?= htmlspecialchars($amenity) ?>">
          <?php endforeach; ?>
        <?php endif; ?>
        <label class="form-label small mb-1">Sort by</label>
        <select name="sort" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="newest"        <?= $sort==='newest'?'selected':'' ?>>Newest</option>
          <option value="price_asc"     <?= $sort==='price_asc'?'selected':'' ?>>Price: Low to High</option>
          <option value="price_desc"    <?= $sort==='price_desc'?'selected':'' ?>>Price: High to Low</option>
          <option value="capacity_desc" <?= $sort==='capacity_desc'?'selected':'' ?>>Capacity: High to Low</option>
        </select>
      </form>
    </div>

    <!-- Filters -->
    <form method="get" class="search-row row g-3 mb-3 align-items-end">
      <div class="col-12 col-lg-5 col-xl-4">
        <label class="form-label mb-1">Location / Landmark</label>
        <input type="text" name="location" class="form-control" placeholder="City, barangay, university, or landmark" value="<?= htmlspecialchars($location_query) ?>">
      </div>
      <div class="col-6 col-lg-3 col-xl-2">
        <label class="form-label mb-1">Min price (₱)</label>
        <input type="text" inputmode="decimal" name="min_price" class="form-control" value="<?= $min_price !== null ? htmlspecialchars(number_format((float)$min_price, 2, '.', '')) : '' ?>">
      </div>
      <div class="col-6 col-lg-3 col-xl-2">
        <label class="form-label mb-1">Max price (₱)</label>
        <input type="text" inputmode="decimal" name="max_price" class="form-control" value="<?= $max_price !== null ? htmlspecialchars(number_format((float)$max_price, 2, '.', '')) : '' ?>">
      </div>
      <div class="col-6 col-lg-2 col-xl-2">
        <label class="form-label mb-1">Min capacity</label>
        <input type="number" name="min_cap" class="form-control" value="<?= $min_cap !== null ? htmlspecialchars($min_cap) : '' ?>">
      </div>
      <div class="col-6 col-lg-2 col-xl-2 d-grid">
        <button class="btn btn-dark" type="submit" style="height:44px;"><i class="bi bi-search"></i></button>
      </div>
      <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
    </form>

    <!-- Amenity Filters -->
    <div class="amenity-filters mb-4">
      <div class="amenity-header mb-3">
        <h5 class="amenity-title">Filter by Amenities</h5>
      </div>
      <form method="get" id="amenity-form">
        <!-- Preserve other filters -->
        <?php foreach (['location','min_price','max_price','min_cap','sort'] as $keep): ?>
          <?php if (isset($_GET[$keep]) && $_GET[$keep] !== ''): ?>
            <input type="hidden" name="<?= $keep ?>" value="<?= htmlspecialchars($_GET[$keep]) ?>">
          <?php endif; ?>
        <?php endforeach; ?>
        
        <div class="amenity-columns">
          <?php
          // Define the specific amenities for each column as shown in the image
          $amenity_columns = [
            // Column 1: Wi-Fi, Elevator, Bathroom
            ['wifi', 'elevator', 'bathroom'],
            // Column 2: Parking, Security/CCTV, Sink
            ['parking', 'security', 'sink'],
            // Column 3: Air Conditioning, Balcony, Electricity (Submeter)
            ['aircon', 'balcony', 'electricity'],
            // Column 4: Kitchen, Gym, Water (Submeter)
            ['kitchen', 'gym', 'water'],
            // Column 5: Laundry, Pool
            ['laundry', 'pool'],
            // Column 6: Furnished, Pet Friendly
            ['furnished', 'pet_friendly']
          ];
          
          // Render each column
          foreach ($amenity_columns as $column_index => $column_amenities): ?>
            <div class="amenity-column">
              <?php foreach ($column_amenities as $amenity_key): ?>
                <?php if (isset($ALL_AMENITIES[$amenity_key])): ?>
                  <div class="amenity-item">
                    <input class="amenity-checkbox" type="checkbox" 
                           name="amenities[]" 
                           value="<?= htmlspecialchars($amenity_key) ?>" 
                           id="amenity-<?= $amenity_key ?>"
                           <?= in_array($amenity_key, $selected_amenities) ? 'checked' : '' ?>
                           onchange="document.getElementById('amenity-form').submit()">
                    <label class="amenity-label" for="amenity-<?= $amenity_key ?>">
                      <?= htmlspecialchars($ALL_AMENITIES[$amenity_key]) ?>
                    </label>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </form>
    </div>

    <!-- Active Filters Display -->
    <?php if (!empty($location_query) || $min_price !== null || $max_price !== null || $min_cap !== null || !empty($selected_amenities)): ?>
      <div class="alert alert-light border mb-3">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <strong><i class="bi bi-funnel"></i> Active Filters:</strong>
            <?php if ($location_query !== ''): ?>
              <span class="badge bg-primary ms-2">Location: "<?= htmlspecialchars($location_query) ?>"</span>
            <?php endif; ?>
            <?php if ($min_price !== null || $max_price !== null): ?>
              <span class="badge bg-success ms-2">
                Price: <?= $min_price !== null ? '&#8369;'.number_format($min_price, 0) : 'Any' ?> - <?= $max_price !== null ? '&#8369;'.number_format($max_price, 0) : 'Any' ?>
              </span>
            <?php endif; ?>
            <?php if ($min_cap !== null): ?>
              <span class="badge bg-info ms-2">Min Capacity: <?= $min_cap ?></span>
            <?php endif; ?>
            <?php if (!empty($selected_amenities)): ?>
              <?php foreach ($selected_amenities as $amenity_key): ?>
                <span class="badge bg-warning ms-2"><?= htmlspecialchars($ALL_AMENITIES[$amenity_key] ?? $amenity_key) ?></span>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <a href="browse_listings.php" class="btn btn-sm btn-outline-secondary">Clear All</a>
        </div>
      </div>
    <?php endif; ?>

    <?php if (count($listings) === 0): ?>
      <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> No properties matched your filters. Try adjusting your search criteria.
      </div>
    <?php else: ?>
      <div class="text-muted mb-3">
        <small>Showing <?= count($listings) ?> of <?= $total_rows ?> properties</small>
      </div>
    <?php endif; ?>

    <div class="row g-3">
      <?php foreach ($listings as $l): ?>
        <div class="col-12 col-md-6 col-lg-4">
          <a class="card card-listing h-100 p-0" href="listing_details.php?id=<?= (int)$l['id'] ?>" style="text-decoration: none; color: inherit;">
            <?php if (!empty($l['photos_array'])): ?>
              <img src="<?= htmlspecialchars($l['photos_array'][0]) ?>"
                   alt="<?= htmlspecialchars($l['title']) ?>"
                   style="width: 100%; height: 200px; object-fit: cover; border-radius: 8px 8px 0 0;">
            <?php else: ?>
              <div style="width: 100%; height: 200px; background: #e9ecef; display: flex; align-items: center; justify-content: center; border-radius: 8px 8px 0 0;">
                <i class="bi bi-image" style="font-size: 3rem; color: #adb5bd;"></i>
              </div>
            <?php endif; ?>
            <div class="p-3">
              <h2 class="h6 mb-2">
                <?= htmlspecialchars($l['title']) ?>
                <span class="badge <?= $l['available_units'] > 0 ? 'bg-success' : 'bg-danger' ?>">
                  <?= $l['available_units'] ?>/<?= $l['total_units'] ?> Available
                </span>
              </h2>
              <div class="mb-2 text-muted small">
                <div><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($l['address']) ?></div>
                <div class="mt-1">
                  <i class="bi bi-info-circle"></i> 
                  <?= $l['available_units'] ?> out of <?= $l['total_units'] ?> units available for rent
                </div>
              </div>

              <?php if (!empty($l['other_types'])): ?>
              <div class="mb-2 small">
                <span class="text-success"><i class="bi bi-house"></i> Also available here:</span>
                <?php foreach ($l['other_types'] as $type): ?>
                  <span class="badge bg-light text-dark border me-1"><?= htmlspecialchars($type) ?></span>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

              <?php if (!empty($l['location_tokens'])): ?>
                <div class="listing-location-tags mb-2">
                  <?php foreach (array_slice($l['location_tokens'], 0, 3) as $token): ?>
                    <span class="badge me-1 mb-1"><?= htmlspecialchars($token) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php if (!empty($l['amenities_array'])): ?>
                <div class="mb-2" style="font-size: 0.75rem;">
                  <?php
                  $display_amenities = array_slice($l['amenities_array'], 0, 3);
                  foreach ($display_amenities as $amenity):
                  ?>
                    <span class="badge bg-secondary me-1 mb-1"><?= htmlspecialchars(ucfirst($amenity)) ?></span>
                  <?php endforeach; ?>
                  <?php if (count($l['amenities_array']) > 3): ?>
                    <span class="badge bg-light text-dark border me-1">+<?= count($l['amenities_array']) - 3 ?> more</span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <div class="d-flex justify-content-between align-items-center mt-auto">
                <span class="price">₱<?= number_format((float)$l['price'], 2) ?>/month</span>
                <span class="badge badge-cap">Cap: <?= (int)$l['capacity'] ?></span>
              </div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
      <nav class="mt-4">
        <ul class="pagination justify-content-center">
          <?php
            $prev = max(1, $page-1);
            $next = min($total_pages, $page+1);

            // Prev
            echo '<li class="page-item '.($page==1?'disabled':'').'"><a class="page-link" href="browse_listings.php?'.build_qs(['page'=>$prev]).'">&laquo;</a></li>';

            if ($total_pages <= 7) {
              for ($p=1; $p<=$total_pages; $p++) {
                $active = $p==$page ? ' active' : '';
                echo '<li class="page-item'.$active.'"><a class="page-link" href="browse_listings.php?'.build_qs(['page'=>$p]).'">'.$p.'</a></li>';
              }
            } else {
              $window = 2;
              $start = max(1, $page-$window);
              $end   = min($total_pages, $page+$window);

              // first
              echo '<li class="page-item'.($page==1?' active':'').'"><a class="page-link" href="browse_listings.php?'.build_qs(['page'=>1]).'">1</a></li>';
              if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';

              for ($p=$start; $p<=$end; $p++) {
                if ($p==1 || $p==$total_pages) continue;
                $active = $p==$page ? ' active' : '';
                echo '<li class="page-item'.$active.'"><a class="page-link" href="browse_listings.php?'.build_qs(['page'=>$p]).'">'.$p.'</a></li>';
              }

              if ($end < $total_pages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
              // last
              echo '<li class="page-item'.($page==$total_pages?' active':'').'"><a class="page-link" href="browse_listings.php?'.build_qs(['page'=>$total_pages]).'">'.$total_pages.'</a></li>';
            }

            // Next
            echo '<li class="page-item '.($page==$total_pages?'disabled':'').'"><a class="page-link" href="browse_listings.php?'.build_qs(['page'=>$next]).'">&raquo;</a></li>';
          ?>
        </ul>
      </nav>
    <?php endif; ?>

    <div class="mt-3 text-center text-muted small">
      Showing page <?= (int)$page ?> of <?= (int)$total_pages ?> • <?= (int)$total_rows ?> result<?= $total_rows==1?'':'s' ?>
    </div>
  </main>

  <script src="darkmode.js"></script>
</body>
</html>







