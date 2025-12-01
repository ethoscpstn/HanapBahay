<?php
// Start output buffering to prevent any accidental output before JSON
ob_start();

session_start();
require 'mysql_connect.php';
require 'send_rental_notification.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'tenant') {
    header("Location: LoginModule.php");
    exit();
}

$tenant_id = (int)$_SESSION['user_id'];
$error = null;
$ok = false;

// --- Helpers ---
function clean_text($s) { return trim(filter_var($s, FILTER_SANITIZE_STRING)); }
function post($key, $default = null) { return $_POST[$key] ?? $default; }

// If coming from property_details.php, these should be posted.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $listing_id      = (int)post('listing_id', 0);
    $payment_method  = strtolower(clean_text((string)post('payment_method','')));
    $payment_option  = strtolower(clean_text((string)post('payment_option',''))); // 'reservation'
    if (!in_array($payment_option, ['reservation', 'full_with_advance'], true)) $payment_option = 'reservation';

    if ($listing_id <= 0) { $error = "Invalid listing."; }

    // 1) Verify listing & get SERVER-SIDE price + owner info
    if (!$error) {
        $stmt = $conn->prepare("
            SELECT l.id, l.price, l.is_archived, l.is_available, l.title, l.owner_id,
                   o.email AS owner_email, o.first_name AS owner_first_name, o.last_name AS owner_last_name
            FROM tblistings l
            JOIN tbadmin o ON o.id = l.owner_id
            WHERE l.id = ? LIMIT 1
        ");
        $stmt->bind_param("i", $listing_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $listing = $res->fetch_assoc();
        $stmt->close();

        if (!$listing || (int)$listing['is_archived'] === 1) {
            $error = "Listing not found or archived.";
        } elseif ((int)$listing['is_available'] !== 1) {
            $error = "Listing is no longer available.";
        } else {
            $price = (float)$listing['price'];
            $propertyTitle = $listing['title'];
            $ownerEmail = $listing['owner_email'];
            $ownerName = trim($listing['owner_first_name'] . ' ' . $listing['owner_last_name']);
        }
    }

    // 2) Compute amount server-side based on payment option
    if (!$error) {
        if ($payment_option === 'reservation') {
            $amount_to_pay = $price * 0.5; // 50% reservation fee
        } elseif ($payment_option === 'full_with_advance') {
            $amount_to_pay = $price * 2; // 2x rent (1 month rent + 1 month advance deposit)
        } else {
            $amount_to_pay = $price * 0.5; // Default to reservation fee
        }
        // Round to 2 decimals for currency
        $amount_to_pay = round($amount_to_pay, 2);
    }

    // 3) Receipt upload (required for gcash/paymaya)
    $need_receipt = in_array($payment_method, ['gcash','paymaya'], true);
    $receipt_path = null;

    if (!$error) {
        if (!empty($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES['receipt_image'];

            if ($f['error'] === UPLOAD_ERR_OK) {
                // Strict MIME check
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    'image/gif'  => 'gif'
                ];

                // Prefer finfo over client-provided type
                $mime = function_exists('mime_content_type') ? mime_content_type($f['tmp_name']) : $f['type'];
                if (!isset($allowed[$mime])) {
                    $error = "Invalid receipt file type. Only JPG, PNG, WEBP, or GIF are allowed.";
                } elseif ($f['size'] > 5 * 1024 * 1024) {
                    $error = "Receipt image too large (max 5 MB).";
                } else {
                    $dir = __DIR__ . "/uploads/receipts/" . date('Ymd');
                    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
                    $ext = $allowed[$mime];
                    $safeName = "rcpt_" . $tenant_id . "_" . $listing_id . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
                    $abs = $dir . "/" . $safeName;

                    if (!@move_uploaded_file($f['tmp_name'], $abs)) {
                        $error = "Failed to save receipt image.";
                    } else {
                        $receipt_path = "uploads/receipts/" . date('Ymd') . "/" . $safeName;
                    }
                }
            } else {
                $error = "Error uploading receipt (code {$f['error']}).";
            }
        } elseif ($need_receipt) {
            $error = "Please upload a payment receipt for {$payment_method}.";
        }
    }

    // 4) Insert rental request
    if (!$error) {
        // Try extended insert first (with payment_option, amount_to_pay, receipt_path)
        $sql_ext = "INSERT INTO rental_requests
  (tenant_id, listing_id, payment_method, payment_option, amount_due, amount_to_pay, receipt_path, requested_at, status)
  VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')";
$stmt = $conn->prepare($sql_ext);
if ($stmt) {
    $stmt->bind_param(
        "iissdds",
        $tenant_id,
        $listing_id,
        $payment_method,
        $payment_option,
        $amount_to_pay,
        $amount_to_pay,
        $receipt_path
    );
            if ($stmt->execute()) {
                $ok = true;
            } else {
                // If failed (e.g., columns don't exist), fall back to your original 4-column insert
                // Close and try fallback
                $stmt->close();
                $sql_min = "INSERT INTO rental_requests
                    (tenant_id, listing_id, payment_method, receipt_file, requested_at, status)
                    VALUES (?, ?, ?, ?, NOW(), 'pending')";
                $stmt2 = $conn->prepare($sql_min);
                if ($stmt2) {
                    // Keep storing path in receipt_file for backward compatibility
                    $receipt_file_legacy = $receipt_path ? basename($receipt_path) : null;
                    $stmt2->bind_param("iiss", $tenant_id, $listing_id, $payment_method, $receipt_file_legacy);
                    if ($stmt2->execute()) {
                        $ok = true;
                    } else {
                        $error = "Error submitting request: " . $stmt2->error;
                    }
                    $stmt2->close();
                } else {
                    $error = "Error preparing fallback insert.";
                }
            }
            if (!$ok && isset($stmt) && $stmt) { $stmt->close(); }
        } else {
            // Could not prepare extended insert (likely columns missing) → try minimal right away
            $sql_min = "INSERT INTO rental_requests
                (tenant_id, listing_id, payment_method, receipt_file, requested_at, status)
                VALUES (?, ?, ?, ?, NOW(), 'pending')";
            $stmt2 = $conn->prepare($sql_min);
            if ($stmt2) {
                $receipt_file_legacy = $receipt_path ? basename($receipt_path) : null;
                $stmt2->bind_param("iiss", $tenant_id, $listing_id, $payment_method, $receipt_file_legacy);
                if ($stmt2->execute()) {
                    $ok = true;
                } else {
                    $error = "Error submitting request: " . $stmt2->error;
                }
                $stmt2->close();
            } else {
                $error = "Error preparing insert.";
            }
        }
    }

    if ($ok) {
        // Get request ID and tenant name for email
        $request_id = $conn->insert_id;

        // Fetch tenant name
        $stmt = $conn->prepare("SELECT first_name, last_name FROM tbadmin WHERE id = ?");
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $tenantResult = $stmt->get_result()->fetch_assoc();
        $tenantName = trim($tenantResult['first_name'] . ' ' . $tenantResult['last_name']);
        $stmt->close();

        // Send email notification to owner
        sendRentalRequestNotification(
            $ownerEmail,
            $ownerName,
            $tenantName,
            $propertyTitle,
            $amount_to_pay,
            $payment_option,
            $request_id
        );

        // Check if AJAX request
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            ob_end_clean(); // Clear any buffered output
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Reservation submitted successfully']);
            exit();
        }
        // Regular form submission - redirect
        header("Location: rental_request.php");
        exit();
    } else if ($error) {
        // Return error for AJAX
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            ob_end_clean(); // Clear any buffered output
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $error]);
            exit();
        }
    }
}

