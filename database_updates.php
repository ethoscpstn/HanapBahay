<?php
/**
 * Database migration script for HanapBahay system updates
 * Adds new columns for deleted archive, kitchen type, and availability status
 */

require 'mysql_connect.php';

echo "<h2>HanapBahay Database Updates</h2>\n";
echo "<pre>\n";

// Columns to add to tblistings table
$columns_to_add = [
    [
        'name' => 'is_deleted',
        'sql' => "ALTER TABLE `tblistings` ADD COLUMN `is_deleted` TINYINT(1) DEFAULT 0 AFTER `is_archived`",
        'check' => "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblistings' AND COLUMN_NAME = 'is_deleted'"
    ],
    [
        'name' => 'kitchen_type',
        'sql' => "ALTER TABLE `tblistings` ADD COLUMN `kitchen_type` ENUM('none', 'gas', 'electric', 'both') DEFAULT 'none' AFTER `kitchen`",
        'check' => "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblistings' AND COLUMN_NAME = 'kitchen_type'"
    ],
    [
        'name' => 'availability_status',
        'sql' => "ALTER TABLE `tblistings` ADD COLUMN `availability_status` ENUM('available', 'reserved', 'unavailable') DEFAULT 'available' AFTER `is_deleted`",
        'check' => "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblistings' AND COLUMN_NAME = 'availability_status'",
        'update' => "UPDATE `tblistings` SET `availability_status` = 'unavailable' WHERE `availability_status` = 'occupied'"
    ],
    [
        'name' => 'total_units',
        'sql' => "ALTER TABLE `tblistings` ADD COLUMN `total_units` INT DEFAULT 1 AFTER `unit_sqm`",
        'check' => "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblistings' AND COLUMN_NAME = 'total_units'"
    ],
    [
        'name' => 'available_units',
        'sql' => "ALTER TABLE `tblistings` ADD COLUMN `available_units` INT DEFAULT 1 AFTER `total_units`",
        'check' => "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblistings' AND COLUMN_NAME = 'available_units'"
    ]
];

// Columns to remove from tblistings table
$columns_to_drop = [
    [
        'name' => 'rental_type',
        'sql' => "ALTER TABLE `tblistings` DROP COLUMN `rental_type`",
        'check' => "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblistings' AND COLUMN_NAME = 'rental_type'"
    ]
];

$success_count = 0;
$skip_count = 0;
$error_count = 0;

// Add new columns
foreach ($columns_to_add as $column) {
    // Check if column already exists
    $result = $conn->query($column['check']);
    if ($result) {
        $row = $result->fetch_assoc();
        if ($row['count'] > 0) {
            echo "✓ Column '{$column['name']}' already exists. Skipping.\n";
            $skip_count++;
            continue;
        }
    }

    // Add the column
    if ($conn->query($column['sql'])) {
        echo "✓ Successfully added column '{$column['name']}'\n";
        $success_count++;
    } else {
        echo "✗ Error adding column '{$column['name']}': " . $conn->error . "\n";
        $error_count++;
    }
}

// Remove deprecated columns
foreach ($columns_to_drop as $column) {
    // Check if column exists before trying to drop
    $result = $conn->query($column['check']);
    if ($result) {
        $row = $result->fetch_assoc();
        if ($row['count'] == 0) {
            echo "✓ Column '{$column['name']}' already removed. Skipping.\n";
            $skip_count++;
            continue;
        }
    }

    // Drop the column
    if ($conn->query($column['sql'])) {
        echo "✓ Successfully removed column '{$column['name']}'\n";
        $success_count++;
    } else {
        echo "✗ Error removing column '{$column['name']}': " . $conn->error . "\n";
        $error_count++;
    }
}

// Update summary
echo "\nMigration Summary:\n";
echo "----------------\n";
echo "Successful operations: $success_count\n";
echo "Skipped operations: $skip_count\n";
echo "Failed operations: $error_count\n";

if ($error_count == 0) {
    echo "\n✅ Migration completed successfully!\n";
} else {
    echo "\n⚠️ Migration completed with errors. Please check the error messages above.\n";
}

echo "</pre>";
?>