<?php
/**
 * Migration script to update rental_requests table
 * This adds missing columns for payment options, rejection handling, and receipts
 */

require 'mysql_connect.php';

echo "<h2>Rental Requests Table Migration</h2>\n";
echo "<pre>\n";

// Check if columns exist before adding them
$columns_to_add = [
    [
        'name' => 'payment_option',
        'sql' => "ALTER TABLE `rental_requests` ADD COLUMN `payment_option` VARCHAR(20) DEFAULT 'full' AFTER `payment_method`",
        'check' => "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rental_requests' AND COLUMN_NAME = 'payment_option'"
    ],
    [
        'name' => 'amount_due',
        'sql' => "ALTER TABLE `rental_requests` ADD COLUMN `amount_due` DECIMAL(10,2) DEFAULT 0 AFTER `payment_option`",
        'check' => "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rental_requests' AND COLUMN_NAME = 'amount_due'"
    ],
    [
        'name' => 'amount_to_pay',
        'sql' => "ALTER TABLE `rental_requests` ADD COLUMN `amount_to_pay` DECIMAL(10,2) DEFAULT 0 AFTER `amount_due`",
        'check' => "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rental_requests' AND COLUMN_NAME = 'amount_to_pay'"
    ],
    [
        'name' => 'receipt_path',
        'sql' => "ALTER TABLE `rental_requests` ADD COLUMN `receipt_path` VARCHAR(500) NULL DEFAULT NULL AFTER `amount_to_pay`",
        'check' => "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rental_requests' AND COLUMN_NAME = 'receipt_path'"
    ],
    [
        'name' => 'receipt_file',
        'sql' => "ALTER TABLE `rental_requests` ADD COLUMN `receipt_file` VARCHAR(255) NULL DEFAULT NULL AFTER `receipt_path`",
        'check' => "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rental_requests' AND COLUMN_NAME = 'receipt_file'"
    ],
    [
        'name' => 'rejection_reason',
        'sql' => "ALTER TABLE `rental_requests` ADD COLUMN `rejection_reason` TEXT NULL DEFAULT NULL AFTER `status`",
        'check' => "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rental_requests' AND COLUMN_NAME = 'rejection_reason'"
    ],
    [
        'name' => 'rejection_message',
        'sql' => "ALTER TABLE `rental_requests` ADD COLUMN `rejection_message` TEXT NULL DEFAULT NULL AFTER `rejection_reason`",
        'check' => "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rental_requests' AND COLUMN_NAME = 'rejection_message'"
    ],
    [
        'name' => 'rejected_by',
        'sql' => "ALTER TABLE `rental_requests` ADD COLUMN `rejected_by` INT(11) NULL DEFAULT NULL AFTER `rejection_message`",
        'check' => "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rental_requests' AND COLUMN_NAME = 'rejected_by'"
    ],
    [
        'name' => 'rejected_at',
        'sql' => "ALTER TABLE `rental_requests` ADD COLUMN `rejected_at` DATETIME NULL DEFAULT NULL AFTER `rejected_by`",
        'check' => "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rental_requests' AND COLUMN_NAME = 'rejected_at'"
    ],
    [
        'name' => 'is_dismissed',
        'sql' => "ALTER TABLE `rental_requests` ADD COLUMN `is_dismissed` TINYINT(1) DEFAULT 0 AFTER `rejected_at`",
        'check' => "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rental_requests' AND COLUMN_NAME = 'is_dismissed'"
    ]
];

$success_count = 0;
$skip_count = 0;
$error_count = 0;

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

echo "\n--- Migration Summary ---\n";
echo "Columns added: $success_count\n";
echo "Columns skipped (already exist): $skip_count\n";
echo "Errors: $error_count\n";

// Backfill amount_due and amount_to_pay for existing records
echo "\n--- Backfilling Data ---\n";
$backfill_sql = "
    UPDATE rental_requests rr
    JOIN tblistings l ON rr.listing_id = l.id
    SET
        rr.amount_due = CASE
            WHEN rr.payment_option = 'half' THEN l.price / 2
            ELSE l.price
        END,
        rr.amount_to_pay = CASE
            WHEN rr.payment_option = 'half' THEN l.price / 2
            ELSE l.price
        END
    WHERE rr.amount_due = 0 OR rr.amount_due IS NULL
";

if ($conn->query($backfill_sql)) {
    echo "✓ Successfully backfilled amount_due and amount_to_pay for existing records\n";
} else {
    echo "✗ Error backfilling data: " . $conn->error . "\n";
}

echo "\n</pre>\n";
echo "<p><strong>Migration complete!</strong> You can now close this page.</p>\n";
echo "<p><a href='admin_transactions.php' class='btn btn-primary'>Go to Admin Transactions</a></p>\n";

$conn->close();
?>
