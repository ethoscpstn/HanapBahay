<?php
session_start();
require 'mysql_connect.php';
require_once 'includes/navigation.php';

if (!isset($_SESSION['owner_id'])) {
    header("Location: LoginModule.php");
    exit();
}
$owner_id = (int)$_SESSION['owner_id'];

// Handle success message for updated listings
if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $_SESSION['show_success_popup'] = true;
    $_SESSION['success_message'] = 'Listing updated successfully!';
}

/** Owner name (for header) */
$stmt = $conn->prepare("SELECT first_name, last_name FROM tbadmin WHERE id = ?");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$res  = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();
$full_name = $user ? ($user['first_name'] . ' ' . $user['last_name']) : 'Unit Owner';

/** Optional thread from QS */
$thread_id = isset($_GET['thread_id']) ? (int)$_GET['thread_id'] : 0;
$counterparty_name = 'Tenant';

/** Owner's listings (cards + map) - Show ALL statuses: pending, approved, rejected */
$listings = [];
$stmt = $conn->prepare("SELECT *, verification_status, rejection_reason FROM tblistings WHERE owner_id = ? AND is_deleted = 0 ORDER BY created_at DESC");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$q = $stmt->get_result();
while ($row = $q->fetch_assoc()) $listings[] = $row;
$stmt->close();

$active_listings = array_filter($listings, function ($l) {
    return (int)($l['is_archived'] ?? 0) === 0 && (($l['verification_status'] ?? 'pending') !== 'rejected');
});
$archived_listings = array_filter($listings, function ($l) {
    return (int)($l['is_archived'] ?? 0) === 1;
});

/** Threads for this owner: show tenant name + listing title */
$threads = [];
$sql = "
SELECT t.id AS thread_id,
       l.title AS listing_title,
       ta.first_name, ta.last_name, ta.id AS tenant_id
