<?php
// Migration script to add ML prediction columns to tblistings table
require 'mysql_connect.php';

echo "<h2>Adding ML Prediction Columns to tblistings</h2>";

// Check if columns already exist
$check_sql = "SHOW COLUMNS FROM tblistings LIKE 'bedroom'";
$result = $conn->query($check_sql);

if ($result->num_rows > 0) {
    echo "<p style='color: orange;'>✓ Columns already exist. Skipping...</p>";
} else {
    // Add new columns
    $sql = "ALTER TABLE tblistings
            ADD COLUMN bedroom INT DEFAULT 1 AFTER capacity,
            ADD COLUMN unit_sqm DECIMAL(10,2) DEFAULT 20.00 AFTER bedroom,
            ADD COLUMN kitchen VARCHAR(10) DEFAULT 'Yes' AFTER unit_sqm,
            ADD COLUMN kitchen_type VARCHAR(20) DEFAULT 'Private' AFTER kitchen,
            ADD COLUMN gender_specific VARCHAR(20) DEFAULT 'Mixed' AFTER kitchen_type,
            ADD COLUMN pets VARCHAR(20) DEFAULT 'Allowed' AFTER gender_specific";

    if ($conn->query($sql) === TRUE) {
        echo "<p style='color: green;'>✓ Successfully added columns:</p>";
        echo "<ul>";
        echo "<li><strong>bedroom</strong> - INT, default 1</li>";
        echo "<li><strong>unit_sqm</strong> - DECIMAL(10,2), default 20.00</li>";
        echo "<li><strong>kitchen</strong> - VARCHAR(10), default 'Yes'</li>";
        echo "<li><strong>kitchen_type</strong> - VARCHAR(20), default 'Private'</li>";
        echo "<li><strong>gender_specific</strong> - VARCHAR(20), default 'Mixed'</li>";
        echo "<li><strong>pets</strong> - VARCHAR(20), default 'Allowed'</li>";
        echo "</ul>";
        echo "<p style='color: green; font-weight: bold;'>✓ Migration completed successfully!</p>";
    } else {
        echo "<p style='color: red;'>✗ Error: " . $conn->error . "</p>";
    }
}

// Show updated table structure
echo "<h3>Current tblistings Table Structure:</h3>";
$columns = $conn->query("DESCRIBE tblistings");
echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
while ($col = $columns->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($col['Field']) . "</td>";
    echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
    echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
    echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
    echo "<td>" . htmlspecialchars($col['Default']) . "</td>";
    echo "</tr>";
}
echo "</table>";

$conn->close();
?>

<p style="margin-top: 20px;">
    <a href="DashboardAddUnit.php" style="padding: 10px 20px; background: #8B4513; color: white; text-decoration: none; border-radius: 5px;">Go to Add Unit</a>
    <a href="DashboardUO.php" style="padding: 10px 20px; background: #6b7280; color: white; text-decoration: none; border-radius: 5px; margin-left: 10px;">Go to Dashboard</a>
</p>
