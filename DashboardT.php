<?php
session_start();
require 'mysql_connect.php';
require_once 'includes/location_utils.php';
require_once 'includes/navigation.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'tenant') {
    // Debug: Log the session issue
    error_log("DashboardT.php: Invalid session - role: " . ($_SESSION['role'] ?? 'not set') . ", user_id: " . ($_SESSION['user_id'] ?? 'not set'));
    header("Location: LoginModule.php");
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$first_name = ''; $last_name = '';

// Debug: Log user_id
error_log("DashboardT.php: user_id = " . $user_id);

if ($user_id) {
    $stmt = $conn->prepare("SELECT first_name, last_name FROM tbadmin WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($first_name, $last_name);
    $stmt->fetch();
    $stmt->close();
    $_SESSION['first_name'] = $first_name;
    $_SESSION['last_name']  = $last_name;
}
$thread_id = isset($_GET['thread_id']) ? (int)$_GET['thread_id'] : 0;
$ownerName = null;
$counterparty_name = 'Owner';

// Validate thread_id if provided
if ($thread_id > 0) {
    $thread_check = $conn->prepare("SELECT 1 FROM chat_threads WHERE id = ? LIMIT 1");
    $thread_check->bind_param('i', $thread_id);
    $thread_check->execute();
    $thread_check->store_result();
    
    if ($thread_check->num_rows === 0) {
        // Thread doesn't exist, redirect to dashboard without thread_id
        header("Location: DashboardT.php");
        exit;
    }
    $thread_check->close();
}

/** Listings for map/search (stored lat/lng only) */
$listings = [];
$res = $conn->query("
  SELECT id, title, description, address, latitude, longitude, price, capacity,
         is_available, amenities, owner_id,
         bedroom, unit_sqm, kitchen, kitchen_type, gender_specific, pets,
         total_units, occupied_units,
         property_photos
  FROM tblistings
  WHERE is_archived = 0
    AND is_verified = 1
    AND is_available = 1
    AND (total_units - occupied_units) > 0
    AND (verification_status = 'approved' OR verification_status IS NULL)
  ORDER BY id DESC
");
while ($row = $res->fetch_assoc()) {
  // Decode property_photos JSON if present
  if (!empty($row['property_photos'])) {
    $row['property_photos_array'] = json_decode($row['property_photos'], true) ?: [];
  } else {
    $row['property_photos_array'] = [];
  }

  // Calculate available units
  $total = (int)($row['total_units'] ?? 1);
  $occupied = (int)($row['occupied_units'] ?? 0);
  $available = max(0, (int)($row['available_units'] ?? ($total - $occupied)));

  $row['total_units'] = $total;
  $row['available_units'] = $available;

  // Extract location tokens for search/browse enhancements
  $row['location_tokens'] = hb_extract_location_tokens($row['address'] ?? '');

  $listings[] = $row;
}

$location_suggestions = hb_collect_location_suggestions($listings);
$AMENITY_OPTIONS = [
  'wifi'             => 'Wi-Fi',
  'parking'          => 'Parking',
  'air conditioning' => 'Air Conditioning',
  'kitchen'          => 'Kitchen',
  'laundry'          => 'Laundry',
  'furnished'        => 'Furnished',
  'bathroom'         => 'Private Bathroom',
  'sink'             => 'Sink',
  'balcony'          => 'Balcony',
  'gym'              => 'Gym',
  'pool'             => 'Pool',
  'pet friendly'     => 'Pet Friendly',
  'elevator'         => 'Elevator',
  'security'         => 'Security / CCTV',
  'electricity'      => 'Electricity Submeter',
  'water'            => 'Water Submeter'
];

/** If thread selected, show owner display name + listing title in header */
if ($thread_id > 0 && $user_id) {
    $stmt = $conn->prepare("
        SELECT o.first_name, o.last_name, l.title
        FROM chat_threads t
        JOIN chat_participants pt ON pt.thread_id = t.id AND pt.user_id = ? AND pt.role = 'tenant'
        JOIN tblistings l         ON l.id = t.listing_id
        JOIN chat_participants po ON po.thread_id = t.id AND po.role = 'owner'
        JOIN tbadmin o            ON o.id = po.user_id
        WHERE t.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $user_id, $thread_id);

    // define variables first to avoid "Undefined variable" notices
    $ofn = $oln = $ltitle = null;

    $stmt->execute();
    $stmt->bind_result($ofn, $oln, $ltitle);

    if ($stmt->fetch()) {
        $ownerName = trim(($ofn ?? '').' '.($oln ?? ''));
        $counterparty_name = ($ownerName ?: 'Owner') . ((string)$ltitle !== '' ? " - $ltitle" : '');
    } else {
        // keep defaults if no row (invalid thread_id or not a participant)
        $ownerName = null;
        // $counterparty_name stays as "Owner"
    }
    $stmt->close();
}


/** Thread selector: show "OwnerName - Listing Title" */
$threads = [];
if ($user_id) {
    $stmt = $conn->prepare("
        SELECT t.id   AS thread_id,
               l.title,
               o.first_name, o.last_name
        FROM chat_threads t
        JOIN chat_participants pt ON pt.thread_id = t.id AND pt.user_id = ? AND pt.role = 'tenant'
        JOIN chat_participants po ON po.thread_id = t.id AND po.role = 'owner'
        JOIN tbadmin o            ON o.id = po.user_id
        JOIN tblistings l         ON l.id = t.listing_id
        ORDER BY t.id DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $row['owner_name'] = trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? '')) ?: 'Owner';
        $threads[] = $row;
    }
    $stmt->close();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tenant Dashboard</title>
  <link rel="icon" type="image/png" sizes="16x16" href="Assets/HanapBahayTablogo.png?v=2">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="DashboardT.css?v=26" />
  <link rel="stylesheet" href="darkmode.css" />

  <style>
    /* Ensure price panel is always visible */
    #priceComparisonPanel {
      max-height: calc(100vh - 120px);
      overflow-y: auto;
    }

    /* Center the map within its container */
    #map {
      margin: 0 auto;
      max-width: 100%;
    }

    /* Improve search bar positioning */
    .mb-4 {
      position: relative;
      z-index: 1;
    }

    /* Responsive adjustments */
    @media (max-width: 991px) {
      #priceComparisonPanel {
        position: relative !important;
        top: 0 !important;
        margin-top: 1rem;
      }
    }

    /* Smooth scrolling for price panel */
    #priceComparisonPanel .card-body {
      overflow-y: auto;
      max-height: calc(100vh - 200px);
    }

    /* Ensure consistent button and select heights */
    #radiusSelect,
    #sortSelect,
    #searchBtn {
      height: 38px !important;
      line-height: 1.5;
      padding-top: 0.375rem;
      padding-bottom: 0.375rem;
    }

    .filter-toolbar {
      border: 1px solid rgba(0, 0, 0, 0.05);
      border-radius: 14px;
      background: #ffffff;
    }

    .filter-toolbar .card-body {
      padding: 1.25rem 1.5rem;
    }

    .filter-toolbar .form-control,
    .filter-toolbar .form-select,
    .filter-toolbar .btn {
      min-height: 44px;
    }

    .filter-toolbar .form-label {
      font-weight: 600;
      color: #4b5563;
    }

    .filter-toolbar .btn-outline-secondary {
      border-color: rgba(139, 69, 19, 0.2);
    }

    .filter-toolbar .btn-outline-secondary:hover,
    .filter-toolbar .btn-outline-secondary:focus,
    .filter-toolbar .btn-outline-secondary.show {
      border-color: #8B4513;
      color: #8B4513;
      background: #fff9f3;
    }

    .amenity-dropdown .btn {
      background: #fff;
      border-color: rgba(139, 69, 19, 0.25);
    }

    .amenity-dropdown .btn:hover,
    .amenity-dropdown .btn:focus,
    .amenity-dropdown .btn.show {
      border-color: #8B4513;
      color: #8B4513;
      background: #fff9f3;
    }

    .amenities-menu {
      max-height: 260px;
      overflow-y: auto;
      width: 100%;
    }

    .amenities-menu .form-check {
      font-size: 0.9rem;
    }

    #amenitiesCount {
      font-size: 0.75rem;
      font-weight: 600;
    }

    @media (max-width: 576px) {
      .location-chip {
        font-size: 0.8rem;
      }
    }
  </style>

