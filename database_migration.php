<?php
// Enhanced Database Migration Script
require 'mysql_connect.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Migration - HanapBahay</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #8B4513; }
        h2 { color: #555; border-bottom: 2px solid #8B4513; padding-bottom: 10px; margin-top: 30px; }
        .success { color: #059669; background: #d1fae5; padding: 10px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #059669; }
        .error { color: #dc2626; background: #fee2e2; padding: 10px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #dc2626; }
        .info { color: #0284c7; background: #e0f2fe; padding: 10px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #0284c7; }
        .warning { color: #d97706; background: #fef3c7; padding: 10px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #d97706; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; background: white; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #8B4513; color: white; }
        tr:nth-child(even) { background: #f9fafb; }
        .btn { display: inline-block; padding: 10px 20px; margin: 5px; background: #8B4513; color: white; text-decoration: none; border-radius: 5px; }
        .btn:hover { background: #6d3610; }
        .btn-secondary { background: #6b7280; }
        .btn-secondary:hover { background: #4b5563; }
        .summary { background: #f9fafb; padding: 20px; border-radius: 5px; margin: 20px 0; }
        .summary-item { display: inline-block; margin: 10px 20px 10px 0; }
        .summary-item strong { font-size: 24px; color: #8B4513; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 HanapBahay Database Migration</h1>
        <p>This script will update your database structure to support all features.</p>

<?php
$errors = [];
$success = [];
$info = [];

// MIGRATION 1: ML Prediction Columns
echo "<h2>1. ML Prediction Columns</h2>";
$ml_columns = [
    'bedroom' => "INT DEFAULT 1 COMMENT 'Number of bedrooms'",
    'unit_sqm' => "DECIMAL(10,2) DEFAULT 20.00 COMMENT 'Unit size in square meters'",
    'kitchen' => "VARCHAR(10) DEFAULT 'Yes' COMMENT 'Kitchen available (Yes/No)'",
    'kitchen_type' => "VARCHAR(20) DEFAULT 'Private' COMMENT 'Kitchen type (Private/Shared)'",
    'gender_specific' => "VARCHAR(20) DEFAULT 'Mixed' COMMENT 'Gender restriction'",
    'pets' => "VARCHAR(20) DEFAULT 'Allowed' COMMENT 'Pet policy'"
];

foreach ($ml_columns as $col => $definition) {
    $check = $conn->query("SHOW COLUMNS FROM tblistings LIKE '$col'");
    if ($check && $check->num_rows > 0) {
        $info[] = "Column '$col' already exists";
        echo "<div class='info'>✓ Column <strong>$col</strong> already exists</div>";
    } else {
        $sql = "ALTER TABLE tblistings ADD COLUMN $col $definition";
        if ($conn->query($sql)) {
            $success[] = "Added column '$col'";
            echo "<div class='success'>✓ Added column <strong>$col</strong></div>";
        } else {
            $errors[] = "Failed to add '$col': " . $conn->error;
            echo "<div class='error'>✗ Failed to add <strong>$col</strong>: " . $conn->error . "</div>";
        }
    }
}

// MIGRATION 2: Amenities column
echo "<h2>2. Amenities Storage</h2>";
$check = $conn->query("SHOW COLUMNS FROM tblistings LIKE 'amenities'");
if ($check && $check->num_rows > 0) {
    $info[] = "Column 'amenities' already exists";
    echo "<div class='info'>✓ Column <strong>amenities</strong> already exists</div>";
} else {
    $sql = "ALTER TABLE tblistings ADD COLUMN amenities TEXT COMMENT 'Comma-separated amenities list'";
    if ($conn->query($sql)) {
        $success[] = "Added column 'amenities'";
        echo "<div class='success'>✓ Added column <strong>amenities</strong></div>";
    } else {
        $errors[] = "Failed to add 'amenities': " . $conn->error;
        echo "<div class='error'>✗ Failed to add <strong>amenities</strong>: " . $conn->error . "</div>";
    }
}

// MIGRATION 3: Database Indexes for Performance
echo "<h2>3. Performance Indexes</h2>";
$indexes = [
    'idx_owner_id' => 'owner_id',
    'idx_is_archived' => 'is_archived',
    'idx_price' => 'price',
    'idx_capacity' => 'capacity'
];

foreach ($indexes as $idx_name => $column) {
    $check = $conn->query("SHOW INDEX FROM tblistings WHERE Key_name = '$idx_name'");
    if ($check && $check->num_rows > 0) {
        $info[] = "Index '$idx_name' already exists";
        echo "<div class='info'>✓ Index <strong>$idx_name</strong> exists</div>";
    } else {
        $sql = "CREATE INDEX $idx_name ON tblistings($column)";
        if ($conn->query($sql)) {
            $success[] = "Created index '$idx_name'";
            echo "<div class='success'>✓ Created index <strong>$idx_name</strong></div>";
        } else {
            // Index creation might fail if already exists via different means
            $info[] = "Index '$idx_name' may already exist";
            echo "<div class='info'>→ Index <strong>$idx_name</strong> creation skipped</div>";
        }
    }
}

// MIGRATION 4: Update existing records with default values
echo "<h2>4. Data Integrity Check</h2>";
$update_sql = "UPDATE tblistings SET
    bedroom = COALESCE(bedroom, 1),
    unit_sqm = COALESCE(unit_sqm, 20.00),
    kitchen = COALESCE(kitchen, 'Yes'),
    kitchen_type = COALESCE(kitchen_type, 'Private'),
    gender_specific = COALESCE(gender_specific, 'Mixed'),
    pets = COALESCE(pets, 'Allowed')
    WHERE bedroom IS NULL OR unit_sqm IS NULL OR kitchen IS NULL";

if ($conn->query($update_sql)) {
    $affected = $conn->affected_rows;
    if ($affected > 0) {
        echo "<div class='success'>✓ Updated $affected existing record(s) with default values</div>";
        $success[] = "Updated $affected records";
    } else {
        echo "<div class='info'>✓ All existing records have valid data</div>";
    }
} else {
    echo "<div class='warning'>⚠ Could not update existing records: " . $conn->error . "</div>";
}

// Summary
echo "<div class='summary'>";
echo "<h2>📊 Migration Summary</h2>";
echo "<div class='summary-item'><strong>" . count($success) . "</strong><br>Successful Changes</div>";
echo "<div class='summary-item'><strong>" . count($errors) . "</strong><br>Errors</div>";
echo "<div class='summary-item'><strong>" . count($info) . "</strong><br>Already Configured</div>";
echo "</div>";

if (count($errors) > 0) {
    echo "<div class='error'><strong>Errors encountered:</strong><ul>";
    foreach ($errors as $err) {
        echo "<li>" . htmlspecialchars($err) . "</li>";
    }
    echo "</ul></div>";
}

// Show current table structure
echo "<h2>5. Current Database Structure</h2>";
$columns = $conn->query("DESCRIBE tblistings");
if ($columns) {
    echo "<table>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($col = $columns->fetch_assoc()) {
        echo "<tr>";
        echo "<td><strong>" . htmlspecialchars($col['Field']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Default'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($col['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

$conn->close();
?>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #e5e7eb;">
            <h3>✅ Next Steps:</h3>
            <p>Migration complete! You can now use all features.</p>
            <a href="DashboardAddUnit.php" class="btn">Add New Property</a>
            <a href="DashboardUO.php" class="btn btn-secondary">Go to Dashboard</a>
            <a href="edit_listing.php?id=<?php
                $check = mysqli_query($GLOBALS['conn'] ?? null, 'SELECT id FROM tblistings LIMIT 1');
                if ($check && $row = $check->fetch_assoc()) echo $row['id'];
            ?>" class="btn btn-secondary">Test Edit Listing</a>
        </div>
    </div>
</body>
</html>