FROM chat_threads t
JOIN tblistings l            ON l.id = t.listing_id
JOIN chat_participants cpo   ON cpo.thread_id = t.id AND cpo.user_id = ? AND cpo.role = 'owner'
JOIN chat_participants cpt   ON cpt.thread_id = t.id AND cpt.role = 'tenant'
JOIN tbadmin ta              ON ta.id = cpt.user_id
ORDER BY t.id DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$rs = $stmt->get_result();
while ($row = $rs->fetch_assoc()) {
    $row['display_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $threads[] = $row;
}
$stmt->close();

/** If a thread is selected, show tenant name as the header */
if ($thread_id > 0) {
    $stmt = $conn->prepare("
        SELECT ta.first_name, ta.last_name
        FROM chat_threads t
        JOIN chat_participants cpo ON cpo.thread_id = t.id AND cpo.user_id = ? AND cpo.role = 'owner'
        JOIN chat_participants cpt ON cpt.thread_id = t.id AND cpt.role = 'tenant'
        JOIN tbadmin ta            ON ta.id = cpt.user_id
        WHERE t.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $owner_id, $thread_id);
    $stmt->execute();
    $stmt->bind_result($fn, $ln);
    if ($stmt->fetch()) {
        $counterparty_name = trim(($fn ?? '') . ' ' . ($ln ?? '')) ?: 'Tenant';
    }
    $stmt->close();
}

/** Fetch rental requests for dashboard preview */
// Show pending plus resolved requests (non-dismissed) in dashboard
$rental_requests = [];
$stmt = $conn->prepare("
    SELECT rr.id, rr.tenant_id, rr.listing_id, rr.status, rr.payment_option,
           rr.amount_due, rr.amount_to_pay, rr.requested_at,
           l.title AS property_title, l.price AS property_price,
           t.first_name AS tenant_first_name, t.last_name AS tenant_last_name
    FROM rental_requests rr
    JOIN tblistings l ON l.id = rr.listing_id
    JOIN tbadmin t ON t.id = rr.tenant_id
    WHERE l.owner_id = ?
      AND rr.status IN ('pending', 'approved', 'rejected', 'cancelled')
      AND (rr.is_dismissed IS NULL OR rr.is_dismissed = 0)
    ORDER BY rr.requested_at DESC
    LIMIT 5
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    // Calculate amount_due if not set
    $paymentOption = $row['payment_option'] ?? 'full';
    if (empty($row['amount_due']) || $row['amount_due'] == 0) {
        $propertyPrice = (float)($row['property_price'] ?? 0);
        $row['amount_due'] = $paymentOption === 'half' ? ($propertyPrice / 2) : $propertyPrice;
    }
    if (empty($row['amount_to_pay']) || $row['amount_to_pay'] == 0) {
        $row['amount_to_pay'] = $row['amount_due'];
    }
    $rental_requests[] = $row;
}
$stmt->close();

// Get pending count separately for badge
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM rental_requests rr JOIN tblistings l ON l.id = rr.listing_id WHERE l.owner_id = ? AND rr.status = 'pending'");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$pending_result = $stmt->get_result()->fetch_assoc();
$pending_count = (int)$pending_result['count'];
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Owner Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="DashboardUO.css?v=24" />
  <link rel="stylesheet" href="darkmode.css" />
</head>
<body class="bg-soft">

  <?= getNavigationForRole('DashboardUO.php') ?>

  <script>
    window.HB_CURRENT_USER_ID = <?php echo (int)$owner_id; ?>;
    window.HB_CURRENT_USER_ROLE = "owner";
    window.HB_THREAD_ID_FROM_QS = <?php echo (int)$thread_id; ?>;
  </script>

  <div id="pageContent" class="flex-grow-1 pt-5 mt-5">
    <header class="dashboard-header px-4 py-3 d-flex justify-content-between align-items-center">
      <h5 class="m-0 text-dark">Welcome back, <?= htmlspecialchars($full_name) ?>!</h5>
      <span class="badge bg-orange text-white">Owner</span>
    </header>

    <main class="p-4">
      <div class="container-fluid px-3">
        
        <!-- Tab Navigation -->
        <ul class="nav nav-tabs mb-4" id="dashboardTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="dashboard-tab" data-bs-toggle="tab" data-bs-target="#dashboard-content" type="button" role="tab">
              <i class="bi bi-house"></i> Dashboard
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="analytics-tab-link" data-bs-toggle="tab" data-bs-target="#analytics-content" type="button" role="tab">
              <i class="bi bi-graph-up"></i> Analytics
            </button>
          </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="dashboardTabsContent">
          <!-- Dashboard Tab -->
          <div class="tab-pane fade show active" id="dashboard-content" role="tabpanel">

        <!-- Dashboard Content -->

        <!-- Summary cards -->
        <div class="row mb-4" id="activity-section">
          <div class="col-lg-12 mb-3">
            <div class="card shadow-sm h-100">
              <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> Recent Activity</h5>
                <div class="d-flex gap-2 align-items-center">
                  <?php if ($pending_count > 0): ?>
                    <span class="badge bg-warning text-dark"><?= $pending_count ?> Pending</span>
                  <?php endif; ?>
                  <?php if (!empty($rental_requests)): ?>
                    <button class="btn btn-sm btn-light" onclick="clearHistory()">
                      <i class="bi bi-trash"></i> Clear History
                    </button>
                  <?php endif; ?>
                </div>
              </div>
              <div class="card-body p-0">
                <?php if (empty($rental_requests)): ?>
                  <div class="p-4 text-center text-muted">
                    <i class="bi bi-inbox icon-lg"></i>
                    <p class="mb-0 mt-2">No recent activity</p>
                    <?php if ($pending_count > 0): ?>
                      <p class="small mb-0 mt-1">You have <?= $pending_count ?> pending request<?= $pending_count > 1 ? 's' : '' ?>. <a href="rental_requests_uo.php">View all</a></p>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <div class="list-group list-group-flush">
                    <?php foreach ($rental_requests as $req): ?>
                      <?php
                      $tenantName = trim($req['tenant_first_name'] . ' ' . $req['tenant_last_name']);
                      $statusClass = 'warning';
                      if ($req['status'] === 'approved') $statusClass = 'success';
                      if ($req['status'] === 'rejected') $statusClass = 'danger';
                      if ($req['status'] === 'cancelled') $statusClass = 'secondary';
                      $timeAgo = date('M d, g:i A', strtotime($req['requested_at']));
                      ?>
                      <a href="rental_requests_uo.php#request-<?= $req['id'] ?>"
                         class="list-group-item list-group-item-action">
                        <div class="d-flex justify-content-between align-items-start">
                          <div class="flex-grow-1">
                            <h6 class="mb-1"><?= htmlspecialchars($req['property_title']) ?></h6>
                            <p class="mb-1 text-muted small">
                              <i class="bi bi-person"></i> <?= htmlspecialchars($tenantName) ?>
                            </p>
                            <small class="text-muted">
                              <i class="bi bi-clock"></i> <?= $timeAgo ?>
                            </small>
                          </div>
                          <div class="text-end">
                            <span class="badge bg-<?= $statusClass ?> mb-1"><?= ucfirst($req['status']) ?></span>
                            <div class="text-success fw-bold">₱<?= number_format($req['amount_due'], 2) ?></div>
                          </div>
                        </div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                  <div class="card-footer text-center bg-light">
                    <a href="rental_requests_uo.php" class="btn btn-sm btn-outline-primary">
                      <i class="bi bi-clock-history"></i> View Full History (Including Pending)
                    </a>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- All Listings (Merged) -->
        <div class="row g-4 mb-4" id="listings-section">
          <div class="col-12">
            <div class="card">
              <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <span>All Property Listings</span>
                <?php if (count($archived_listings) > 0): ?>
                  <a href="#archivedListings" class="btn btn-sm btn-light">
                    <i class="bi bi-archive"></i> View Archived (<?= count($archived_listings) ?>)
                  </a>
                <?php endif; ?>
              </div>
              <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                <?php 
                $rejected = array_filter($listings, fn($l) => ($l['verification_status'] ?? '') === 'rejected');
                $has_active_or_rejected = count($active_listings) > 0 || count($rejected) > 0;
                $has_any_listings = count($listings) > 0;
                ?>
                
                <?php if ($has_active_or_rejected): ?>
                  <!-- Active Listings -->
                  <?php if (count($active_listings) > 0): ?>
                    <h6 class="text-success mb-3"><i class="bi bi-check-circle"></i> Active Listings</h6>
                    <?php foreach ($active_listings as $listing): ?>
                      <?php
                      $verificationStatus = $listing['verification_status'] ?? 'pending';
                      $statusBadge = '';
                      if ($verificationStatus === 'approved') {
                          $statusBadge = '<span class="badge bg-success">Approved</span>';
                      } elseif ($verificationStatus === 'pending') {
                          $statusBadge = '<span class="badge bg-warning text-dark">Pending Verification</span>';
                      }
                      ?>
                      <div class="border border-success rounded p-3 mb-3" data-listing-id="<?= $listing['id'] ?>">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                          <h6 class="mb-0"><?= htmlspecialchars($listing['title']) ?></h6>
                          <?= $statusBadge ?>
                        </div>
                        <p class="mb-1"><strong>Capacity:</strong> <?= (int)$listing['capacity'] ?> person(s)</p>
                        <p class="mb-1">
                          <strong>Units Available:</strong>
                          <?php
                            $availableUnits = max(0, (int)($listing['total_units'] ?? 0) - (int)($listing['occupied_units'] ?? 0));
                            $totalUnits = max(1, (int)($listing['total_units'] ?? 1));
                          ?>
                          <?= $availableUnits ?> / <?= $totalUnits ?>
                        </p>
                        <p class="mb-1"><strong>Address:</strong> <?= htmlspecialchars($listing['address']) ?></p>
                        <p class="mb-1"><strong>Price:</strong> ₱<?= number_format($listing['price'], 0) ?></p>
                        <div class="d-flex flex-wrap gap-2">
                          <a href="edit_listing.php?id=<?= $listing['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                          <?php if ($verificationStatus === 'approved'): ?>
                            <button class="btn btn-sm btn-outline-warning" 
                                    onclick="archiveListing(<?= $listing['id'] ?>, '<?= htmlspecialchars($listing['title'], ENT_QUOTES) ?>')">Archive</button>
                          <?php endif; ?>
                          <a href="delete_listing.php?id=<?= $listing['id'] ?>" class="btn btn-sm btn-outline-danger"
                             onclick="return confirm('Delete this listing permanently?');">Delete</a>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>

                  <!-- Rejected Listings -->
                  <?php if (count($rejected) > 0): ?>
                    <h6 class="text-danger mb-3 mt-4"><i class="bi bi-x-circle"></i> Rejected Listings</h6>
                    <?php foreach ($rejected as $listing): ?>
                      <div class="border border-danger rounded p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                          <h6 class="mb-0"><?= htmlspecialchars($listing['title']) ?></h6>
                          <span class="badge bg-danger">Rejected</span>
                        </div>
                        <p class="mb-1"><strong>Reason:</strong> <span class="text-danger"><?= htmlspecialchars($listing['rejection_reason'] ?? 'Not specified') ?></span></p>
                        <p class="mb-1"><strong>Price:</strong> ₱<?= number_format($listing['price'], 0) ?></p>
                        <div class="d-flex flex-wrap gap-2">
                          <a href="DashboardAddUnit.php?resubmit=<?= $listing['id'] ?>" class="btn btn-sm btn-warning">Resubmit</a>
                          <a href="delete_listing.php?id=<?= $listing['id'] ?>" class="btn btn-sm btn-outline-danger"
                             onclick="return confirm('Delete this listing permanently?');">Delete</a>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                <?php elseif ($has_any_listings && count($archived_listings) > 0): ?>
                  <!-- All listings are archived -->
                  <div class="text-center text-muted py-4">
                    <i class="bi bi-archive icon-lg"></i>
                    <p class="mb-0 mt-2">All your listings are currently archived</p>
                    <p class="small mb-0 mt-1">You can restore them from the archived section below or add new properties</p>
                    <div class="d-flex gap-2 justify-content-center mt-3">
                      <a href="#archivedListings" class="btn btn-outline-secondary">
                        <i class="bi bi-archive"></i> View Archived Listings
                      </a>
                      <a href="DashboardAddUnit.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Add New Property
                      </a>
                    </div>
                  </div>
                <?php else: ?>
                  <!-- No listings at all -->
                  <div class="text-center text-muted py-4">
                    <i class="bi bi-house-door icon-lg"></i>
                    <p class="mb-0 mt-2">No property listings yet</p>
                    <p class="small mb-0 mt-1">Start by adding your first property</p>
                    <a href="DashboardAddUnit.php" class="btn btn-primary mt-3">
                      <i class="bi bi-plus-circle"></i> Add Property
                    </a>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Archived Listings (Separate Section) -->
        <?php if (count($archived_listings) > 0): ?>
        <div class="row g-4 mb-4">
          <div class="col-12" id="archivedListings">
            <div class="card border-secondary">
              <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                <span>Archived Listings</span>
                <a href="#listings-section" class="btn btn-sm btn-light">
                  <i class="bi bi-arrow-up"></i> Back to Active
                </a>
              </div>
              <div class="card-body">
                <?php foreach ($archived_listings as $listing): ?>
                  <div class="border rounded p-2 mb-3">
                    <h6 class="mb-1"><?= htmlspecialchars($listing['title']) ?></h6>
                    <p class="mb-1"><strong>Capacity:</strong> <?= (int)$listing['capacity'] ?> person(s)</p>
                    <p class="mb-1">
                      <strong>Units Available:</strong>
                      <?php
                        $availableUnits = max(0, (int)($listing['total_units'] ?? 0) - (int)($listing['occupied_units'] ?? 0));
                        $totalUnits = max(1, (int)($listing['total_units'] ?? 1));
                      ?>
                      <?= $availableUnits ?> / <?= $totalUnits ?>
                    </p>
                    <p class="mb-1"><strong>Address:</strong> <?= htmlspecialchars($listing['address']) ?></p>
                    <p class="mb-1"><strong>Price:</strong> ₱<?= number_format($listing['price'], 0) ?></p>
                    <div class="d-flex flex-wrap gap-2">
                      <a href="DashboardAddUnit.php?restore=<?= $listing['id'] ?>" class="btn btn-sm btn-outline-success">Restore &amp; Resubmit</a>
                      <a href="delete_listing.php?id=<?= $listing['id'] ?>" class="btn btn-sm btn-outline-danger"
                         onclick="return confirm('Delete this listing permanently?');">Delete</a>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Map -->
        <section class="mb-4" id="home-section">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">My Property Locations</h6>
            <div class="d-flex gap-3 small">
              <span><span class="text-success-custom">●</span> Available</span>
              <span><span class="text-danger-custom">●</span> Unavailable</span>
              <span><span class="text-secondary-custom">●</span> Archived</span>
            </div>
          </div>
          <div id="map" class="border rounded" style="height: 400px;"></div>
        </section>

          </div>
          <!-- End Dashboard Tab -->

          <!-- Analytics Tab -->
          <div class="tab-pane fade" id="analytics-content" role="tabpanel">
        <section id="analytics-section">
          <div class="mb-4">
            <h5 class="mb-3"><i class="bi bi-graph-up"></i> Rental Market Analytics</h5>

            <!-- Summary Cards -->
            <div class="row g-3 mb-4">
              <div class="col-md-3">
                <div class="card border-primary">
                  <div class="card-body">
                    <h6 class="text-muted small mb-2" id="owner-avg-price-label">Your Avg Price</h6>
                    <h3 class="mb-0" id="owner-avg-price">₱0</h3>
                    <small class="text-muted" id="price-status"></small>
                  </div>
                </div>
              </div>
              <div class="col-md-3">
                <div class="card border-success">
                  <div class="card-body">
                    <h6 class="text-muted small mb-2" id="market-avg-price-label">Market Avg Price</h6>
                    <h3 class="mb-0" id="market-avg-price">₱0</h3>
                    <small class="text-muted" id="market-avg-note">Overall market</small>
                  </div>
                </div>
              </div>
              <div class="col-md-3">
                <div class="card border-warning">
                  <div class="card-body">
                    <h6 class="text-muted small mb-2">Peak Month</h6>
                    <h3 class="mb-0" id="peak-month">-</h3>
                    <small class="text-muted" id="peak-count">0 listings</small>
                  </div>
                </div>
              </div>
              <div class="col-md-3">
                <div class="card border-info">
                  <div class="card-body">
                    <h6 class="text-muted small mb-2">Total Listings</h6>
                    <h3 class="mb-0" id="total-listings">0</h3>
                    <small class="text-muted">Last 12 months</small>
                  </div>
                </div>
              </div>
            </div>

            <!-- Charts -->
            <div class="row g-4">
              <!-- Demand Trends Chart -->
              <div class="col-lg-6">
                <div class="card">
                  <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="bi bi-graph-up-arrow"></i> Demand Trends</h6>
                  </div>
                  <div class="card-body">
                    <canvas id="demandChart" height="200"></canvas>
                  </div>
                </div>
              </div>

              <!-- Price Trends Chart -->
              <div class="col-lg-6">
                <div class="card">
                  <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="bi bi-currency-dollar"></i> Price Trends</h6>
                  </div>
                  <div class="card-body">
                    <canvas id="priceChart" height="200"></canvas>
                  </div>
                </div>
              </div>

              <!-- Location Pricing Chart -->
              <div class="col-lg-6">
                <div class="card">
                  <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="bi bi-geo-alt"></i> Top Locations by Avg Price</h6>
                  </div>
                  <div class="card-body">
                    <canvas id="locationChart" height="250"></canvas>
                  </div>
                </div>
              </div>

              <!-- Competitive Pricing Chart -->
              <div class="col-lg-6">
                <div class="card">
                  <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-award"></i> Your Pricing vs Market</h6>
                    <select id="competitive-type-select" class="form-select form-select-sm" style="max-width: 220px;">
                      <option value="">All property types</option>
                    </select>
                  </div>
                  <div class="card-body">
                    <canvas id="competitiveChart" height="250"></canvas>
                    <div id="no-listings-msg" class="text-center text-muted py-4 analytics-hidden">
                      <i class="bi bi-info-circle icon-lg"></i>
                      <p class="mt-2">Add listings to see competitive analysis</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Market Positioning (Comparables) Section -->
            <div class="row g-4 mt-2" id="comparables-section">
              <div class="col-12">
                <div class="card">
                  <div class="card-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                      <h6 class="mb-0"><i class="bi bi-diagram-3"></i> Market Positioning & Comparables</h6>
                      <select id="comp-listing-select" class="form-select form-select-sm" style="max-width: 300px;">
                        <option value="">Select a listing to compare...</option>
                        <?php foreach ($listings as $l): ?>
                          <?php if ((int)$l['is_archived'] === 0): ?>
                            <option value="<?= (int)$l['id'] ?>"><?= htmlspecialchars($l['title']) ?></option>
                          <?php endif; ?>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="card-body">
                    <div id="comps-loading" class="text-center py-4 text-muted analytics-hidden">
                      <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                      </div>
                      <p class="mt-2">Loading comparables...</p>
                    </div>

                    <div id="comps-empty" class="text-center py-4 text-muted">
                      <i class="bi bi-info-circle icon-lg"></i>
                      <p class="mt-2">Select a listing above to see how it compares to similar properties</p>
                    </div>

                    <div id="comps-content" class="analytics-hidden">
                      <!-- Market Position Summary -->
                      <div class="row g-3 mb-4">
                        <div class="col-md-3">
                          <div class="border rounded p-3">
                            <div class="small text-muted">Market Rank</div>
                            <h4 class="mb-0" id="comp-rank">-</h4>
                            <small class="text-muted" id="comp-percentile"></small>
                          </div>
                        </div>
                        <div class="col-md-3">
                          <div class="border rounded p-3">
                            <div class="small text-muted">Avg Competitor Price</div>
                            <h4 class="mb-0" id="comp-avg-price">₱0</h4>
                            <small id="comp-status" class="badge"></small>
                          </div>
                        </div>
                        <div class="col-md-3">
                          <div class="border rounded p-3">
                            <div class="small text-muted">Price Difference</div>
                            <h4 class="mb-0" id="comp-price-diff">₱0</h4>
                            <small class="text-muted" id="comp-price-diff-pct"></small>
                          </div>
                        </div>
                        <div class="col-md-3">
                          <div class="border rounded p-3">
                            <div class="small text-muted">Comparables Found</div>
                            <h4 class="mb-0" id="comp-count">0</h4>
                            <small class="text-muted">Similar properties</small>
                          </div>
                        </div>
                      </div>

                      <!-- Recommendations -->
                      <div id="comp-recommendations" class="mb-4"></div>

                      <!-- Comparables Table -->
                      <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0">Similar Properties</h6>
                        <div class="d-flex align-items-center gap-2">
                          <label class="form-label small mb-0">Sort by:</label>
                          <select id="comp-sort-select" class="form-select form-select-sm" style="width: 180px;">
                            <option value="price_asc">Price: Low to High</option>
                            <option value="price_desc">Price: High to Low</option>
                            <option value="title_asc">Property: A-Z</option>
                            <option value="title_desc">Property: Z-A</option>
                            <option value="capacity_asc">Capacity: Low to High</option>
                            <option value="capacity_desc">Capacity: High to Low</option>
                            <option value="bedrooms_asc">Bedrooms: Low to High</option>
                            <option value="bedrooms_desc">Bedrooms: High to Low</option>
                            <option value="size_asc">Size: Small to Large</option>
                            <option value="size_desc">Size: Large to Small</option>
                            <option value="status_asc">Status: Available First</option>
                            <option value="status_desc">Status: Unavailable First</option>
                          </select>
                        </div>
                      </div>
                      <div class="table-responsive">
                        <table class="table table-hover" id="comp-table">
                          <thead>
                            <tr>
                              <th>Property</th>
                              <th>Location</th>
                              <th>Price</th>
                              <th>Capacity</th>
                              <th>Bedrooms</th>
                              <th>Size (sqm)</th>
                              <th>Price/sqm</th>
                              <th>Status</th>
                            </tr>
                          </thead>
                          <tbody id="comp-table-body">
                          </tbody>
                        </table>
                      </div>

                      <!-- Amenities Comparison -->
                      <div class="mt-4">
                        <h6 class="mb-3">Amenities Analysis</h6>
                        <div id="comp-amenities"></div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </section>
          </div>
        </div>

        </div>
      </div>
    </main>
  </div>

  <!-- ===== Floating Chat Widget (Owner) ===== -->
  <div id="hb-chat-widget" class="hb-chat-widget">
    <div id="hb-chat-header" class="hb-chat-header-bar">
      <span><i class="bi bi-chat-dots"></i> Messages</span>
      <button id="hb-toggle-btn" class="hb-btn-ghost">_</button>
    </div>
    <div id="hb-chat-body-container" class="hb-chat-body-container">
      <div class="d-flex align-items-center justify-content-between mb-2 px-2 pt-2">
        <select id="hb-thread-select" class="form-select form-select-sm min-w-260">
          <option value="0" selected>Select a conversation…</option>
          <?php foreach ($threads as $t): ?>
            <?php
              $name = $t['display_name'] ?: 'Tenant';
              $label = $name . ' — ' . ($t['listing_title'] ?: 'Listing');
              $dataName = htmlspecialchars($name, ENT_QUOTES);
            ?>
            <option value="<?= (int)$t['thread_id'] ?>" data-name="<?= $dataName ?>"><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-secondary" id="hb-clear-chat">Clear</button>
      </div>

      <div id="hb-chat" class="hb-chat-container">
        <div class="hb-chat-header">
          <div class="hb-chat-title">
            <span class="hb-dot"></span>
            <strong id="hb-counterparty"><?= htmlspecialchars($counterparty_name) ?></strong>
          </div>
        </div>
        <div id="hb-chat-body" class="hb-chat-body">
          <div id="hb-history-sentinel" class="hb-history-sentinel">
            <?= ($thread_id ? 'Loading…' : 'Select a conversation to view messages') ?>
          </div>
          <div id="hb-messages" class="hb-messages" aria-live="polite"></div>
        

<!-- Quick Replies (Owner Saved Replies) -->
<div id="hb-quick-replies" class="hb-quick-replies" aria-label="Saved replies"></div>

</div>
        <form id="hb-send-form" class="hb-chat-input" autocomplete="off">
          <textarea id="hb-input" rows="1" placeholder="Type a message… (Press Enter to send)" required <?= $thread_id ? '' : 'disabled' ?>></textarea>
          <button id="hb-send" type="submit" class="hb-btn" <?= $thread_id ? '' : 'disabled' ?>>Send</button>
        </form>
      </div>
    </div>
  </div>
  <!-- ======================================== -->

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://js.pusher.com/8.2/pusher.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <script src="js/chat.js?v=20250216"></script>

  <script>
    // Show success popup if set in session
    <?php if (isset($_SESSION['show_success_popup']) && $_SESSION['show_success_popup']): ?>
      document.addEventListener('DOMContentLoaded', function() {
        const successMessage = <?= json_encode($_SESSION['success_message'] ?? 'Operation successful!') ?>;
        const modal = document.createElement('div');
        modal.innerHTML = `
          <div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <div class="modal-header bg-success text-white">
                  <h5 class="modal-title" id="successModalLabel"><i class="bi bi-check-circle"></i> Success!</h5>
                  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center py-4">
                  <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
                  <p class="mt-3 mb-0">${successMessage}</p>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-success" data-bs-dismiss="modal">OK</button>
                </div>
              </div>
            </div>
          </div>
        `;
        document.body.appendChild(modal);
        const successModal = new bootstrap.Modal(document.getElementById('successModal'));
        successModal.show();
      });
      <?php
        unset($_SESSION['show_success_popup']);
        unset($_SESSION['success_message']);
      ?>
    <?php endif; ?>

    // Collapse / expand floating widget
    document.addEventListener("DOMContentLoaded", () => {
      const widget = document.getElementById("hb-chat-widget");
      const toggleBtn = document.getElementById("hb-toggle-btn");

      // Start collapsed by default
      widget.classList.add("collapsed");
      if (toggleBtn) toggleBtn.textContent = "▴";

      document.getElementById("hb-chat-header").addEventListener("click", (e)=>{
        if (e.target.id !== 'hb-toggle-btn') {
          widget.classList.toggle("collapsed");
          if (toggleBtn) toggleBtn.textContent = widget.classList.contains("collapsed") ? "▴" : "_";
        }
      });
      toggleBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        widget.classList.toggle("collapsed");
        toggleBtn.textContent = widget.classList.contains("collapsed") ? "▴" : "_";
      });

      // Function to expand chat when new message arrives
      window.expandChatOnNewMessage = function() {
        if (widget.classList.contains("collapsed")) {
          widget.classList.remove("collapsed");
          if (toggleBtn) toggleBtn.textContent = "_";
        }
      };
    });

    // Initialize chat with the new module
    (() => {
      const chatInstance = initializeChat({
        threadId: <?= (int)$thread_id ?>,
        counterparty: <?= json_encode($counterparty_name) ?>,
        bodyEl: document.getElementById('hb-chat-body'),
        msgsEl: document.getElementById('hb-messages'),
        inputEl: document.getElementById('hb-input'),
        formEl: document.getElementById('hb-send-form'),
        sendBtn: document.getElementById('hb-send'),
        sentinel: document.getElementById('hb-history-sentinel'),
        counterpartyEl: document.getElementById('hb-counterparty'),
        threadSelect: document.getElementById('hb-thread-select'),
        clearBtn: document.getElementById('hb-clear-chat'),
        role: 'owner'
      });
      
      // Store chat instance globally for debugging
      window.hbChatInstance = chatInstance;
      console.log('Owner chat initialized with threadId:', <?= (int)$thread_id ?>);
      console.log('Owner chat instance:', chatInstance);
      
      // Add Enter key functionality to send messages
      const inputEl = document.getElementById('hb-input');
      const formEl = document.getElementById('hb-send-form');
      
      if (inputEl && formEl) {
        inputEl.addEventListener('keydown', function(e) {
          // Check if Enter key is pressed (without Shift)
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault(); // Prevent default behavior (new line)
            
            // Only send if input has content and is not disabled
            if (inputEl.value.trim() && !inputEl.hasAttribute('disabled')) {
              console.log('Sending message via Enter key (owner dashboard)');
              formEl.dispatchEvent(new Event('submit'));
            }
          }
        });
        
        // Also handle Ctrl+Enter as alternative send (common in chat apps)
        inputEl.addEventListener('keydown', function(e) {
          if (e.key === 'Enter' && e.ctrlKey) {
            e.preventDefault();
            if (inputEl.value.trim() && !inputEl.hasAttribute('disabled')) {
              console.log('Sending message via Ctrl+Enter (owner dashboard)');
              formEl.dispatchEvent(new Event('submit'));
            }
          }
        });
        
        console.log('Enter key functionality added to chat input (owner dashboard)');
      }
    })();

    // Owner map
    function initOwnerMap(){
    const el = document.getElementById('map');
    if (!el) return;

    const map = new google.maps.Map(el, {
      center: { lat: 14.5995, lng: 120.9842 },  // fallback
      zoom: 12,                                  // fallback
      gestureHandling: "greedy",
      scrollwheel: true,
      mapTypeControl: false,
      streetViewControl: true,
      fullscreenControl: true
    });

    // Ensure only one InfoWindow is open
    let activeInfoWindow = null;

    // Close any open bubble when clicking the map background
    map.addListener('click', () => {
      if (activeInfoWindow) { activeInfoWindow.close(); activeInfoWindow = null; }
    });

    const listings = <?= json_encode($listings); ?>;
    const bounds   = new google.maps.LatLngBounds();
    let markerCount = 0;
    let lastPos = null;

    // Show all listings on map (including archived ones)
    (listings || []).forEach(item => {
      const lat = parseFloat(item.latitude), lng = parseFloat(item.longitude);
      if (Number.isNaN(lat) || Number.isNaN(lng)) return;

      const pos = { lat, lng };
      lastPos = pos; markerCount++;

      // Choose marker color based on archived status
      let markerColor;
      if (parseInt(item.is_archived) === 1) {
        markerColor = "gray"; // Archived properties
      } else if (String(item.is_available) === '1') {
        markerColor = "green"; // Available properties
      } else {
        markerColor = "red"; // Occupied properties
      }

      const marker = new google.maps.Marker({
        map,
        position: pos,
        title: item.title || '',
        icon: { url: `https://maps.google.com/mapfiles/ms/icons/${markerColor}-dot.png` }
      });

      // Determine status text
      let statusText;
      if (parseInt(item.is_archived) === 1) {
        statusText = '<span class="text-secondary-custom">Archived</span>';
      } else if (String(item.is_available) === '1') {
        statusText = '<span class="text-success-custom">Available</span>';
      } else {
        statusText = '<span class="text-danger-custom">Occupied</span>';
      }

      const info = new google.maps.InfoWindow({
        content: `
          <div>
            <h6>${(item.title || '').toString()}</h6>
            <p><strong>Address:</strong> ${(item.address || '').toString()}</p>
            <p><strong>Price:</strong> ₱${Number(item.price || 0).toLocaleString()}</p>
            <p><strong>Status:</strong> ${statusText}</p>
          </div>`
      });

      marker.addListener('click', () => {
        if (activeInfoWindow) activeInfoWindow.close();
        info.open(map, marker);
        activeInfoWindow = info;
      });

      bounds.extend(pos);
    });

    if (markerCount === 0) {
      // keep fallback center/zoom
      return;
    } else if (markerCount === 1 && lastPos) {
      map.setCenter(lastPos);
      map.setZoom(16);
    } else if (markerCount > 1) {
      map.fitBounds(bounds);
      google.maps.event.addListenerOnce(map, "bounds_changed", () => {
        if (map.getZoom() > 15) map.setZoom(15);
      });
    } else {
      // No listings with valid coordinates - show default location
      map.setCenter({ lat: 14.5995, lng: 120.9842 });
      map.setZoom(12);
      
      // Add a message marker if no properties exist
      if (listings.length === 0) {
        const infoWindow = new google.maps.InfoWindow({
          content: '<div class="text-center"><h6>No Properties Yet</h6><p>Add your first property to see it on the map</p></div>',
          position: { lat: 14.5995, lng: 120.9842 }
        });
        infoWindow.open(map);
      }
    }
  }

  // expose for Google callback
  window.initOwnerMap = initOwnerMap;