</head>
<body class="dashboard-bg">
  <?= getNavigationForRole('DashboardT.php') ?>

  <script>
    window.HB_CURRENT_USER_ID   = <?= (int)$user_id ?>;
    window.HB_CURRENT_USER_ROLE = "tenant";
    window.HB_THREAD_ID_FROM_QS = <?= (int)$thread_id ?>;
  </script>

  <main class="container-fluid py-5 mt-4">
    <div class="row g-4 justify-content-center">
      <!-- Main Content Area -->
      <div class="col-lg-8 col-xl-7">
    <!-- Unified Filters -->
    <div class="filter-toolbar card shadow-sm mb-4 mt-4">
      <div class="card-body">
        <form class="row g-3" id="filterForm" onsubmit="return false;">
          <div class="col-12">
            <div class="row g-3 align-items-end">
              <div class="col-12 col-xl-4">
                <label class="form-label mb-1">Location / Landmark</label>
                <input
                  id="searchBar"
                  type="text"
                  class="form-control"
                  list="popularPlacesList"
                  placeholder="Search a location or landmark (e.g., 'Makati', 'UP Town Center')"
                />
                <datalist id="popularPlacesList">
                  <?php foreach ($location_suggestions as $suggestion): ?>
                    <option value="<?= htmlspecialchars($suggestion) ?>"></option>
                  <?php endforeach; ?>
                </datalist>
              </div>
              <div class="col-6 col-md-4 col-xl-2">
                <label class="form-label mb-1">Radius</label>
                <select id="radiusSelect" class="form-select">
                  <option value="2">Within 2 km</option>
                  <option value="5" selected>Within 5 km</option>
                  <option value="10">Within 10 km</option>
                  <option value="15">Within 15 km</option>
                  <option value="20">Within 20 km</option>
                </select>
              </div>
              <div class="col-6 col-md-4 col-xl-2">
                <label class="form-label mb-1">Sort</label>
                <select id="sortSelect" class="form-select">
                  <option value="distance" selected>Sort by Distance</option>
                  <option value="price_low">Sort by Price (Low to High)</option>
                  <option value="price_high">Sort by Price (High to Low)</option>
                  <option value="capacity_desc">Sort by Capacity (High to Low)</option>
                  <option value="capacity_asc">Sort by Capacity (Low to High)</option>
                  <option value="newest">Sort by Newest</option>
                  <option value="oldest">Sort by Oldest</option>
                </select>
              </div>
              <div class="col-6 col-md-4 col-xl-2">
                <label class="form-label mb-1">Min price (₱)</label>
                <input type="text" inputmode="decimal" class="form-control" id="priceMinInput" placeholder="0">
              </div>
              <div class="col-6 col-md-4 col-xl-2">
                <label class="form-label mb-1">Max price (₱)</label>
                <input type="text" inputmode="decimal" class="form-control" id="priceMaxInput" placeholder="Any">
              </div>
            </div>
          </div>
          <div class="col-12">
            <div class="row g-3 align-items-end">
              <div class="col-6 col-md-4 col-xl-2">
                <label class="form-label mb-1">Min capacity</label>
                <input type="number" class="form-control" id="capacityMinInput" min="1" step="1" placeholder="1">
              </div>
              <div class="col-12 col-md-5 col-xl-4">
                <label class="form-label mb-1">Amenities</label>
                <div class="dropdown amenity-dropdown w-100">
                  <button class="btn btn-outline-secondary d-flex justify-content-between align-items-center w-100" type="button" id="amenitiesDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <span><i class="bi bi-sliders me-1"></i>Select amenities</span>
                    <span id="amenitiesCount" class="badge bg-light text-dark ms-2">0 selected</span>
                  </button>
                  <div class="dropdown-menu amenities-menu p-3" aria-labelledby="amenitiesDropdown">
                    <div class="row g-2">
                      <?php foreach ($AMENITY_OPTIONS as $value => $label):
                        $inputId = 'amenity-' . preg_replace('/[^a-z0-9]+/i', '-', $value);
                      ?>
                        <div class="col-12 col-sm-6">
                          <div class="form-check">
                            <input class="form-check-input amenity-item" type="checkbox" value="<?= htmlspecialchars($value) ?>" id="<?= htmlspecialchars($inputId) ?>">
                            <label class="form-check-label" for="<?= htmlspecialchars($inputId) ?>"><?= htmlspecialchars($label) ?></label>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <div class="d-flex justify-content-end pt-2">
                      <button type="button" class="btn btn-sm btn-light me-2" id="amenitiesClear">Clear</button>
                      <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="dropdown">Done</button>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-6 col-md-3 col-xl-2 d-grid">
                <label class="form-label mb-1 visually-hidden" for="searchBtn">Search</label>
                <button class="btn btn-primary" type="button" id="searchBtn"><i class="bi bi-search me-1"></i>Find</button>
              </div>
              <div class="col-6 col-md-3 col-xl-2 d-grid">
                <label class="form-label mb-1 visually-hidden" for="filterResetBtn">Clear</label>
                <button class="btn btn-outline-secondary" type="button" id="filterResetBtn">Clear</button>
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Map -->
    <div id="map" class="mb-5"></div>
    
    <!-- Unified Search Results Panel (only shown for geocoded place search) -->
    <div id="resultsPanel" style="display:none" class="mt-4">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Available Properties</h5></div>
        <div class="card-body" id="resultsContent" style="max-height: 600px; overflow-y: auto;"></div>
      </div>
    </div>
      </div>

      <!-- Price Comparison Panel (Right Side) -->
      <div class="col-lg-4 col-xl-3">
        <div id="priceComparisonPanel" style="position: sticky; top: 100px;">
          <div class="card shadow-sm">
            <div class="card-header price-analysis-header">
              <h6 class="mb-0"><i class="bi bi-graph-up-arrow"></i> Price Analysis</h6>
            </div>
            <div class="card-body">
              <div id="priceAnalysisContent">
                <div class="text-center text-muted py-4">
                  <i class="bi bi-info-circle fs-3"></i>
                  <p class="mt-2 small">Click on a property to see price analysis</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <!-- ============ FLOATING CHAT WIDGET ============ -->
  <div id="hb-chat-widget" class="hb-chat-widget">
    <div id="hb-chat-header" class="hb-chat-header-bar">
      <span><i class="bi bi-chat-dots"></i> Messages</span>
      <button id="hb-toggle-btn" class="hb-btn-ghost">_</button>
    </div>
    <div id="hb-chat-body-container" class="hb-chat-body-container">
      <div class="d-flex align-items-center justify-content-between mb-2 px-2 pt-2">
        <select id="hb-thread-select" class="form-select form-select-sm" style="min-width:240px;">
          <option value="0" selected>Select a conversation…</option>
          <?php foreach ($threads as $t): ?>
            <?php
              $label = ($t['owner_name'] ?: 'Owner') . ' — ' . ($t['title'] ?: 'Listing');
              $dataName = htmlspecialchars($t['owner_name'] ?: 'Owner', ENT_QUOTES);
            ?>
            <option value="<?= (int)$t['thread_id'] ?>" data-name="<?= $dataName ?>"><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-secondary" id="hb-clear-chat">Clear</button>
      </div>

      <div id="hb-chat" class="hb-chat-container">
        <div class="hb-chat-header">
          <div class="hb-chat-title">
            <span class="hb-dot" title="Owner status unknown"></span>
            <strong id="hb-counterparty"><?= htmlspecialchars($counterparty_name) ?></strong>
          </div>
        </div>
        <div id="hb-chat-body" class="hb-chat-body">
          <div id="hb-history-sentinel" class="hb-history-sentinel">
            <?= ($thread_id ? 'Loading…' : 'Select a conversation to view messages') ?>
          </div>
          <div id="hb-messages" class="hb-messages" aria-live="polite"></div>
        

<!-- Quick Replies (Tenant) -->
<div id="hb-quick-replies" class="hb-quick-replies" aria-label="Suggested questions"></div>

