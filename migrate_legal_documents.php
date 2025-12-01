<?php
// Migration script to add legal document columns to tblistings
require 'mysql_connect.php';

echo "<!DOCTYPE html><html><head><title>Legal Documents Migration</title></head><body>";
echo "<h2>Adding Legal Document Columns to tblistings...</h2>";

// Define legal document columns
$columns = [
    [
        'name' => 'barangay_permit_path',
        'sql' => "ALTER TABLE `tblistings` ADD COLUMN `barangay_permit_path` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Barangay permit for residential rental'"
    ],
    [
        'name' => 'dti_sec_permit_path',
        'sql' => "ALTER TABLE `tblistings` ADD COLUMN `dti_sec_permit_path` VARCHAR(500) NULL DEFAULT NULL COMMENT 'DTI or SEC permit for commercial rental'"
    ],
    [
        'name' => 'business_permit_path',
        'sql' => "ALTER TABLE `tblistings` ADD COLUMN `business_permit_path` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Mayors/Business permit for commercial rental'"
    ],
    [
        'name' => 'bir_permit_path',
        'sql' => "ALTER TABLE `tblistings` ADD COLUMN `bir_permit_path` VARCHAR(500) NULL DEFAULT NULL COMMENT 'BIR permit for commercial rental'"
    ],
    [
        'name' => 'rental_type',
        'sql' => "ALTER TABLE `tblistings` ADD COLUMN `rental_type` ENUM('residential', 'commercial') NOT NULL DEFAULT 'residential' COMMENT 'Type of rental property'"
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

$conn->close();

if ($success_count == count($columns)) {
    echo "<h3 style='color: green;'>✓ Migration Complete!</h3>";
    echo "<p><strong>All legal document columns have been added successfully.</strong></p>";
    echo "<p><a href='DashboardUO.php' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px;'>Go to Dashboard</a></p>";
    echo "<hr>";
    echo "<p style='color: red;'><strong>IMPORTANT:</strong> For security, delete this file (migrate_legal_documents.php) after migration is complete.</p>";
} else {
    echo "<h3 style='color: orange;'>⚠ Migration Incomplete</h3>";
    echo "<p>Some columns could not be added. Please check the errors above.</p>";
}

echo "</body></html>";
?>
