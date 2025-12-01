<?php
session_start();
require 'mysql_connect.php';
require_once 'includes/navigation.php';

// Only owners can access
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'unit_owner')) {
    header("Location: LoginModule.php");
    exit();
}

$owner_id = $_SESSION['user_id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    $instant_notifications = isset($_POST['instant_notifications']) ? 1 : 0;
    $daily_summary = isset($_POST['daily_summary']) ? 1 : 0;
    
    $stmt = $conn->prepare("UPDATE tbadmin SET 
        email_notifications = ?, 
        instant_notifications = ?, 
        daily_summary = ? 
        WHERE id = ?");
    $stmt->bind_param("iiii", $email_notifications, $instant_notifications, $daily_summary, $owner_id);
    
    if ($stmt->execute()) {
        $success_message = "Notification preferences updated successfully!";
    } else {
        $error_message = "Failed to update preferences: " . $conn->error;
    }
    $stmt->close();
}

// Get current preferences
$stmt = $conn->prepare("SELECT email_notifications, instant_notifications, daily_summary FROM tbadmin WHERE id = ?");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$result = $stmt->get_result();
$preferences = $result->fetch_assoc();
$stmt->close();

// Set defaults if not set
$email_notifications = $preferences['email_notifications'] ?? 1;
$instant_notifications = $preferences['instant_notifications'] ?? 1;
$daily_summary = $preferences['daily_summary'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Notifications - HanapBahay</title>
    <link rel="icon" type="image/png" sizes="16x16" href="Assets/HanapBahayTablogo.png?v=2">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="darkmode.css">
    <script>
        (function() {
            try {
                const savedTheme = localStorage.getItem('hb-theme');
                if (savedTheme) {
                    document.documentElement.setAttribute('data-theme', savedTheme);
                }
            } catch (err) {
                // Ignore access errors
            }
        })();
    </script>
</head>
<body>
    <?= getNavigationForRole('email_notifications') ?>
    
    <main class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="bi bi-bell"></i> Email Notification Settings</h4>
                    </div>
                    <div class="card-body">
                        <?php if (isset($success_message)): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($error_message)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="mb-4">
                                <h5><i class="bi bi-envelope"></i> Property Inquiry Notifications</h5>
                                <p class="text-muted">Get notified when tenants show interest in your properties</p>
                                
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="email_notifications" 
                                           name="email_notifications" <?= $email_notifications ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="email_notifications">
                                        <strong>Enable Email Notifications</strong>
                                        <small class="d-block text-muted">Receive emails when tenants send messages about your properties</small>
                                    </label>
                                </div>
                                
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="instant_notifications" 
                                           name="instant_notifications" <?= $instant_notifications ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="instant_notifications">
                                        <strong>Instant Notifications</strong>
                                        <small class="d-block text-muted">Get notified immediately when new messages arrive</small>
                                    </label>
                                </div>
                                
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="daily_summary" 
                                           name="daily_summary" <?= $daily_summary ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="daily_summary">
                                        <strong>Daily Summary</strong>
                                        <small class="d-block text-muted">Receive a daily summary of all inquiries and messages</small>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="alert alert-info">
                                <h6><i class="bi bi-info-circle"></i> How It Works</h6>
                                <ul class="mb-0">
                                    <li><strong>New Inquiry:</strong> Get notified when a tenant starts a conversation about your property</li>
                                    <li><strong>New Message:</strong> Receive emails when tenants send messages in existing conversations</li>
                                    <li><strong>Property Details:</strong> Each notification includes property title, address, and price</li>
                                    <li><strong>Direct Reply:</strong> Click the link in emails to go directly to the conversation</li>
                                </ul>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <a href="DashboardUO.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Save Preferences
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-question-circle"></i> Notification Examples</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="bi bi-chat-dots"></i> New Conversation</h6>
                                <div class="border rounded p-3 bg-light">
                                    <strong>Subject:</strong> New Property Inquiry Started - Studio Apartment<br>
                                    <strong>Content:</strong> John Doe has started a conversation about your property: "Studio Apartment" at ₱15,000/month
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="bi bi-envelope-open"></i> New Message</h6>
                                <div class="border rounded p-3 bg-light">
                                    <strong>Subject:</strong> New Property Inquiry - Studio Apartment<br>
                                    <strong>Content:</strong> John Doe: "Is this property still available? I'm interested in viewing it."
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="darkmode.js"></script>
</body>
</html>