</div>
        <form id="hb-send-form" class="hb-chat-input" autocomplete="off">
          <textarea id="hb-input" rows="1" placeholder="Type a message… (Press Enter to send)" required <?= $thread_id ? '' : 'disabled' ?>></textarea>
          <button id="hb-send" type="submit" class="hb-btn" <?= $thread_id ? '' : 'disabled' ?>>Send</button>
        </form>
      </div>
    </div>
  </div>
  <!-- ============================================== -->

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://js.pusher.com/8.2/pusher.min.js"></script>
  <script src="js/chat.js?v=20250216"></script>

  <script>
    // ---------- Collapse/expand chat widget (restored) ----------
    document.addEventListener("DOMContentLoaded", () => {
      const widget = document.getElementById("hb-chat-widget");
      const toggleBtn = document.getElementById("hb-toggle-btn");
      const header = document.getElementById("hb-chat-header");

      // Start collapsed by default
      widget.classList.add("collapsed");
      if (toggleBtn) toggleBtn.textContent = "▴";

      if (header) {
        header.addEventListener("click", (e)=>{
          if (e.target && e.target.id === 'hb-toggle-btn') return; // button handles itself
          widget.classList.toggle("collapsed");
          if (toggleBtn) toggleBtn.textContent = widget.classList.contains("collapsed") ? "▴" : "_";
        });
      }
      if (toggleBtn) {
        toggleBtn.addEventListener("click", (e) => {
          e.stopPropagation();
          widget.classList.toggle("collapsed");
          toggleBtn.textContent = widget.classList.contains("collapsed") ? "▴" : "_";
        });
      }

      // Function to expand chat when new message arrives
      window.expandChatOnNewMessage = function() {
        if (widget.classList.contains("collapsed")) {
          widget.classList.remove("collapsed");
          if (toggleBtn) toggleBtn.textContent = "_";
        }
      };
    });

    // Load existing threads function
    window.loadExistingThreads = async function(preserveSelection = false) {
      try {
        console.log('Loading existing threads...');
        const response = await fetch('/api/chat/list_threads.php', {
          credentials: 'include'
        });
        
        console.log('Threads response status:', response.status);
        
        if (!response.ok) {
          throw new Error(`Failed to load threads: ${response.status}`);
        }
        
        const data = await response.json();
        console.log('Threads data:', data);
        
        if (data.threads && Array.isArray(data.threads)) {
          const threadSelect = document.getElementById('hb-thread-select');
          const currentValue = preserveSelection ? threadSelect.value : '0';
          
          console.log('Current thread select value:', currentValue);
          
          threadSelect.innerHTML = '<option value="0" selected>Select a conversation…</option>';
          
          data.threads.forEach(thread => {
            const option = document.createElement('option');
            option.value = thread.thread_id;
            option.textContent = `${thread.counterparty_name || 'Owner'} — ${thread.listing_title || 'Listing'}`;
            option.setAttribute('data-name', thread.counterparty_name || 'Owner');
            threadSelect.appendChild(option);
            console.log('Added thread option:', thread.thread_id, option.textContent);
          });
          
          if (preserveSelection && currentValue !== '0') {
            threadSelect.value = currentValue;
            console.log('Restored selection to:', currentValue);
          }
        } else {
          console.warn('No threads data or invalid format:', data);
        }
      } catch (error) {
        console.error('Failed to load threads:', error);
        console.error('Error details:', {
          message: error.message,
          stack: error.stack
        });
      }
    };

    // Test function to debug AJAX
    window.testMessageOwner = async function() {
      console.log('Testing Message Owner with listing ID 1...');
      await handleMessageOwner(1);
    };

    // Message Owner AJAX Handler
    window.handleMessageOwner = async function(listingId) {
      try {
        console.log('Starting chat for listing:', listingId);
        
        // Expand chat widget immediately
        const chatWidget = document.getElementById('hb-chat-widget');
        const toggleBtn = document.getElementById('hb-toggle-btn');
        if (chatWidget && chatWidget.classList.contains('collapsed')) {
          chatWidget.classList.remove('collapsed');
          if (toggleBtn) toggleBtn.textContent = '_';
        }
        
        // Show loading state
        const threadSelect = document.getElementById('hb-thread-select');
        if (threadSelect) {
          threadSelect.innerHTML = '<option value="0" selected>Starting conversation...</option>';
        }
        
        // Make AJAX call to start_chat.php
        console.log('Making AJAX call to start_chat.php with listing_id:', listingId);
        const response = await fetch(`start_chat.php?listing_id=${listingId}`, {
          method: 'GET',
          credentials: 'include',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        });
        
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        
        if (!response.ok) {
          const errorText = await response.text();
          console.error('Response error:', errorText);
          throw new Error(`Failed to start chat: ${response.status} ${errorText}`);
        }
        
        const responseText = await response.text();
        console.log('Raw response:', responseText);
        
        let data;
        try {
          data = JSON.parse(responseText);
          console.log('Parsed JSON data:', data);
        } catch (parseError) {
          console.error('Failed to parse JSON response:', parseError);
          console.error('Response was:', responseText);
          throw new Error('Invalid JSON response from server');
        }
        
        if (data.thread_id) {
          console.log('Chat started with thread_id:', data.thread_id);
          
          // Refresh thread list and select the new thread
          await loadExistingThreads(true);
          
          // Select the new thread
          if (threadSelect) {
            threadSelect.value = data.thread_id;
            const changeEvent = new Event('change', { bubbles: true });
            threadSelect.dispatchEvent(changeEvent);
          }
          
          // Trigger quick replies rendering
          setTimeout(() => {
            if (window.renderQuickReplies) {
              window.renderQuickReplies();
              console.log('Rendered quick replies after AJAX chat start');
            }
          }, 200);
          
        } else {
          throw new Error('No thread_id returned');
        }
        
      } catch (error) {
        console.error('Error starting chat:', error);
        console.error('Error details:', {
          message: error.message,
          stack: error.stack,
          listingId: listingId
        });
        
        // Show user-friendly error message
        alert(`Failed to start conversation: ${error.message}\n\nPlease check the browser console for more details.`);
        
        // Reset thread select
        const threadSelect = document.getElementById('hb-thread-select');
        if (threadSelect) {
          threadSelect.innerHTML = '<option value="0" selected>Select a conversation…</option>';
        }
      }
    };

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
        role: 'tenant'
      });
      
      // Store chat instance globally for debugging and external access
      window.hbChatInstance = chatInstance;
      
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
              console.log('Sending message via Enter key');
              formEl.dispatchEvent(new Event('submit'));
            }
          }
        });
        
        // Also handle Ctrl+Enter as alternative send (common in chat apps)
        inputEl.addEventListener('keydown', function(e) {
          if (e.key === 'Enter' && e.ctrlKey) {
            e.preventDefault();
            if (inputEl.value.trim() && !inputEl.hasAttribute('disabled')) {
              console.log('Sending message via Ctrl+Enter');
              formEl.dispatchEvent(new Event('submit'));
            }
          }
        });
        
        console.log('Enter key functionality added to chat input');
      }
      
      // Debug logging
      console.log('Chat initialized with threadId:', <?= (int)$thread_id ?>);
      console.log('Chat instance:', chatInstance);
      
      // Auto-select thread if thread_id is provided in URL
      if (<?= (int)$thread_id ?> > 0) {
        const threadSelect = document.getElementById('hb-thread-select');
        const chatWidget = document.getElementById('hb-chat-widget');
        const toggleBtn = document.getElementById('hb-toggle-btn');
        
        if (threadSelect) {
          threadSelect.value = <?= (int)$thread_id ?>;
          // Trigger change event to load messages
          const changeEvent = new Event('change', { bubbles: true });
          threadSelect.dispatchEvent(changeEvent);
          console.log('Auto-selected thread:', <?= (int)$thread_id ?>);
        }
        
        // Expand chat widget when thread is auto-selected
        if (chatWidget && chatWidget.classList.contains('collapsed')) {
          chatWidget.classList.remove('collapsed');
          if (toggleBtn) toggleBtn.textContent = '_';
          console.log('Auto-expanded chat widget');
        }
        
        // Trigger quick replies rendering after auto-selection
        setTimeout(() => {
          if (window.renderQuickReplies) {
            window.renderQuickReplies();
            console.log('Triggered quick replies rendering');
          }
        }, 200);
      }
    })();

    // ---------- Quick Replies Logic ----------
    (() => {
      const quickRepliesEl = document.getElementById('hb-quick-replies');
      let quickReplies = [];

      // Load quick replies from server
      async function loadQuickReplies() {
        try {
          const res = await fetch('/api/chat/get_quick_replies.php', { credentials: 'include' });
          const data = await res.json();
          if (data.ok && Array.isArray(data.quick_replies)) {
            quickReplies = data.quick_replies;
            renderQuickReplies();
            return Promise.resolve();
          }
        } catch (e) {
          console.warn('Failed to load quick replies:', e);
        }
        return Promise.resolve();
      }

      // Render quick reply buttons
      function renderQuickReplies() {
        if (!quickRepliesEl) return;

        quickRepliesEl.innerHTML = '';

        // Only show quick replies when a thread is selected and user is tenant
        const threadId = parseInt(document.getElementById('hb-thread-select')?.value || '0', 10);
        if (!threadId || window.HB_CURRENT_USER_ROLE !== 'tenant') return;

        quickReplies.forEach(reply => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'hb-qr-btn';
          btn.textContent = reply.message;
          btn.title = reply.message;
          btn.addEventListener('click', () => {
            const inputEl = document.getElementById('hb-input');
            const formEl = document.getElementById('hb-send-form');
            if (inputEl && formEl) {
              inputEl.value = reply.message;
              inputEl.focus();
              // Auto-send the quick reply
              formEl.dispatchEvent(new Event('submit'));
            }
          });
          quickRepliesEl.appendChild(btn);
        });
      }
      
      // Make renderQuickReplies globally accessible
      window.renderQuickReplies = renderQuickReplies;

      // Update quick replies visibility when thread changes
      const threadSelect = document.getElementById('hb-thread-select');
      if (threadSelect) {
        threadSelect.addEventListener('change', renderQuickReplies);
      }

      // Load quick replies on page load
      loadQuickReplies().then(() => {
        // If thread is auto-selected, render quick replies after loading
        if (<?= (int)$thread_id ?> > 0) {
          setTimeout(() => {
            if (window.renderQuickReplies) {
              window.renderQuickReplies();
              console.log('Rendered quick replies after auto-selection');
            }
          }, 100);
        }
      });
    })();
  </script>

  <script>
    // ---------- Map + Unified Search (place OR text) ----------
    const listings = <?= json_encode($listings); ?>;
    const locationSuggestions = <?= json_encode($location_suggestions); ?>;
    const priceMinInput = document.getElementById('priceMinInput');
    const priceMaxInput = document.getElementById('priceMaxInput');
    const capacityInput = document.getElementById('capacityMinInput');
    const filterResetBtn = document.getElementById('filterResetBtn');
    const amenitiesClearBtn = document.getElementById('amenitiesClear');
    const amenitiesCountEl = document.getElementById('amenitiesCount');
    const amenityChecks = document.querySelectorAll('.amenity-item');

    let map, geocoder, directionsService, directionsRenderer;
    let activeRouteListingId = null;
    let lastOriginLatLng = null;
    let lastOriginAddress = '';
    const commuteDetails = new Map();
    const crowDistanceFallback = new Map();

    let markers = [];
    let activeInfoWindow = null;
    let workplaceMarker = null;
    let LAST_NEARBY = [];

    const priceStats = (listings || []).reduce(
      (acc, item) => {
        const price = Number(item.price) || 0;
        if (price < acc.min) acc.min = price;
        if (price > acc.max) acc.max = price;
        return acc;
      },
      { min: Number.POSITIVE_INFINITY, max: 0 }
    );

    const capacityStats = (listings || []).reduce((max, item) => {
      const capacity = Number(item.capacity) || 0;
      return capacity > max ? capacity : max;
    }, 1);

    const PRICE_SLIDER_MIN = 0;
    const PRICE_SLIDER_MAX = (() => {
      if (!Number.isFinite(priceStats.max) || priceStats.max <= 0) return 10000;
      const rounded = Math.ceil(priceStats.max / 1000) * 1000;
      return Math.max(rounded, 10000);
    })();
    const PRICE_SLIDER_STEP = 500;

    const CAPACITY_SLIDER_MIN = 1;
    const CAPACITY_SLIDER_MAX = Math.max(capacityStats, CAPACITY_SLIDER_MIN);

    const filterState = {
      minPrice: PRICE_SLIDER_MIN,
      maxPrice: PRICE_SLIDER_MAX,
      minCapacity: CAPACITY_SLIDER_MIN
    };

    function formatDistanceMeters(meters) {
      if (!Number.isFinite(meters)) return '';
      if (meters >= 1000) return `${(meters / 1000).toFixed(1)} km`;
      return `${Math.round(meters)} m`;
    }

    function parseNumericInput(input, fallback = 0) {
      if (!input) return fallback;
      const raw = String(input.value || '').replace(/[^0-9.]/g, '');
      const parsed = parseFloat(raw);
      return Number.isFinite(parsed) ? parsed : fallback;
    }

    function updateFilterStateFromInputs() {
      filterState.minPrice = parseNumericInput(priceMinInput, PRICE_SLIDER_MIN);
      filterState.maxPrice = parseNumericInput(priceMaxInput, PRICE_SLIDER_MAX);
      if (filterState.maxPrice < filterState.minPrice) {
        filterState.maxPrice = filterState.minPrice;
      }
      const capacityValue = parseNumericInput(capacityInput, CAPACITY_SLIDER_MIN);
      filterState.minCapacity = Math.max(CAPACITY_SLIDER_MIN, capacityValue || CAPACITY_SLIDER_MIN);
    }

    function runActiveFilter() {
      if (workplaceMarker && lastOriginLatLng) {
        applyWorkplaceFilters();
      } else {
        applyGeneralFilter();
      }
    }

    function escapeHtml(value) {
      return (value || '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      })[ch] || ch);
    }

    function buildInfoContent(item) {
      const key = String(item.id || '');
      const commute = commuteDetails.get(key);
      const fallback = crowDistanceFallback.get(key);
      const safeTitle = escapeHtml(item.title || '');
      const safeAddress = escapeHtml(item.address || '');
      const priceLabel = '&#8369;' + Number(item.price || 0).toLocaleString();
      const statusLabel = String(item.is_available) === '1' ? 'Available' : 'Unavailable';
      const totalUnits = Number(item.total_units ?? 0);
      const availableUnits = Number(item.available_units ?? Math.max(0, totalUnits - Number(item.occupied_units ?? 0)));
      const unitsLabel = totalUnits > 0
        ? `${availableUnits} / ${totalUnits} units`
        : `${availableUnits} units`;
      let commuteHtml = '';

      if (commute && (commute.distanceText || commute.durationText)) {
        const parts = [];
        if (commute.distanceText) parts.push(escapeHtml(commute.distanceText));
        if (commute.durationText) parts.push(escapeHtml(commute.durationText));
        if (parts.length) {
          commuteHtml = `<p class="hb-commute"><strong>Commute (driving):</strong> ${parts.join(' \u2022 ')}</p>`;
        }
      } else if (fallback) {
        commuteHtml = `<p class="hb-commute"><strong>Approx distance:</strong> ${escapeHtml(fallback)}</p>`;
      }

      return `
            <div class="hb-map-popup">
              <h6>${safeTitle}</h6>
              <p><strong>Address:</strong> ${safeAddress}</p>
              <p><strong>Price:</strong> ${priceLabel}</p>
              <p><strong>Status:</strong> ${statusLabel}</p>
              <p><strong>Units:</strong> ${unitsLabel}</p>
              ${commuteHtml}
              <div class="d-flex gap-2 mt-2">
                <a href="property_details.php?id=${item.id}&ret=DashboardT" class="btn btn-sm btn-primary">More Details</a>
                <button onclick="handleMessageOwner(${item.id})" class="btn btn-sm btn-outline-secondary">Message Owner</button>
              </div>
            </div>`;
    }

    // Price Comparison Panel Functions
    async function loadPriceComparison(item) {
      const panel = document.getElementById('priceComparisonPanel');
      const content = document.getElementById('priceAnalysisContent');

      if (!panel || !content) return;

      // Show panel with loading state
      panel.style.display = 'block';
      content.innerHTML = `
        <div class="text-center py-4">
          <div class="spinner-border spinner-border-sm text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
          <p class="mt-2 small text-muted">Analyzing price...</p>
        </div>`;

      try {
        // Prepare ML input data
        const addressParts = (item.address || '').split(',');
        const location = addressParts[addressParts.length - 1]?.trim() || 'Unknown';

        const mlInput = {
          Capacity: parseInt(item.capacity) || 1,
          Bedroom: parseInt(item.bedroom) || 1,
          unit_sqm: parseFloat(item.unit_sqm) || 20,
          cap_per_bedroom: Math.round((parseInt(item.capacity) || 1) / Math.max(parseInt(item.bedroom) || 1, 1) * 100) / 100,
          Type: derivePropertyType(item.title || ''),
          Kitchen: item.kitchen || 'Yes',
          'Kitchen type': item.kitchen_type || 'Private',
          'Gender specific': item.gender_specific || 'Mixed',
          Pets: item.pets || 'Allowed',
          Location: location
        };

        // Auto-detect correct API path for localhost vs production
        const isLocalhost = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
        const apiPath = isLocalhost ? '/public_html/api/ml_suggest_price.php' : '/api/ml_suggest_price.php';
        const intervalPath = isLocalhost ? '/public_html/api/price_interval.php' : '/api/price_interval.php';

        // Helper function for fetch with timeout (90 seconds for Render cold start)
        const fetchWithTimeout = (url, options, timeout = 90000) => {
          return Promise.race([
            fetch(url, options),
            new Promise((_, reject) =>
              setTimeout(() => reject(new Error('Request timeout - ML service may be starting up')), timeout)
            )
          ]);
        };

        // Update loading message for first request (may take longer)
        content.innerHTML = `
          <div class="text-center py-4">
            <div class="spinner-border spinner-border-sm text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2 small text-muted">Analyzing price...</p>
            <p class="mt-1" style="font-size: 0.7rem; color: #6c757d;">
              <i class="bi bi-info-circle"></i> First load may take up to 60 seconds
            </p>
          </div>`;

        // Fetch both prediction and interval in parallel with extended timeout
        const [response, intervalResponse] = await Promise.all([
          fetchWithTimeout(apiPath, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ inputs: [mlInput] })
          }),
          fetchWithTimeout(intervalPath, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ inputs: [mlInput] })
          })
        ]);

        const data = await response.json();
        const intervalData = await intervalResponse.json();

        if (data.prediction) {
          const actualPrice = parseFloat(item.price) || 0;
          const mlPrice = data.prediction;
          const diffPercent = ((actualPrice - mlPrice) / mlPrice) * 100;

          let status, statusClass, statusIcon, message;
          if (diffPercent <= -10) {
            status = 'great';
            statusClass = 'success';
            statusIcon = 'bi-check-circle-fill';
            message = 'Great Deal!';
          } else if (diffPercent <= 10) {
            status = 'fair';
            statusClass = 'info';
            statusIcon = 'bi-info-circle-fill';
            message = 'Fair Price';
          } else {
            status = 'high';
            statusClass = 'warning';
            statusIcon = 'bi-exclamation-triangle-fill';
            message = 'Above Market';
          }

          const photosArray = item.property_photos_array || [];
          const mainPhoto = photosArray.length > 0 ? photosArray[0] : 'https://via.placeholder.com/300x150?text=No+Image';

          // Build price interval HTML if available
          let intervalHtml = '';
          if (intervalData && intervalData.interval) {
            const interval = intervalData.interval;
            intervalHtml = `
              <div class="mb-3 p-2" style="background: #f8f9fa; border-radius: 6px; border-left: 3px solid #17a2b8;">
                <div class="small fw-bold mb-2">
                  <i class="bi bi-graph-up"></i> Price Range (${interval.confidence}% Confidence)
                </div>
                <div class="d-flex align-items-center gap-2 mb-2">
                  <small class="text-muted">₱${interval.min.toLocaleString()}</small>
                  <div class="flex-grow-1">
                    <div class="progress" style="height: 12px;">
                      <div class="progress-bar bg-info" style="width: 100%"></div>
                    </div>
                  </div>
                  <small class="text-muted">₱${interval.max.toLocaleString()}</small>
                </div>
                <small class="text-muted d-block" style="font-size: 0.75rem;">
                  Expected range based on similar properties (±${interval.variance_factor}%)
                </small>
              </div>`;
          }

          content.innerHTML = `
            <div class="mb-3">
              <img src="${escapeHtml(mainPhoto)}" alt="${escapeHtml(item.title || '')}"
                   style="width:100%; height:120px; object-fit:cover; border-radius:8px;">
            </div>

            <h6 class="mb-2 text-truncate" title="${escapeHtml(item.title || '')}">${escapeHtml(item.title || 'Property')}</h6>

            <div class="alert alert-${statusClass} py-2 px-3 mb-3">
              <div class="d-flex align-items-center gap-2">
                <i class="bi ${statusIcon}"></i>
                <strong>${message}</strong>
              </div>
            </div>

            <div class="mb-3">
              <div class="d-flex justify-content-between mb-1">
                <span class="text-muted small">Actual Price:</span>
                <strong class="text-primary">₱${actualPrice.toLocaleString()}</strong>
              </div>
              <div class="d-flex justify-content-between mb-1">
                <span class="text-muted small">ML Predicted:</span>
                <strong>₱${mlPrice.toLocaleString()}</strong>
              </div>
              <div class="d-flex justify-content-between">
                <span class="text-muted small">Difference:</span>
                <span class="${diffPercent > 0 ? 'text-danger' : 'text-success'} fw-bold">
                  ${diffPercent > 0 ? '+' : ''}${diffPercent.toFixed(1)}%
                </span>
              </div>
            </div>

            ${intervalHtml}

            <div class="small text-muted mb-3">
              <div><i class="bi bi-people"></i> ${item.capacity || 1} capacity</div>
              <div><i class="bi bi-door-closed"></i> ${item.bedroom || 1} bedroom</div>
              <div><i class="bi bi-rulers"></i> ${parseFloat(item.unit_sqm || 20).toFixed(1)} sqm</div>
            </div>

            <div class="d-grid gap-2">
              <a href="property_details.php?id=${item.id}&ret=DashboardT" class="btn btn-sm btn-primary">
                <i class="bi bi-eye"></i> View Details
              </a>
              <button onclick="handleMessageOwner(${item.id})" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-chat-dots"></i> Message Owner
              </button>
            </div>

            <div class="mt-3 p-2 bg-light rounded small text-muted">
              <i class="bi bi-lightbulb"></i> AI prediction based on property features and market data
            </div>`;
        } else {
          throw new Error('No prediction data');
        }
      } catch (error) {
        console.error('Price analysis error:', error);
        content.innerHTML = `
          <div class="alert alert-warning py-2 px-3">
            <i class="bi bi-exclamation-triangle"></i>
            <small>Unable to load price analysis</small>
          </div>
          <div class="d-grid gap-2 mt-3">
            <a href="property_details.php?id=${item.id}&ret=DashboardT" class="btn btn-sm btn-primary">View Details</a>
          </div>`;
      }
    }

    function derivePropertyType(title) {
      const lower = (title || '').toLowerCase();
      if (lower.includes('studio')) return 'Studio';
      if (lower.includes('apartment')) return 'Apartment';
      if (lower.includes('condo')) return 'Condominium';
      if (lower.includes('house') || lower.includes('boarding')) return 'Boarding House';
      return 'Apartment';
    }

    function resetAllCommuteDisplays() {
      markers.forEach(entry => entry.info.setContent(buildInfoContent(entry.item)));
    }

    function clearRoute() {
      if (activeRouteListingId !== null) {
        commuteDetails.delete(String(activeRouteListingId));
      }
      activeRouteListingId = null;
      if (directionsRenderer) {
        try {
          directionsRenderer.setDirections({ routes: [] });
        } catch (err) {
          directionsRenderer.setDirections(null);
        }
      }
      resetAllCommuteDisplays();
    }

    function clearCommuteState() {
      commuteDetails.clear();
      crowDistanceFallback.clear();
      lastOriginLatLng = null;
      lastOriginAddress = '';
      LAST_NEARBY = [];
      clearRoute();
      resetAllCommuteDisplays();
    }

    function drawRouteTo(entry) {
      if (!directionsService || !directionsRenderer || !lastOriginLatLng) return;
      if (!entry || !entry.marker) return;
      const request = {
        origin: lastOriginLatLng,
        destination: entry.marker.getPosition(),
        travelMode: google.maps.TravelMode.DRIVING
      };
      directionsService.route(request, (result, status) => {
        if (status === 'OK' && result && result.routes && result.routes[0] && result.routes[0].legs && result.routes[0].legs[0]) {
          const leg = result.routes[0].legs[0];
          const key = String(entry.item.id || '');
          commuteDetails.set(key, {
            distanceText: leg.distance?.text || null,
            durationText: leg.duration?.text || null
          });
          directionsRenderer.setDirections(result);
          activeRouteListingId = entry.item.id;
          entry.info.setContent(buildInfoContent(entry.item));
          if (activeInfoWindow === entry.info) {
            activeInfoWindow.close();
            entry.info.open(map, entry.marker);
          }
          applyWorkplaceFilters();
        } else {
          console.warn('Directions request failed:', status);
        }
      });
    }

    function initMap() {
      map = new google.maps.Map(document.getElementById('map'), {
        center: { lat: 14.5995, lng: 120.9842 },
        zoom: 12,
        gestureHandling: 'greedy',
        scrollwheel: true,
        mapTypeControl: false,
        streetViewControl: true,
        fullscreenControl: true,
      });

      geocoder = new google.maps.Geocoder();
      directionsService = new google.maps.DirectionsService();
      directionsRenderer = new google.maps.DirectionsRenderer({
        map,
        suppressMarkers: true,
        polylineOptions: {
          strokeColor: '#8B4513',
          strokeOpacity: 0.75,
          strokeWeight: 4
        }
      });

      map.addListener('click', () => {
        if (activeInfoWindow) {
          activeInfoWindow.close();
          activeInfoWindow = null;
        }
      });

      const bounds = new google.maps.LatLngBounds();

      (listings || []).forEach(item => {
        const lat = parseFloat(item.latitude);
        const lng = parseFloat(item.longitude);
        if (Number.isNaN(lat) || Number.isNaN(lng)) return;

        const pos = { lat, lng };
        const marker = new google.maps.Marker({
          map,
          position: pos,
          title: item.title || '',
          icon: {
            url: (String(item.is_available) === '1')
              ? 'https://maps.google.com/mapfiles/ms/icons/green-dot.png'
              : 'https://maps.google.com/mapfiles/ms/icons/red-dot.png'
          }
        });

        const info = new google.maps.InfoWindow({
          content: buildInfoContent(item)
        });

        const entry = { marker, item, info };

        marker.addListener('click', () => {
          if (activeInfoWindow) activeInfoWindow.close();
          info.open(map, marker);
          activeInfoWindow = info;

          // Auto-center map on selected property with smooth animation
          map.panTo(marker.getPosition());

          // Adjust zoom if needed for better view
          if (map.getZoom() < 14) {
            map.setZoom(14);
          }

          if (workplaceMarker && lastOriginLatLng) {
            drawRouteTo(entry);
          }
          // Load price comparison
          loadPriceComparison(item);
        });
        markers.push(entry);
        bounds.extend(pos);
      });

      if (!bounds.isEmpty()) map.fitBounds(bounds);

      initUnifiedSearch();
      wireFilters();
    }

    function textMatches(item, q) {
      if (!q) return true;
      q = q.toLowerCase();
      const tokens = Array.isArray(item.location_tokens)
        ? item.location_tokens.map(token => String(token).toLowerCase())
        : [];
      return (
        (item.title || '').toLowerCase().includes(q) ||
        (item.address || '').toLowerCase().includes(q) ||
        (item.description || '').toLowerCase().includes(q) ||
        String(item.price || '').toLowerCase().includes(q) ||
        tokens.some(token => token.includes(q))
      );
    }

    function getSelectedAmenities() {
      return Array.from(document.querySelectorAll('.amenity-item'))
        .filter(input => input.checked)
        .map(input => (input.value || '').toLowerCase());
    }

    function amenitiesMatch(item, selectedAmenities) {
      if (!selectedAmenities || selectedAmenities.length === 0) return true;

      let amenityValues = [];
      if (Array.isArray(item.amenities_array)) {
        amenityValues = item.amenities_array
          .map(value => String(value || '').trim().toLowerCase())
          .filter(Boolean);
      } else if (item.amenities) {
        amenityValues = String(item.amenities)
          .split(',')
          .map(value => value.trim().toLowerCase())
          .filter(Boolean);
      }

      if (!amenityValues.length) {
        return false;
      }

      return selectedAmenities.every(needle =>
        amenityValues.some(value => value.includes(needle))
      );
    }

    function updateAmenityCount(triggerFilter = true) {
      const selected = getSelectedAmenities();
      if (amenitiesCountEl) amenitiesCountEl.textContent = `${selected.length} selected`;
      if (triggerFilter) runActiveFilter();
    }

    function numericFiltersMatch(item) {
      const price = Number(item.price) || 0;
      const capacity = Number(item.capacity) || 0;
      return (
        price >= filterState.minPrice &&
        price <= filterState.maxPrice &&
        capacity >= filterState.minCapacity
      );
    }

    function applyGeneralFilter() {
      const q = (document.getElementById('searchBar')?.value || '').toLowerCase();
      const bounds = new google.maps.LatLngBounds();
      const visibleItems = [];
      const selectedAmenities = getSelectedAmenities();
      updateFilterStateFromInputs();
      if (!workplaceMarker) clearRoute();

      let visibleCount = 0;
      markers.forEach(({ marker, item, info }) => {
        const visible = textMatches(item, q) && amenitiesMatch(item, selectedAmenities) && numericFiltersMatch(item);
        marker.setVisible(visible);
        if (!visible && activeInfoWindow === info) {
          info.close();
          activeInfoWindow = null;
        }
        if (visible) {
          bounds.extend(marker.getPosition());
          visibleCount++;
          visibleItems.push(item);
        }
      });

      // Only fit bounds if there are visible listings
      if (visibleCount > 0 && !bounds.isEmpty()) {
        map.fitBounds(bounds);
      }

      renderGeneralResults(visibleItems);
    }

    function renderGeneralResults(properties) {
      const panel = document.getElementById('resultsPanel');
      const content = document.getElementById('resultsContent');
      if (!panel || !content) return;

      const headingEl = panel.querySelector('.card-header h5');
      if (headingEl) {
        const locationText = (document.getElementById('searchBar')?.value || '').trim();
        headingEl.textContent = locationText
          ? `Available near ${escapeHtml(locationText)}`
          : 'Available Properties';
      }

      panel.style.display = 'block';

      if (!properties.length) {
        content.innerHTML = '<p class="text-muted mb-0">No properties match your filters. Try adjusting the search location, radius, or price range.</p>';
        return;
      }

      const cards = properties.map(p => {
        const safeTitle = escapeHtml(p.title || 'Untitled Property');
        const safeAddress = escapeHtml(p.address || '');
        const priceLabel = '&#8369;' + Number(p.price || 0).toLocaleString() + '/month';
        const totalUnits = Number(p.total_units ?? 0);
        const availableUnits = Number(p.available_units ?? Math.max(0, totalUnits - Number(p.occupied_units ?? 0)));
        const unitsBadge = totalUnits > 0
          ? `${availableUnits} / ${totalUnits} units`
          : `${availableUnits} units`;

        let photosHtml = '';
        if (p.property_photos_array && p.property_photos_array.length > 0) {
          const firstPhoto = escapeHtml(p.property_photos_array[0]);
          photosHtml = `<div class="mb-2"><img src="${firstPhoto}" alt="${safeTitle}" style="width:100%; height:160px; object-fit:cover; border-radius:8px;"></div>`;
        } else {
          photosHtml = `<div class="mb-2 bg-light d-flex align-items-center justify-content-center rounded" style="height:160px;">
            <i class="bi bi-building text-muted" style="font-size:2rem;"></i>
          </div>`;
        }

        const tokens = Array.isArray(p.location_tokens) ? p.location_tokens.slice(0, 3) : [];
        const tagsHtml = tokens.length
          ? `<div class="mt-2">${tokens.map(token => `<span class="badge bg-light text-dark border me-1">${escapeHtml(token)}</span>`).join('')}</div>`
          : '';
        const amenityTags = Array.isArray(p.amenities_array) ? p.amenities_array.slice(0, 3) : [];
        const amenityHtml = amenityTags.length
          ? `<div class="mt-2 small text-muted"><i class="bi bi-check-circle me-1"></i>${amenityTags.map(tag => escapeHtml(tag)).join(', ')}</div>`
          : '';

        return `
          <div class="results-item mb-3" data-listing-id="${p.id}">
            ${photosHtml}
            <div class="d-flex justify-content-between align-items-start mb-1">
              <h6 class="mb-1">${safeTitle}</h6>
              <span class="fw-semibold text-primary">${priceLabel}</span>
            </div>
            <p class="text-muted mb-1">${safeAddress}</p>
            <p class="small text-muted mb-1"><strong>Units:</strong> ${unitsBadge}</p>
            ${tagsHtml}
            ${amenityHtml}
            <div class="d-flex justify-content-between align-items-center mt-2">
              <div class="btn-group btn-group-sm">
                <a href="property_details.php?id=${p.id}&ret=DashboardT" class="btn btn-outline-primary">View Details</a>
                <button onclick="handleMessageOwner(${p.id})" class="btn btn-outline-secondary">Message Owner</button>
              </div>
            </div>
          </div>`;
      }).join('');

      content.innerHTML = cards;

      content.querySelectorAll('.results-item').forEach(card => {
        card.addEventListener('click', () => {
          const listingId = card.getAttribute('data-listing-id');
          if (!listingId) return;
          const entry = markers.find(m => String(m.item.id) === String(listingId));
          if (entry && entry.marker) {
            map.panTo(entry.marker.getPosition());
            if (map.getZoom() < 15) {
              map.setZoom(15);
            }
            google.maps.event.trigger(entry.marker, 'click');
          }
        });
      });
    }

    function applyWorkplaceFilters() {
      if (!LAST_NEARBY.length) {
        markers.forEach(({ marker }) => marker.setVisible(false));
        displayWorkplaceResults([], lastOriginAddress);
        return;
      }

      updateFilterStateFromInputs();
      const selectedAmenities = getSelectedAmenities();
      const filtered = LAST_NEARBY.filter(p => amenitiesMatch(p, selectedAmenities) && numericFiltersMatch(p));
      const idSet = new Set(filtered.map(p => String(p.id)));

      markers.forEach(({ marker, item }) => {
        marker.setVisible(idSet.has(String(item.id)));
      });

      if (activeRouteListingId && !idSet.has(String(activeRouteListingId))) {
        clearRoute();
      }

      displayWorkplaceResults(filtered, lastOriginAddress);
    }

    function displayWorkplaceResults(properties, workplaceAddress) {
      const panel = document.getElementById('resultsPanel');
      const content = document.getElementById('resultsContent');
      if (!panel || !content) return;

      const headingEl = panel.querySelector('.card-header h5');
      if (headingEl) headingEl.textContent = 'Nearest Available Properties';

      if (!properties.length) {
        content.innerHTML = '<p class="text-muted mb-0">No available properties found within the selected radius.</p>';
      } else {
        const safeOrigin = workplaceAddress ? escapeHtml(workplaceAddress) : '';
        const note = workplaceAddress
          ? `<p class="text-muted small mb-3">Routes use <strong>${safeOrigin}</strong> as the origin.</p>`
          : '';

        const cards = properties.map(p => {
          const key = String(p.id || '');
          const commute = commuteDetails.get(key);
          const fallback = crowDistanceFallback.get(key) || (Number.isFinite(p.distance) ? formatDistanceMeters(p.distance) : null);
          const distanceLabel = commute && commute.distanceText ? escapeHtml(commute.distanceText) : (fallback ? escapeHtml(fallback) : 'Distance unavailable');
          const durationLabel = commute && commute.durationText ? escapeHtml(commute.durationText) : null;
          const metaHtml = durationLabel
          ? `<p class="commute-meta mb-2">Driving estimate: ${distanceLabel} &bull; ${durationLabel}</p>`
          : '';
          const safeTitle = escapeHtml(p.title || 'Untitled Property');
          const safeAddress = escapeHtml(p.address || '');
          const priceLabel = '&#8369;' + Number(p.price || 0).toLocaleString() + '/month';
          const totalUnits = Number(p.total_units ?? 0);
          const availableUnits = Number(p.available_units ?? Math.max(0, totalUnits - Number(p.occupied_units ?? 0)));
          const unitsBadge = totalUnits > 0
            ? `${availableUnits} / ${totalUnits} units`
            : `${availableUnits} units`;
          const isActive = String(activeRouteListingId || '') === String(p.id || '');
          const cardClasses = `results-item mb-3${isActive ? ' route-active' : ''}`;

          // Property photos
          let photosHtml = '';
          if (p.property_photos_array && p.property_photos_array.length > 0) {
            const firstPhoto = escapeHtml(p.property_photos_array[0]);
            photosHtml = `<div class="mb-2"><img src="${firstPhoto}" alt="${safeTitle}" style="width:100%; height:160px; object-fit:cover; border-radius:8px;"></div>`;
          }

          const amenityTags = Array.isArray(p.amenities_array) ? p.amenities_array.slice(0, 3) : [];
          const amenityHtml = amenityTags.length
            ? `<div class="small text-muted mb-2"><i class="bi bi-check-circle me-1"></i>${amenityTags.map(tag => escapeHtml(tag)).join(', ')}</div>`
            : '';

          return `
            <div class="${cardClasses}" data-listing-id="${p.id}">
              ${photosHtml}
              <div class="d-flex justify-content-between align-items-start mb-1">
                <h6 class="mb-1">${safeTitle}</h6>
                <span class="distance-badge">${distanceLabel}</span>
              </div>
              <p class="text-muted mb-1">${safeAddress}</p>
              <p class="small text-muted mb-1"><strong>Units:</strong> ${unitsBadge}</p>
              ${metaHtml}
              ${amenityHtml}
              <div class="d-flex justify-content-between align-items-center">
                <span class="fw-semibold">${priceLabel}</span>
                <div class="btn-group btn-group-sm">
                  <a href="property_details.php?id=${p.id}&ret=DashboardT" class="btn btn-outline-primary">View Details</a>
                  <button onclick="handleMessageOwner(${p.id})" class="btn btn-primary">Message Owner</button>
                </div>
              </div>
            </div>`;
        }).join('');

        content.innerHTML = note + cards;

        content.querySelectorAll('[data-listing-id]').forEach(card => {
          card.addEventListener('click', event => {
            if (event.target.closest('a')) return;
            const listingId = card.dataset.listingId;
            if (!listingId) return;
            const entry = markers.find(m => String(m.item.id) === String(listingId));
            if (!entry) return;

            // Trigger marker click which handles centering
            google.maps.event.trigger(entry.marker, 'click');

            if (workplaceMarker && lastOriginLatLng) {
              drawRouteTo(entry);
            }
          });
        });
      }

      panel.style.display = 'block';
    }

    function geocodeAddress(address) {
      return new Promise((resolve, reject) => {
        geocoder.geocode({ address: address + ', Philippines' }, (results, status) => {
          if (status === 'OK' && results && results[0]) resolve(results[0]);
          else reject(status);
        });
      });
    }

    function initUnifiedSearch() {
      const input = document.getElementById('searchBar');
      const searchBtn = document.getElementById('searchBtn');

      if (google.maps.places && google.maps.places.Autocomplete) {
        const ac = new google.maps.places.Autocomplete(input, {
          fields: ['formatted_address', 'geometry'],
          componentRestrictions: { country: 'ph' }
        });
        ac.addListener('place_changed', () => {
          const place = ac.getPlace();
          if (place && place.geometry && place.geometry.location) {
            runWorkplaceSearch(place.formatted_address || input.value, place.geometry.location);
          } else {
            doUnifiedSearch();
          }
        });
      }

      input.addEventListener('input', () => {
        if (!input.value.trim()) {
          if (workplaceMarker) {
            workplaceMarker.setMap(null);
            workplaceMarker = null;
          }
          clearCommuteState();
          applyGeneralFilter();
        }
      });

      searchBtn?.addEventListener('click', doUnifiedSearch);
    }

    async function doUnifiedSearch() {
      const q = (document.getElementById('searchBar')?.value || '').trim();
      if (!q) {
        if (workplaceMarker) {
          workplaceMarker.setMap(null);
          workplaceMarker = null;
        }
        clearCommuteState();
        applyGeneralFilter();
        return;
      }

      try {
        const result = await geocodeAddress(q);
        const loc = result.geometry.location;
        runWorkplaceSearch(result.formatted_address || q, loc);
      } catch (err) {
        console.warn('Geocode failed:', err);
        if (workplaceMarker) {
          workplaceMarker.setMap(null);
          workplaceMarker = null;
        }
        clearCommuteState();
        applyGeneralFilter();
      }
    }

    function runWorkplaceSearch(address, locLatLng) {
      const radiusKm = parseFloat(document.getElementById('radiusSelect')?.value || '5');
      const sortBy = document.getElementById('sortSelect')?.value || 'distance';

      if (workplaceMarker) workplaceMarker.setMap(null);
      const wpLL = new google.maps.LatLng(locLatLng.lat(), locLatLng.lng());
      workplaceMarker = new google.maps.Marker({
        map,
        position: wpLL,
        title: 'Workplace/School',
        icon: { url: 'https://maps.google.com/mapfiles/ms/icons/red-dot.png' }
      });

      commuteDetails.clear();
      crowDistanceFallback.clear();
      clearRoute();

      const maxM = radiusKm * 1000;
      const nearby = [];
      markers.forEach(({ marker, item }) => {
        if (String(item.is_available) !== '1') return;
        const idKey = String(item.id);
        const distM = google.maps.geometry.spherical.computeDistanceBetween(wpLL, marker.getPosition());
        if (distM <= maxM) {
          const approxText = formatDistanceMeters(distM);
          crowDistanceFallback.set(idKey, approxText);
          commuteDetails.delete(idKey);
          nearby.push({ ...item, distance: distM, marker });
        } else {
          marker.setVisible(false);
        }
      });

      if (sortBy === 'distance') {
        nearby.sort((a, b) => a.distance - b.distance);
      } else if (sortBy === 'price_low') {
        nearby.sort((a, b) => (parseFloat(a.price) || 0) - (parseFloat(b.price) || 0));
      } else if (sortBy === 'price_high') {
        nearby.sort((a, b) => (parseFloat(b.price) || 0) - (parseFloat(a.price) || 0));
      } else if (sortBy === 'capacity_desc') {
        nearby.sort((a, b) => (parseInt(b.capacity) || 0) - (parseInt(a.capacity) || 0));
      } else if (sortBy === 'capacity_asc') {
        nearby.sort((a, b) => (parseInt(a.capacity) || 0) - (parseInt(b.capacity) || 0));
      } else if (sortBy === 'newest') {
        nearby.sort((a, b) => (parseInt(b.id) || 0) - (parseInt(a.id) || 0));
      } else if (sortBy === 'oldest') {
        nearby.sort((a, b) => (parseInt(a.id) || 0) - (parseInt(b.id) || 0));
      }

      LAST_NEARBY = nearby;

      map.setCenter(wpLL);
      map.setZoom(15);

      lastOriginLatLng = wpLL;
      lastOriginAddress = address || '';

      resetAllCommuteDisplays();
      applyWorkplaceFilters();
    }


    function wireFilters() {
      const locationInput = document.getElementById("searchBar");
      const radiusSelectEl = document.getElementById("radiusSelect");
      const sortSelectEl = document.getElementById("sortSelect");
    
      const refreshFilters = () => {
        updateFilterStateFromInputs();
        runActiveFilter();
      };
    
      [priceMinInput, priceMaxInput, capacityInput].forEach(input => {
        if (!input) return;
        input.addEventListener("change", refreshFilters);
        input.addEventListener("blur", refreshFilters);
      });
    
      amenityChecks.forEach(chk => {
        chk.addEventListener("change", () => updateAmenityCount());
      });
    
      if (amenitiesClearBtn) {
        amenitiesClearBtn.addEventListener("click", () => {
          amenityChecks.forEach(chk => (chk.checked = false));
          updateAmenityCount();
        });
      }

      if (filterResetBtn) {
        filterResetBtn.addEventListener("click", () => {
          if (locationInput) locationInput.value = "";
          if (priceMinInput) priceMinInput.value = "";
          if (priceMaxInput) priceMaxInput.value = "";
          if (capacityInput) capacityInput.value = "";
          amenityChecks.forEach(chk => (chk.checked = false));
          updateAmenityCount(false);
          updateFilterStateFromInputs();
          clearCommuteState();
          if (workplaceMarker) {
            workplaceMarker.setMap(null);
            workplaceMarker = null;
          }
          applyGeneralFilter();
        });
      }

      const refreshWorkplaceSearch = () => {
        updateFilterStateFromInputs();
        if (workplaceMarker && lastOriginLatLng) {
          runWorkplaceSearch(lastOriginAddress || "", lastOriginLatLng);
        } else {
          applyGeneralFilter();
        }
      };

      radiusSelectEl?.addEventListener("change", refreshWorkplaceSearch);
      sortSelectEl?.addEventListener("change", refreshWorkplaceSearch);

      updateFilterStateFromInputs();
      updateAmenityCount(false);
      applyGeneralFilter();
    }

    window.initMap = initMap;
  </script>
  <!-- Google Maps JS API with Places + callback -->
  <script src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode('AIzaSyCrKcnAX9KOdNp_TNHwWwzbLSQodgYqgnU') ?>&libraries=places,geometry&callback=initMap" async defer></script>
