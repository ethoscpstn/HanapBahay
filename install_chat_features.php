<?php
// Installation script for chat predefined prompts and auto-reply features
// Run this once to create the required database tables and insert default data

require_once 'mysql_connect.php';

echo "Installing Chat Features...\n";

try {
    // Read and execute the SQL file
    $sql = file_get_contents(__DIR__ . '/api/chat/chat_prompts.sql');

    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        if (!empty($statement)) {
            if ($conn->query($statement)) {
                echo "✓ Executed: " . substr($statement, 0, 50) . "...\n";
            } else {
                echo "✗ Error executing: " . substr($statement, 0, 50) . "...\n";
                echo "MySQL Error: " . $conn->error . "\n";
            }
        }
    }

    echo "\n✓ Chat features installation completed successfully!\n";
    echo "\nFeatures installed:\n";
    echo "- Quick reply prompts for tenants\n";
    echo "- Auto-reply system based on message content\n";
    echo "- Default prompts: 'What services do you offer?', 'Can I schedule a viewing?', etc.\n";
    echo "- Auto-replies for common inquiries about services, pricing, amenities, etc.\n";

} catch (Exception $e) {
    echo "✗ Installation failed: " . $e->getMessage() . "\n";
}

$conn->close();
?>