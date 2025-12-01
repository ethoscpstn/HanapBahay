<?php
session_start();
require 'mysql_connect.php';
require_once 'includes/navigation.php';

// Only admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: LoginModule.php");
    exit();
}

// Check if filtering by specific property
$property_id = isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0;
$where_clause = $property_id > 0 ? "WHERE l.id = ?" : "";
$order_clause = "ORDER BY rr.requested_at DESC";

// Fetch all rental transactions with full details
$sql = "
    SELECT rr.id, rr.tenant_id, rr.listing_id, rr.payment_method, rr.payment_option,
           rr.amount_due, rr.amount_to_pay, rr.status, rr.requested_at, rr.receipt_path,
           rr.rejection_reason, rr.rejection_message, rr.rejected_at,
           l.title AS property_title, l.address AS property_address,
           l.price AS property_price, l.owner_id,
           t.first_name AS tenant_first_name, t.last_name AS tenant_last_name,
           t.email AS tenant_email,
           o.first_name AS owner_first_name, o.last_name AS owner_last_name,
           o.email AS owner_email
    FROM rental_requests rr
    JOIN tblistings l ON l.id = rr.listing_id
    JOIN tbadmin t ON t.id = rr.tenant_id
    JOIN tbadmin o ON o.id = l.owner_id
    $where_clause
    $order_clause
";

$stmt = $conn->prepare($sql);
if ($property_id > 0) {
    $stmt->bind_param("i", $property_id);
}
$stmt->execute();
$result = $stmt->get_result();
$transactions = [];
while ($row = $result->fetch_assoc()) {
    $transactions[] = $row;
}
$stmt->close();

// Backfill missing amounts from property prices
foreach ($transactions as &$txn) {
    $paymentOption = $txn['payment_option'] ?? 'full';
    if (empty($txn['amount_due']) || $txn['amount_due'] == 0) {
        $propertyPrice = (float)$txn['property_price'];
        $txn['amount_due'] = $paymentOption === 'half' ? ($propertyPrice / 2) : $propertyPrice;
    }
    if (empty($txn['amount_to_pay']) || $txn['amount_to_pay'] == 0) {
        $txn['amount_to_pay'] = $txn['amount_due'];
    }
}
unset($txn);