<script>
(function(){
  const container = document.getElementById('hb-quick-replies');
  const inputEl   = document.getElementById('hb-input');
  const formEl    = document.getElementById('hb-send-form');
  const sendBtn   = document.getElementById('hb-send');
  if (!container || !inputEl || !formEl) return;

  // Prompts for TENANT (use owner set in DashboardUO.php)
  const PROMPTS = [
    "What services do you offer?",
    "Is this property still available?",
    "Can I schedule a viewing?",
    "What are the payment terms?",
    "Are utilities and amenities included?"
  ];

  // 1) Render once (no early return)
  if (!container.dataset.rendered) {
    PROMPTS.forEach((text)=>{
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'hb-qr-btn';
      b.textContent = text;
      b.addEventListener('click', () => handleQuick(text));
      container.appendChild(b);
    });
    container.dataset.rendered = '1';
  }

  // 2) Show/hide chips based on whether a thread is active (input enabled)
  function syncVisibility(){
    // Hide when the textarea is disabled (no active thread), show when enabled
    container.style.display = inputEl.hasAttribute('disabled') ? 'none' : '';
  }
  syncVisibility();

  // Watch for the 'disabled' attribute to change when you click "Message Owner"
  const mo = new MutationObserver(syncVisibility);
  mo.observe(inputEl, { attributes: true, attributeFilter: ['disabled'] });

  // 3) Click -> fill; only auto-send when thread is active
  function handleQuick(text){
    inputEl.value = inputEl.value.trim()
      ? (inputEl.value.trim() + "\n" + text)
      : text;

    // Only submit if input is enabled (thread active)
    if (!inputEl.hasAttribute('disabled')) {
      if (sendBtn) sendBtn.disabled = true;
      formEl.requestSubmit ? formEl.requestSubmit() : formEl.submit();
    } else {
      // Optional: focus so the user sees it filled while waiting for thread
      inputEl.focus();
    }
  }
})();
</script>

<script src="darkmode.js"></script>
</body>
</html>

