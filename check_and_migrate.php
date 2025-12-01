<?php
// Auto-migration script - checks if columns exist before adding them
require 'mysql_connect.php';

echo "<!DOCTYPE html><html><head><title>Database Migration</title></head><body>";
echo "<h2>Checking Database Structure...</h2>";

// Check if columns already exist
$check = $conn->query("SHOW COLUMNS FROM `tblistings` LIKE 'property_photos'");
if ($check && $check->num_rows > 0) {
    echo "<p style='color: green;'>✓ Columns already exist. No migration needed.</p>";
    echo "<p><a href='DashboardT.php'>Go to Dashboard</a></p>";
    $conn->close();
    exit;
}

echo "<h3>Adding Required Columns...</h3>";

// Add columns one by one with error handling
$columns = [
    [
        'name' => 'gov_id_path',
        'sql' => "ALTER TABLE `tblistings` ADD COLUMN `gov_id_path` VARCHAR(500) NULL DEFAULT NULL"
    ],
    [
        'name' => 'property_photos',
        'sql' => "ALTER TABLE `tblistings` ADD COLUMN `property_photos` TEXT NULL DEFAULT NULL"
    ],
    [
        'name' => 'verification_status',
        'sql' => "ALTER TABLE `tblistings` ADD COLUMN `verification_status` VARCHAR(20) NULL DEFAULT NULL"
    ],
    [
        'name' => 'rejection_reason',
        'sql' => "ALTER TABLE `tblistings` ADD COLUMN `rejection_reason` TEXT NULL DEFAULT NULL"
    ]
];

$success_count = 0;
foreach ($columns as $col) {
    // Check if column exists first
    $check = $conn->query("SHOW COLUMNS FROM `tblistings` LIKE '{$col['name']}'");
    if ($check && $check->num_rows > 0) {
        echo "<p style='color: blue;'>○ Column '{$col['name']}' already exists, skipping...</p>";
        $success_count++;
        continue;
    }

    // Add the column
    if ($conn->query($col['sql'])) {
        echo "<p style='color: green;'>✓ Added column: {$col['name']}</p>";
        $success_count++;
    } else {
        echo "<p style='color: red;'>✗ Failed to add column '{$col['name']}': " . htmlspecialchars($conn->error) . "</p>";
    }
}

// Add indexes if they don't exist
echo "<h3>Adding Indexes...</h3>";

// Check and add first index
$idx_check = $conn->query("SHOW INDEX FROM `tblistings` WHERE Key_name = 'idx_verification_status'");
if ($idx_check && $idx_check->num_rows == 0) {
    if ($conn->query("CREATE INDEX `idx_verification_status` ON `tblistings` (`verification_status`)")) {
        echo "<p style='color: green;'>✓ Added index: idx_verification_status</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Could not add index idx_verification_status: " . htmlspecialchars($conn->error) . "</p>";
    }
} else {
    echo "<p style='color: blue;'>○ Index idx_verification_status already exists</p>";
}

// Check and add second index
$idx_check2 = $conn->query("SHOW INDEX FROM `tblistings` WHERE Key_name = 'idx_is_verified_archived'");
if ($idx_check2 && $idx_check2->num_rows == 0) {
    if ($conn->query("CREATE INDEX `idx_is_verified_archived` ON `tblistings` (`is_verified`, `is_archived`)")) {
        echo "<p style='color: green;'>✓ Added index: idx_is_verified_archived</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Could not add index idx_is_verified_archived: " . htmlspecialchars($conn->error) . "</p>";
    }
} else {
    echo "<p style='color: blue;'>○ Index idx_is_verified_archived already exists</p>";
}

$conn->close();

if ($success_count == count($columns)) {
    echo "<h3 style='color: green;'>✓ Migration Complete!</h3>";
    echo "<p><strong>All required columns have been added successfully.</strong></p>";
    echo "<p>You can now use the application normally.</p>";
    echo "<p><a href='DashboardT.php' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px;'>Go to Tenant Dashboard</a></p>";
    echo "<p><a href='LoginModule.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px;'>Go to Login</a></p>";
    echo "<hr>";
    echo "<p style='color: red;'><strong>IMPORTANT:</strong> For security, delete this file (check_and_migrate.php) after migration is complete.</p>";
} else {
    echo "<h3 style='color: orange;'>⚠ Migration Incomplete</h3>";
    echo "<p>Some columns could not be added. Please check the errors above.</p>";
    echo "<p>You may need to run the SQL manually in phpMyAdmin.</p>";
}

echo "</body></html>";
?>