// Calculate statistics
$total_transactions = count($transactions);
$total_revenue = array_sum(array_column($transactions, 'amount_due'));
$pending_count = count(array_filter($transactions, fn($t) => $t['status'] === 'pending'));
$approved_count = count(array_filter($transactions, fn($t) => $t['status'] === 'approved'));
$rejected_count = count(array_filter($transactions, fn($t) => $t['status'] === 'rejected'));
$cancelled_count = count(array_filter($transactions, fn($t) => $t['status'] === 'cancelled'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Transactions - Admin Dashboard<?= $property_id > 0 ? ' (Property #' . $property_id . ')' : '' ?></title>
  <link rel="icon" type="image/png" sizes="16x16" href="Assets/HanapBahayTablogo.png?v=2">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="darkmode.css">
    <style>
        body { background: #f7f7fb; }
        .topbar { background: #8B4513; color: #fff; }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-card h3 {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }
        .stat-card p {
            color: #6c757d;
            margin: 0;
        }
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d1e7dd; color: #0f5132; }
        .status-rejected { background: #f8d7da; color: #842029; }
        .status-cancelled { background: #e2e3e5; color: #383d41; }
        .table-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <?= getNavigationForRole('admin_transactions.php') ?>

    <main class="container-fluid py-4">
        <?php if ($property_id > 0): ?>
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="admin_listings.php">Property Listings</a></li>
                    <li class="breadcrumb-item"><a href="admin_view_listing.php?id=<?= $property_id ?>">Property #<?= $property_id ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Transactions</li>
                </ol>
            </nav>
            
            <!-- Property-specific heading -->
            <div class="alert alert-info mb-4">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h5 class="mb-1"><i class="bi bi-building"></i> Transactions for Property #<?= $property_id ?></h5>
                        <?php if (!empty($transactions)): ?>
                            <p class="mb-0">Showing <?= count($transactions) ?> transaction<?= count($transactions) !== 1 ? 's' : '' ?> for this property.</p>
                        <?php else: ?>
                            <p class="mb-0">No transactions found for this property.</p>
                        <?php endif; ?>
                    </div>
                    <a href="admin_transactions.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-arrow-left"></i> Back to All Transactions
                    </a>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <i class="bi bi-receipt text-primary" style="font-size: 2rem;"></i>
                    <h3><?= $total_transactions ?></h3>
                    <p>Total Transactions</p>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-card">
                    <i class="bi bi-hourglass text-warning" style="font-size: 2rem;"></i>
                    <h3><?= $pending_count ?></h3>
                    <p>Pending</p>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="stat-card">
                    <i class="bi bi-check-circle text-success" style="font-size: 2rem;"></i>
                    <h3><?= $approved_count ?></h3>
                    <p>Approved</p>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="stat-card">
                    <i class="bi bi-x-circle text-danger" style="font-size: 2rem;"></i>
                    <h3><?= $rejected_count ?></h3>
                    <p>Rejected</p>
                </div>
            </div>
            <div class="col-md-2 mb-3">
                <div class="stat-card">
                    <i class="bi bi-slash-circle text-secondary" style="font-size: 2rem;"></i>
                    <h3><?= $cancelled_count ?></h3>
                    <p>Cancelled</p>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <input type="text" id="searchInput" class="form-control" placeholder="Search by name, email, or property...">
                </div>
                <div class="col-md-2">
                    <select id="statusFilter" class="form-select">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select id="paymentFilter" class="form-select">
                        <option value="">All Payment Types</option>
                        <option value="half">Half Payment (50%)</option>
                        <option value="full">Full Payment (100%)</option>
                    </select>
                </div>
                <div class="col-md-5 text-end">
                    <button class="btn btn-outline-primary" onclick="exportToCSV()">
                        <i class="bi bi-download"></i> Export to CSV
                    </button>
                </div>
            </div>
        </div>

        <!-- Transactions Table -->
        <div class="table-container">
            <h5 class="mb-3"><i class="bi bi-table"></i> <?= $property_id > 0 ? 'Property Transactions' : 'All Transactions' ?></h5>
            <div class="table-responsive">
                <table class="table table-hover" id="transactionsTable">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Property</th>
                            <th>Tenant</th>
                            <th>Owner</th>
                            <th>Payment Type</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $txn): ?>
                            <?php
                            $statusClass = 'status-pending';
                            if ($txn['status'] === 'approved') $statusClass = 'status-approved';
                            if ($txn['status'] === 'rejected') $statusClass = 'status-rejected';
                            if ($txn['status'] === 'cancelled') $statusClass = 'status-cancelled';

                            $paymentOption = $txn['payment_option'] ?? 'full';
                            $paymentLabel = $paymentOption === 'half' ? 'Half (50%)' : 'Full (100%)';
                            $tenantName = trim($txn['tenant_first_name'] . ' ' . $txn['tenant_last_name']);
                            $ownerName = trim($txn['owner_first_name'] . ' ' . $txn['owner_last_name']);
                            ?>
                            <tr data-status="<?= $txn['status'] ?>" data-payment="<?= $paymentOption ?>">
                                <td><strong>#<?= $txn['id'] ?></strong></td>
                                <td><?= date('M d, Y', strtotime($txn['requested_at'])) ?><br>
                                    <small class="text-muted"><?= date('g:i A', strtotime($txn['requested_at'])) ?></small>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($txn['property_title']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($txn['property_address']) ?></small>
                                </td>
                                <td>
                                    <?= htmlspecialchars($tenantName) ?><br>
                                    <small class="text-muted"><?= htmlspecialchars($txn['tenant_email']) ?></small>
                                </td>
                                <td>
                                    <?= htmlspecialchars($ownerName) ?><br>
                                    <small class="text-muted"><?= htmlspecialchars($txn['owner_email']) ?></small>
                                </td>
                                <td><?= $paymentLabel ?></td>
                                <td><strong class="text-success">₱<?= number_format($txn['amount_due'], 2) ?></strong></td>
                                <td>
                                    <span class="status-badge <?= $statusClass ?>"><?= strtoupper($txn['status']) ?></span>
                                    <?php if ($txn['status'] === 'rejected' && !empty($txn['rejection_reason'])): ?>
                                        <button type="button" class="btn btn-sm btn-link p-0 ms-1" data-bs-toggle="tooltip"
                                            title="<?= htmlspecialchars($txn['rejection_reason']) ?>">
                                            <i class="bi bi-info-circle"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" onclick="viewDetails(<?= $txn['id'] ?>)">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- View Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Transaction Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalBody">
                    <!-- Details will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        })
    </script>
    <script>
        // Search and filter functionality
        const searchInput = document.getElementById('searchInput');
        const statusFilter = document.getElementById('statusFilter');
        const paymentFilter = document.getElementById('paymentFilter');
        const tableRows = document.querySelectorAll('#transactionsTable tbody tr');

        function filterTable() {
            const searchTerm = searchInput.value.toLowerCase();
            const statusValue = statusFilter.value;
            const paymentValue = paymentFilter.value;

            tableRows.forEach(row => {
                const status = row.dataset.status;
                
                // Get payment option from the row data or text content
                let payment = row.dataset.payment;
                if (!payment) {
                    // Extract payment type from the row text content
                    const paymentText = row.textContent.toLowerCase();
                    if (paymentText.includes('half')) {
                        payment = 'half';
                    } else if (paymentText.includes('full')) {
                        payment = 'full';
                    }
                }

                // Enhanced search functionality
                let matchesSearch = true;
                if (searchTerm) {
                    const text = row.textContent.toLowerCase();
                    
                    // Check if search term matches any of these specific fields:
                    // - Tenant name
                    // - Owner name  
                    // - Tenant email
                    // - Owner email
                    // - Property title
                    // - Property address
                    const tenantName = row.cells[3]?.textContent.toLowerCase() || '';
                    const ownerName = row.cells[4]?.textContent.toLowerCase() || '';
                    const propertyTitle = row.cells[2]?.textContent.toLowerCase() || '';
                    
                    matchesSearch = text.includes(searchTerm) || 
                                  tenantName.includes(searchTerm) || 
                                  ownerName.includes(searchTerm) ||
                                  propertyTitle.includes(searchTerm);
                }
                
                const matchesStatus = !statusValue || status === statusValue;
                const matchesPayment = !paymentValue || payment === paymentValue;

                if (matchesSearch && matchesStatus && matchesPayment) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        searchInput.addEventListener('input', filterTable);
        statusFilter.addEventListener('change', filterTable);
        paymentFilter.addEventListener('change', filterTable);

        // View details
        function viewDetails(id) {
            const transactions = <?= json_encode($transactions) ?>;
            const txn = transactions.find(t => t.id == id);

            if (txn) {
                const tenantName = `${txn.tenant_first_name} ${txn.tenant_last_name}`.trim();
                const ownerName = `${txn.owner_first_name} ${txn.owner_last_name}`.trim();
                const paymentOption = txn.payment_option || 'full';
                const paymentLabel = paymentOption === 'half' ? 'Half Payment (50%)' : 'Full Payment (100%)';

                let rejectionInfo = '';
                if (txn.status === 'rejected' && txn.rejection_reason) {
                    rejectionInfo = `
                        <hr>
                        <div class="row">
                            <div class="col-12">
                                <h6 class="text-danger">Rejection Information</h6>
                                <p><strong>Reason:</strong> ${txn.rejection_reason || 'Not specified'}</p>
                                ${txn.rejected_at ? `<p><strong>Rejected At:</strong> ${new Date(txn.rejected_at).toLocaleString()}</p>` : ''}
                            </div>
                        </div>
                    `;
                }

                const html = `
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Transaction Information</h6>
                            <p><strong>Transaction ID:</strong> #${txn.id}</p>
                            <p><strong>Date:</strong> ${new Date(txn.requested_at).toLocaleString()}</p>
                            <p><strong>Status:</strong> <span class="badge bg-${txn.status === 'approved' ? 'success' : txn.status === 'rejected' ? 'danger' : 'warning'}">${txn.status.toUpperCase()}</span></p>
                        </div>
                        <div class="col-md-6">
                            <h6>Payment Details</h6>
                            <p><strong>Payment Type:</strong> ${paymentLabel}</p>
                            <p><strong>Property Price:</strong> ₱${parseFloat(txn.property_price).toLocaleString('en-PH', {minimumFractionDigits: 2})}</p>
                            <p><strong>Amount Due:</strong> <span class="text-success fs-5">₱${parseFloat(txn.amount_due).toLocaleString('en-PH', {minimumFractionDigits: 2})}</span></p>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Tenant Information</h6>
                            <p><strong>Name:</strong> ${tenantName}</p>
                            <p><strong>Email:</strong> ${txn.tenant_email}</p>
                        </div>
                        <div class="col-md-6">
                            <h6>Owner Information</h6>
                            <p><strong>Name:</strong> ${ownerName}</p>
                            <p><strong>Email:</strong> ${txn.owner_email}</p>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-12">
                            <h6>Property Information</h6>
                            <p><strong>Title:</strong> ${txn.property_title}</p>
                            <p><strong>Address:</strong> ${txn.property_address}</p>
                        </div>
                    </div>
                    ${rejectionInfo}
                `;

                document.getElementById('modalBody').innerHTML = html;
                new bootstrap.Modal(document.getElementById('detailsModal')).show();
            }
        }

        // Export to CSV (only filtered transactions)
        function exportToCSV() {
            const transactions = <?= json_encode($transactions) ?>;
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const paymentFilter = document.getElementById('paymentFilter');
            
            const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
            const statusValue = statusFilter ? statusFilter.value : '';
            const paymentValue = paymentFilter ? paymentFilter.value : '';
            
            console.log('Export filters:', { searchTerm, statusValue, paymentValue });
            console.log('Total transactions:', transactions.length);
            
            // Filter transactions based on current filters
            const filteredTransactions = transactions.filter(txn => {
                const status = txn.status;
                const payment = txn.payment_option || 'full';

                // Enhanced search functionality matching the table filter
                let matchesSearch = true;
                if (searchTerm) {
                    const tenantName = `${txn.tenant_first_name} ${txn.tenant_last_name}`.toLowerCase();
                    const ownerName = `${txn.owner_first_name} ${txn.owner_last_name}`.toLowerCase();
                    const propertyTitle = txn.property_title.toLowerCase();
                    const propertyAddress = txn.property_address.toLowerCase();
                    const tenantEmail = txn.tenant_email.toLowerCase();
                    const ownerEmail = txn.owner_email.toLowerCase();
                    
                    matchesSearch = tenantName.includes(searchTerm) || 
                                  ownerName.includes(searchTerm) ||
                                  propertyTitle.includes(searchTerm) ||
                                  propertyAddress.includes(searchTerm) ||
                                  tenantEmail.includes(searchTerm) ||
                                  ownerEmail.includes(searchTerm);
                }
                
                const matchesStatus = !statusValue || status === statusValue;
                const matchesPayment = !paymentValue || payment === paymentValue;

                return matchesSearch && matchesStatus && matchesPayment;
            });

            console.log('Filtered transactions:', filteredTransactions.length);

            let csv = 'ID,Date,Property,Tenant,Owner,Payment Type,Amount,Status,Rejection Reason\n';

            filteredTransactions.forEach(txn => {
                const tenantName = `${txn.tenant_first_name} ${txn.tenant_last_name}`.trim();
                const ownerName = `${txn.owner_first_name} ${txn.owner_last_name}`.trim();
                const paymentOption = txn.payment_option || 'full';
                const paymentLabel = paymentOption === 'half' ? 'Half Payment (50%)' : 'Full Payment (100%)';
                const rejectionReason = txn.rejection_reason ? `"${txn.rejection_reason.replace(/"/g, '""')}"` : '';

                csv += `${txn.id},"${txn.requested_at}","${txn.property_title}","${tenantName}","${ownerName}",${paymentLabel},${txn.amount_due},${txn.status},${rejectionReason}\n`;
            });

            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `hanapbahay_transactions_${new Date().toISOString().split('T')[0]}.csv`;
            a.click();
        }
    </script>
    <script src="darkmode.js"></script>
</body>
</html>
<?php $conn->close(); ?>
