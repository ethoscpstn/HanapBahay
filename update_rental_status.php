<?php
/**
 * Updates for property rental status handling
 */

require 'mysql_connect.php';

echo "<h2>Setting up automatic rental status updates</h2>\n";
echo "<pre>\n";

// Create trigger to update availability status when a rental request is approved
$trigger_sql = "
CREATE TRIGGER update_listing_status_on_rental
AFTER UPDATE ON rental_requests
FOR EACH ROW
BEGIN
    IF NEW.status = 'approved' AND OLD.status != 'approved' THEN
        UPDATE tblistings 
        SET availability_status = 'unavailable',
            available_units = GREATEST(0, available_units - 1)
        WHERE id = NEW.listing_id;
    END IF;
END;
";

// Check if trigger exists
$check_trigger = $conn->query("SHOW TRIGGERS WHERE `Trigger` = 'update_listing_status_on_rental'");
if ($check_trigger->num_rows === 0) {
    if ($conn->query($trigger_sql)) {
        echo "✓ Created trigger for automatic status updates\n";
    } else {
        echo "✗ Failed to create trigger: " . $conn->error . "\n";
    }
} else {
    echo "• Trigger already exists\n";
}

echo "</pre>\n";
echo "<p>Done.</p>\n";