</script>

<!-- Make sure your loader calls the callback -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?= 'AIzaSyCrKcnAX9KOdNp_TNHwWwzbLSQodgYqgnU' ?>&callback=initOwnerMap" async defer></script>
<script>
(function(){
  const container = document.getElementById('hb-quick-replies');
  const inputEl   = document.getElementById('hb-input');
  const formEl    = document.getElementById('hb-send-form');
  const sendBtn   = document.getElementById('hb-send');
  if(!container || !inputEl || !formEl) return;

  const PROMPTS = ['Hi! Yes, it’s available. When would you like to view?', 'Viewing hours: Mon–Sat, 10am–6pm. Please share your preferred time.', 'Payment terms: 1 month advance + 1 month deposit. GCash/QR accepted.', 'Inclusions: Wi‑Fi and water included; electricity billed separately.', 'Please share your target move‑in date and number of occupants.'];

  if (!container.dataset.rendered) {
    PROMPTS.slice(0,5).forEach(text=>{
      const b=document.createElement('button');
      b.type='button';
      b.className='hb-qr-btn';
      b.textContent=text;
      b.addEventListener('click',()=>handleQuick(text));
      container.appendChild(b);
    });
    container.dataset.rendered = '1';
  }

  function syncVisibility(){
    container.style.display = inputEl.hasAttribute('disabled') ? 'none' : '';
  }

  syncVisibility();
  const mo = new MutationObserver(syncVisibility);
  mo.observe(inputEl, { attributes: true, attributeFilter: ['disabled'] });

  function handleQuick(text){
    inputEl.value = inputEl.value.trim()
      ? (inputEl.value.trim() + "
" + text)
      : text;

    if (!inputEl.hasAttribute('disabled')) {
      if (sendBtn) sendBtn.disabled = true;
      formEl.requestSubmit ? formEl.requestSubmit() : formEl.submit();
    } else {
      inputEl.focus();
    }
  }
})();
</script>

<script>
// Clear rental request history
async function clearHistory() {
  if (!confirm('Clear all viewed requests from this section? This will hide approved, rejected, and cancelled requests. Pending requests will still be visible in the full history.')) {
    return;
  }

  try {
    const response = await fetch('clear_request_history.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      }
    });

    const data = await response.json();

    if (data.success) {
      // Reload the page to show updated list
      window.location.reload();
    } else {
      alert('Failed to clear history: ' + (data.error || 'Unknown error'));
    }
  } catch (error) {
    console.error('Error clearing history:', error);
    alert('Failed to clear history. Please try again.');
  }
}