// =============== UI (optional fallback page) ===============
// If you normally POST from property_details.php, users won’t land here directly.
// Keeping a simple form for manual testing.
$listings = [];
$q = $conn->query("SELECT id, title FROM tblistings WHERE is_archived = 0 ORDER BY id DESC");
while ($row = $q->fetch_assoc()) { $listings[] = $row; }
$q->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Submit Rental Request</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    #receiptPreview { display:none; max-width:300px; margin-top:10px; border-radius:6px; }
  </style>
</head>
<body class="bg-light">
<div class="container py-5">
  <h3 class="mb-4">Submit Rental Request</h3>

  <?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" class="bg-white p-4 shadow-sm rounded">
    <!-- Select listing (optional if posted from property_details.php) -->
    <div class="mb-3">
      <label class="form-label">Select Property</label>
      <select name="listing_id" class="form-select" required>
        <option value="">-- Choose a property --</option>
        <?php foreach ($listings as $l): ?>
          <option value="<?= (int)$l['id'] ?>"><?= htmlspecialchars($l['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Payment method -->
    <div class="mb-3">
      <label class="form-label">Payment Method</label>
      <select class="form-select" name="payment_method" id="payment_method" required>
        <option value="">Select</option>
        <option value="gcash">GCash</option>
        <option value="paymaya">PayMaya</option>
        <option value="cash">Cash</option>
      </select>
    </div>

    <!-- Payment option -->
    <div class="mb-3">
      <label class="form-label">Payment Option</label>
      <select class="form-select" name="payment_option" id="payment_option" required>
        <option value="reservation">Reservation Only (50% of rent)</option>
        <option value="full_with_advance">Full Payment with Advance Deposit (2x rent)</option>
      </select>
    </div>

    <!-- Receipt -->
    <div class="mb-3">
      <label for="receipt_image" class="form-label">Upload Receipt <small class="text-muted">(Image only; required for GCash/PayMaya)</small></label>
      <input type="file" class="form-control" name="receipt_image" id="receipt_image" accept="image/*">
      <img id="receiptPreview" alt="Receipt Preview">
    </div>

    <button type="submit" class="btn btn-primary">Submit Request</button>
    <a href="DashboardT.php" class="btn btn-secondary ms-2">Cancel</a>
  </form>
</div>

<script>
  // Toggle receipt required if GCash/PayMaya
  const methodSel = document.getElementById('payment_method');
  const receiptInput = document.getElementById('receipt_image');

  function toggleReceiptRequired() {
    const need = ['gcash','paymaya'].includes((methodSel.value || '').toLowerCase());
    if (need) receiptInput.setAttribute('required', 'required');
    else receiptInput.removeAttribute('required');
  }
  methodSel.addEventListener('change', toggleReceiptRequired);
  toggleReceiptRequired();

  // Preview
  receiptInput.addEventListener('change', function () {
    const file = this.files && this.files[0];
    const img = document.getElementById('receiptPreview');
    if (file && /^image\//i.test(file.type)) {
      const reader = new FileReader();
      reader.onload = e => {
        img.src = e.target.result;
        img.style.display = 'block';
      };
      reader.readAsDataURL(file);
    } else {
      img.style.display = 'none';
      img.src = '';
    }
  });
</script>
</body>
</html>
<?php $conn->close(); ?>