// Archive listing function
async function archiveListing(listingId, listingTitle) {
  if (!confirm(`Archive "${listingTitle}"? Tenants won't see it.`)) {
    return;
  }

  try {
    const response = await fetch(`archive_listing.php?id=${listingId}`, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
      }
    });

    const data = await response.json();

    if (data.success) {
      // Show success message
      showSuccessMessage(data.message || 'Listing archived successfully!');
      
      // Remove the listing from the active listings section
      const listingElement = document.querySelector(`[data-listing-id="${listingId}"]`);
      if (listingElement) {
        listingElement.remove();
      }
      
      // Update the archived listings count and link
      updateArchivedListingsCount();
      
      // Reinitialize the map with updated listings
      setTimeout(() => {
        if (window.initOwnerMap) {
          window.initOwnerMap();
        }
      }, 100);
      
    } else {
      alert('Failed to archive listing: ' + (data.error || 'Unknown error'));
    }
  } catch (error) {
    console.error('Error archiving listing:', error);
    alert('Failed to archive listing. Please try again.');
  }
}

// Show success message
function showSuccessMessage(message) {
  const modal = document.createElement('div');
  modal.innerHTML = `
    <div class="modal fade" tabindex="-1" style="display: block;">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-body text-center py-4">
            <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
            <h5 class="mt-3 mb-0">${message}</h5>
          </div>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
  
  setTimeout(() => {
    modal.remove();
  }, 2000);
}

// Update archived listings count
function updateArchivedListingsCount() {
  const archivedLink = document.querySelector('a[href="#archivedListings"]');
  if (archivedLink) {
    const currentCount = parseInt(archivedLink.textContent.match(/\d+/)?.[0] || '0');
    archivedLink.innerHTML = `<i class="bi bi-archive"></i> View Archived (${currentCount + 1})`;
  }
}
</script>

<!-- Analytics Tab Switching & Charts -->
<script>
(function() {
  const analyticsTabLink = document.getElementById('analytics-tab-link');
  const homeSection = document.getElementById('home-section');
  const analyticsSection = document.getElementById('analytics-section');
  const activitySection = document.getElementById('activity-section');
  const listingsSection = document.getElementById('listings-section');

  let analyticsLoaded = false;
  let analyticsSelectedType = '';
  let charts = {};

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
      green: dark ? '#10b981' : '#10b981',
      red: dark ? '#ef4444' : '#ef4444',
      blue: dark ? '#3b82f6' : '#3b82f6',
      orange: dark ? '#f59e0b' : '#f59e0b'
    };
  }

  // Tab switching
  analyticsTabLink.addEventListener('click', (e) => {
    e.preventDefault();

    // Hide home sections
    if (homeSection) homeSection.style.display = 'none';
    if (activitySection) activitySection.style.display = 'none';
    if (listingsSection) listingsSection.style.display = 'none';

    // Show analytics
    analyticsSection.style.display = 'block';

    // Load analytics data on first click
    if (!analyticsLoaded) {
      loadAnalytics();
    }
  });

  // Navigate back to home
  document.querySelectorAll('.nav-link').forEach(link => {
    if (link.id !== 'analytics-tab-link') {
      link.addEventListener('click', () => {
        analyticsSection.style.display = 'none';
        if (homeSection) homeSection.style.display = 'block';
        if (activitySection) activitySection.style.display = 'block';
        if (listingsSection) listingsSection.style.display = 'flex';
      });
    }
  });

  // Load analytics data
  async function loadAnalytics(propertyType = analyticsSelectedType) {
    analyticsSelectedType = propertyType || '';
    const ownerId = window.HB_CURRENT_USER_ID || <?= (int)$owner_id ?>;

    try {
      const typeParam = analyticsSelectedType ? `&property_type=${encodeURIComponent(analyticsSelectedType)}` : '';
      const response = await fetch(`/api/price_trend.php?owner_id=${ownerId}${typeParam}`);
      const result = await response.json();

      if (!result.success) {
        throw new Error(result.error || 'Failed to load analytics');
      }

        const data = result.data;

        if (data && data.competitive_analysis) {
          analyticsSelectedType = data.competitive_analysis.selected_type || analyticsSelectedType || '';
        }

        // Update summary cards
        updateSummaryCards(data);

      // Create charts
      createDemandChart(data.demand_trends);
      createPriceChart(data.price_trends);
      createLocationChart(data.location_trends);
      createCompetitiveChart(data.competitive_analysis);

      analyticsLoaded = true;
    } catch (error) {
      console.error('Error loading analytics:', error);
      alert('Failed to load analytics data. Please try again.');
    }
  }

  function updateSummaryCards(data) {
    const competitive = data.competitive_analysis;

    if (competitive) {
      const selectedType = competitive.selected_type || '';
      const ownerAvgEl = document.getElementById('owner-avg-price');
      const marketAvgEl = document.getElementById('market-avg-price');
      const ownerLabelEl = document.getElementById('owner-avg-price-label');
      const marketLabelEl = document.getElementById('market-avg-price-label');
      const marketNoteEl = document.getElementById('market-avg-note');
      const statusEl = document.getElementById('price-status');

      if (ownerAvgEl) ownerAvgEl.textContent = '₱' + competitive.owner_avg_price.toLocaleString();
      if (marketAvgEl) marketAvgEl.textContent = '₱' + competitive.market_avg_price.toLocaleString();

      if (ownerLabelEl) ownerLabelEl.textContent = selectedType
        ? `Your Avg Price (${selectedType})`
        : 'Your Avg Price';

      if (marketLabelEl) marketLabelEl.textContent = selectedType
        ? `Market Avg Price (${selectedType})`
        : 'Market Avg Price';

      if (marketNoteEl) marketNoteEl.textContent = selectedType
        ? `${selectedType} listings market average`
        : 'Overall market';

      const diff = competitive.price_difference_pct;
      const status = competitive.status || 'at_market';

      if (status === 'no_owner_data') {
        statusEl.textContent = selectedType
          ? `Add a ${selectedType.toLowerCase()} listing to see comparisons`
          : 'Add listings to see comparisons';
        statusEl.className = 'text-muted small';
        return;
      }

      if (status === 'no_market_data') {
        statusEl.textContent = `Not enough market data${selectedType ? ` for ${selectedType}` : ''}`;
        statusEl.className = 'text-muted small';
        return;
      }

      if (status === 'no_data') {
        statusEl.textContent = 'Not enough pricing data yet';
        statusEl.className = 'text-muted small';
        return;
      }

      if (diff > 0) {
        statusEl.textContent = `${diff}% above market${selectedType ? ` for ${selectedType}` : ''}`;
        statusEl.className = 'text-danger small';
      } else if (diff < 0) {
        statusEl.textContent = `${Math.abs(diff)}% below market${selectedType ? ` for ${selectedType}` : ''}`;
        statusEl.className = 'text-success small';
      } else {
        statusEl.textContent = selectedType
          ? `At market rate for ${selectedType} listings`
          : 'At market rate';
        statusEl.className = 'text-muted small';
      }
    }

    if (data.peak_months && data.peak_months.length > 0) {
      const peak = data.peak_months[0];
      document.getElementById('peak-month').textContent = peak.month_label;
      document.getElementById('peak-count').textContent = `${peak.listing_count} listings`;
    }

    const totalListings = data.demand_trends.reduce((sum, item) => sum + item.listing_count, 0);
    document.getElementById('total-listings').textContent = totalListings;
  }

  function createDemandChart(demandData) {
    const ctx = document.getElementById('demandChart');
    if (charts.demand) charts.demand.destroy();

    const colors = getChartColors();

    charts.demand = new Chart(ctx, {
      type: 'line',
      data: {
        labels: demandData.map(d => d.month_label),
        datasets: [{
          label: 'New Listings',
          data: demandData.map(d => d.listing_count),
          borderColor: colors.brown,
          backgroundColor: isDarkMode() ? 'rgba(217, 119, 6, 0.1)' : 'rgba(139, 69, 19, 0.1)',
          tension: 0.4,
          fill: true
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: true,
            labels: { color: colors.textColor }
          },
          tooltip: { mode: 'index', intersect: false }
        },
        scales: {
          x: {
            ticks: { color: colors.textColor },
            grid: { color: colors.gridColor }
          },
          y: {
            beginAtZero: true,
            ticks: { stepSize: 1, color: colors.textColor },
            grid: { color: colors.gridColor }
          }
        }
      }
    });
  }

  function createPriceChart(priceData) {
    const ctx = document.getElementById('priceChart');
    if (charts.price) charts.price.destroy();

    const colors = getChartColors();

    charts.price = new Chart(ctx, {
      type: 'line',
      data: {
        labels: priceData.map(d => d.month_label),
        datasets: [
          {
            label: 'Average Price',
            data: priceData.map(d => d.avg_price),
            borderColor: colors.green,
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            tension: 0.4,
            fill: true
          },
          {
            label: 'Max Price',
            data: priceData.map(d => d.max_price),
            borderColor: colors.red,
            borderDash: [5, 5],
            tension: 0.4,
            fill: false
          },
          {
            label: 'Min Price',
            data: priceData.map(d => d.min_price),
            borderColor: colors.blue,
            borderDash: [5, 5],
            tension: 0.4,
            fill: false
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: true,
            labels: { color: colors.textColor }
          },
          tooltip: {
            mode: 'index',
            intersect: false,
            callbacks: {
              label: (context) => {
                return context.dataset.label + ': ₱' + context.parsed.y.toLocaleString();
              }
            }
          }
        },
        scales: {
          x: {
            ticks: { color: colors.textColor },
            grid: { color: colors.gridColor }
          },
          y: {
            beginAtZero: true,
            ticks: {
              callback: (value) => '₱' + value.toLocaleString(),
              color: colors.textColor
            },
            grid: { color: colors.gridColor }
          }
        }
      }
    });
  }

  function createLocationChart(locationData) {
    const ctx = document.getElementById('locationChart');
    if (charts.location) charts.location.destroy();

    const topLocations = locationData.slice(0, 8);
    const colors = getChartColors();

    charts.location = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: topLocations.map(d => d.location),
        datasets: [{
          label: 'Avg Price',
          data: topLocations.map(d => d.avg_price),
          backgroundColor: colors.brown,
          borderColor: isDarkMode() ? '#b45309' : '#6d3710',
          borderWidth: 1
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (context) => {
                const item = topLocations[context.dataIndex];
                return [
                  `Avg: ₱${item.avg_price.toLocaleString()}`,
                  `Range: ₱${item.min_price.toLocaleString()} - ₱${item.max_price.toLocaleString()}`,
                  `Count: ${item.count} listings`
                ];
              }
            }
          }
        },
        scales: {
          x: {
            beginAtZero: true,
            ticks: {
              callback: (value) => '₱' + value.toLocaleString(),
              color: colors.textColor
            },
            grid: { color: colors.gridColor }
          },
          y: {
            ticks: { color: colors.textColor },
            grid: { color: colors.gridColor }
          }
        }
      }
    });
  }

  function createCompetitiveChart(competitive) {
    const ctx = document.getElementById('competitiveChart');
    const noListingsMsg = document.getElementById('no-listings-msg');
    const typeSelect = document.getElementById('competitive-type-select');
    const selectedType = competitive.selected_type || '';

    if (typeSelect) {
      const types = competitive.property_types || [];
      const previousKey = typeSelect.dataset.optionsKey || '';
      const currentKey = JSON.stringify(types);

      if (previousKey !== currentKey) {
        typeSelect.innerHTML = '';
        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = 'All property types';
        typeSelect.appendChild(defaultOption);
        types.forEach(type => {
          const opt = document.createElement('option');
          opt.value = type;
          opt.textContent = type;
          typeSelect.appendChild(opt);
        });
        typeSelect.dataset.optionsKey = currentKey;
      }

      const desiredValue = selectedType;
      if (typeSelect.value !== desiredValue) {
        typeSelect.value = desiredValue;
      }

      if (!typeSelect.dataset.bound) {
        typeSelect.addEventListener('change', (event) => {
          analyticsSelectedType = event.target.value || '';
          loadAnalytics(analyticsSelectedType);
        });
        typeSelect.dataset.bound = '1';
      }
    }

    if (!competitive || !competitive.owner_listings || competitive.owner_listings.length === 0) {
      ctx.classList.add('analytics-hidden');
      noListingsMsg.classList.remove('analytics-hidden');
      if (typeSelect && selectedType.length) {
        noListingsMsg.innerHTML = `
          <i class="bi bi-info-circle icon-lg"></i>
          <p class="mt-2">No listings for ${selectedType} yet. Add one to compare.</p>
        `;
      } else {
        noListingsMsg.innerHTML = `
          <i class="bi bi-info-circle icon-lg"></i>
          <p class="mt-2">Add listings to see competitive analysis</p>
        `;
      }
      return;
    }

    ctx.classList.remove('analytics-hidden');
    noListingsMsg.classList.add('analytics-hidden');

    if (charts.competitive) charts.competitive.destroy();

    const listings = competitive.owner_listings.map(l => ({
      title: l.title.length > 25 ? l.title.substring(0, 25) + '...' : l.title,
      price: l.price
    }));

    const marketAvg = competitive.market_avg_price;
    const colors = getChartColors();

    charts.competitive = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: listings.map(l => l.title),
        datasets: [{
          label: 'Your Price',
          data: listings.map(l => l.price),
          backgroundColor: listings.map(l => l.price > marketAvg ? colors.red : colors.green),
          borderColor: listings.map(l => l.price > marketAvg ? '#dc2626' : '#059669'),
          borderWidth: 1
        }, {
          label: 'Market Average',
          data: listings.map(() => marketAvg),
          backgroundColor: colors.orange,
          borderColor: '#d97706',
          borderWidth: 1,
          type: 'line',
          pointRadius: 0,
          pointHoverRadius: 6,
          fill: false
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { 
            display: true,
            position: 'top',
            labels: {
              color: colors.textColor,
              usePointStyle: true,
              pointStyle: 'rect'
            }
          },
          tooltip: {
            callbacks: {
              label: (context) => {
                const price = context.parsed.y;
                const diff = price - marketAvg;
                const pct = ((diff / marketAvg) * 100).toFixed(1);
                
                if (context.datasetIndex === 0) {
                  // Your listings
                  return [
                    `Your Price: ₱${price.toLocaleString()}`,
                    `${diff > 0 ? '+' : ''}${pct}% vs market`
                  ];
                } else {
                  // Market average line
                  return `Market Average: ₱${price.toLocaleString()}`;
                }
              }
            }
          }
        },
        scales: {
          x: {
            ticks: { color: colors.textColor },
            grid: { color: colors.gridColor }
          },
          y: {
            beginAtZero: true,
            ticks: {
              callback: (value) => '₱' + value.toLocaleString(),
              color: colors.textColor
            },
            grid: { color: colors.gridColor }
          }
        }
      }
    });
  }

  // ========== Comparables Section ==========
  const compListingSelect = document.getElementById('comp-listing-select');
  const compsLoading = document.getElementById('comps-loading');
  const compsEmpty = document.getElementById('comps-empty');
  const compsContent = document.getElementById('comps-content');
  const compSortSelect = document.getElementById('comp-sort-select');
  let currentComparablesData = [];

  compListingSelect.addEventListener('change', async function() {
    const listingId = this.value;

    if (!listingId) {
      compsContent.classList.add('analytics-hidden');
      compsLoading.classList.add('analytics-hidden');
      compsEmpty.classList.remove('analytics-hidden');
      return;
    }

    // Show loading
    compsEmpty.classList.add('analytics-hidden');
    compsContent.classList.add('analytics-hidden');
    compsLoading.classList.remove('analytics-hidden');

    try {
      const response = await fetch(`/api/comps.php?listing_id=${listingId}`);
      const result = await response.json();

      if (!result.success) {
        throw new Error(result.error || 'Failed to load comparables');
      }

      const data = result.data;

      // Update summary cards
      document.getElementById('comp-rank').textContent = `#${data.market_position.rank}`;
      document.getElementById('comp-percentile').textContent = `Top ${data.market_position.percentile}%`;
      document.getElementById('comp-avg-price').textContent = `₱${data.market_position.avg_comp_price.toLocaleString()}`;
      document.getElementById('comp-count').textContent = data.comparables.length;

      // Status badge
      const statusBadge = document.getElementById('comp-status');
      const status = data.market_position.status;
      statusBadge.textContent = status.replace('_', ' ').toUpperCase();
      statusBadge.className = 'badge ' + (
        status === 'overpriced' ? 'bg-danger' :
        status === 'underpriced' ? 'bg-success' :
        'bg-primary'
      );

      // Price difference
      const diff = data.market_position.price_difference;
      const diffPct = data.market_position.price_difference_pct;
      document.getElementById('comp-price-diff').textContent = `₱${Math.abs(diff).toLocaleString()}`;
      document.getElementById('comp-price-diff-pct').textContent = `${diffPct > 0 ? '+' : ''}${diffPct}%`;

      // Recommendations
      const recsContainer = document.getElementById('comp-recommendations');
      recsContainer.innerHTML = '';
      data.recommendations.forEach(rec => {
        const alertClass = rec.severity === 'high' ? 'alert-danger' :
                          rec.severity === 'medium' ? 'alert-warning' :
                          'alert-info';
        const icon = rec.severity === 'high' ? 'exclamation-triangle-fill' :
                    rec.severity === 'medium' ? 'info-circle-fill' :
                    'check-circle-fill';
        recsContainer.innerHTML += `
          <div class="alert ${alertClass} d-flex align-items-center" role="alert">
            <i class="bi bi-${icon} me-2"></i>
            <div>${rec.message}</div>
          </div>
        `;
      });

      // Store comparables data for sorting
      currentComparablesData = data.comparables;

      // Populate comparables table
      populateComparablesTable();

      // Amenities analysis
      const amenitiesContainer = document.getElementById('comp-amenities');
      amenitiesContainer.innerHTML = `
        <div class="row g-3">
          <div class="col-md-6">
            <div class="border rounded p-3">
              <h6>Your Amenities</h6>
              <div class="d-flex flex-wrap gap-2">
                ${data.target_listing.amenities.length > 0 ?
                  data.target_listing.amenities.map(a => `<span class="badge bg-primary">${a}</span>`).join('') :
                  '<span class="text-muted">No amenities listed</span>'}
              </div>
              <small class="text-muted mt-2 d-block">Count: ${data.amenities_analysis.your_amenities_count}</small>
            </div>
          </div>
          <div class="col-md-6">
            <div class="border rounded p-3">
              <h6>Missing Amenities</h6>
              ${data.amenities_analysis.missing_amenities.length > 0 ? `
                <div class="d-flex flex-wrap gap-2">
                  ${data.amenities_analysis.missing_amenities.map(a =>
                    `<span class="badge bg-warning text-dark">${a.amenity} (${a.prevalence_pct}% have it)</span>`
                  ).join('')}
                </div>
                <small class="text-muted mt-2 d-block">Consider adding these to be more competitive</small>
              ` : '<span class="text-success">You have all common amenities!</span>'}
            </div>
          </div>
        </div>
        <div class="mt-3">
          <small class="text-muted">
            Avg competitor amenities: ${data.amenities_analysis.avg_comp_amenities_count}
          </small>
        </div>
      `;

      // Show content
      compsLoading.classList.add('analytics-hidden');
      compsContent.classList.remove('analytics-hidden');

    } catch (error) {
      console.error('Error loading comparables:', error);
      compsLoading.classList.add('analytics-hidden');
      compsEmpty.classList.remove('analytics-hidden');
      compsEmpty.innerHTML = `
        <div class="text-center py-4 text-danger">
          <i class="bi bi-exclamation-circle icon-lg"></i>
          <p class="mt-2">${error.message || 'Failed to load comparables'}</p>
        </div>
      `;
    }
  });

  // Sorting functionality for comparables table
  function populateComparablesTable() {
    const tableBody = document.getElementById('comp-table-body');
    tableBody.innerHTML = '';
    
    currentComparablesData.forEach(comp => {
      const row = `
        <tr>
          <td><strong>${comp.title}</strong></td>
          <td>${comp.address}</td>
          <td>₱${comp.price.toLocaleString()}</td>
          <td>${comp.capacity}</td>
          <td>${comp.bedrooms}</td>
          <td>${comp.unit_sqm || '-'}</td>
          <td>${comp.price_per_sqm ? '₱' + comp.price_per_sqm.toLocaleString() : '-'}</td>
          <td>
            <span class="badge ${comp.is_available ? 'bg-success' : 'bg-secondary'}">
              ${comp.is_available ? 'Available' : 'Unavailable'}
            </span>
          </td>
        </tr>
      `;
      tableBody.innerHTML += row;
    });
  }

  function sortComparables(sortType) {
    if (!currentComparablesData.length) return;

    const [field, direction] = sortType.split('_');
    const isAsc = direction === 'asc';

    currentComparablesData.sort((a, b) => {
      let aVal, bVal;
      
      switch (field) {
        case 'price':
          aVal = parseFloat(a.price) || 0;
          bVal = parseFloat(b.price) || 0;
          break;
        case 'title':
          aVal = (a.title || '').toLowerCase();
          bVal = (b.title || '').toLowerCase();
          break;
        case 'capacity':
          aVal = parseInt(a.capacity) || 0;
          bVal = parseInt(b.capacity) || 0;
          break;
        case 'bedrooms':
          aVal = parseInt(a.bedrooms) || 0;
          bVal = parseInt(b.bedrooms) || 0;
          break;
        case 'size':
          aVal = parseFloat(a.unit_sqm) || 0;
          bVal = parseFloat(b.unit_sqm) || 0;
          break;
        case 'status':
          aVal = a.is_available ? 1 : 0;
          bVal = b.is_available ? 1 : 0;
          break;
        default:
          return 0;
      }

      if (typeof aVal === 'string') {
        return isAsc ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
      } else {
        return isAsc ? aVal - bVal : bVal - aVal;
      }
    });

    populateComparablesTable();
  }

  // Add event listener for sorting
  if (compSortSelect) {
    compSortSelect.addEventListener('change', function() {
      sortComparables(this.value);
    });
  }

})();
</script>

<script src="darkmode.js"></script>
</body>
</html>
