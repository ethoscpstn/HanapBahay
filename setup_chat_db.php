<?php
require_once 'mysql_connect.php';

echo "<h2>Setting up Chat Database Tables</h2>\n";

// Create chat_quick_replies table
$sql1 = "CREATE TABLE IF NOT EXISTS `chat_quick_replies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `message` varchar(255) NOT NULL,
  `category` varchar(50) DEFAULT 'general',
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

if ($conn->query($sql1) === TRUE) {
    echo "✅ Table 'chat_quick_replies' created successfully<br>\n";
} else {
    echo "❌ Error creating chat_quick_replies table: " . $conn->error . "<br>\n";
}

// Create chat_auto_replies table
$sql2 = "CREATE TABLE IF NOT EXISTS `chat_auto_replies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trigger_pattern` varchar(255) NOT NULL,
  `response_message` text NOT NULL,
  `category` varchar(50) DEFAULT 'general',
  `is_active` tinyint(1) DEFAULT 1,
  `match_type` enum('contains', 'starts_with', 'exact', 'regex') DEFAULT 'contains',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

if ($conn->query($sql2) === TRUE) {
    echo "✅ Table 'chat_auto_replies' created successfully<br>\n";
} else {
    echo "❌ Error creating chat_auto_replies table: " . $conn->error . "<br>\n";
}

// Insert default quick reply prompts
$quick_replies = [
    ['What services do you offer?', 'services', 1],
    ['Can I schedule a viewing?', 'viewing', 2],
    ['What are your rates?', 'pricing', 3],
    ['Is parking available?', 'amenities', 4],
    ['What utilities are included?', 'utilities', 5],
    ['Are pets allowed?', 'policies', 6]
];

echo "<br><h3>Inserting Quick Reply Prompts</h3>\n";
foreach ($quick_replies as $reply) {
    $check_sql = "SELECT COUNT(*) as count FROM chat_quick_replies WHERE message = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param('s', $reply[0]);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $check_stmt->close();

    if ($count == 0) {
        $insert_sql = "INSERT INTO chat_quick_replies (message, category, display_order) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($insert_sql);
        $stmt->bind_param('ssi', $reply[0], $reply[1], $reply[2]);
        if ($stmt->execute()) {
            echo "✅ Added: {$reply[0]}<br>\n";
        } else {
            echo "❌ Error adding: {$reply[0]} - " . $stmt->error . "<br>\n";
        }
        $stmt->close();
    } else {
        echo "⚠️ Already exists: {$reply[0]}<br>\n";
    }
}

// Insert default auto-reply patterns
$auto_replies = [
    ['services', 'We offer residential rental services including property viewing, application processing, and tenant support. Our properties range from studio units to family homes with various amenities.', 'services', 'contains'],
    ['viewing', 'I can help arrange a property viewing! Please let me know your preferred date and time, and I\'ll check availability with the property owner.', 'viewing', 'contains'],
    ['schedule', 'I can help arrange a property viewing! Please let me know your preferred date and time, and I\'ll check availability with the property owner.', 'viewing', 'contains'],
    ['rates', 'Property rates vary depending on location, size, and amenities. You can view specific pricing details on each property listing. Would you like information about a particular property?', 'pricing', 'contains'],
    ['price', 'Property rates vary depending on location, size, and amenities. You can view specific pricing details on each property listing. Would you like information about a particular property?', 'pricing', 'contains'],
    ['parking', 'Parking availability varies by property. Some include dedicated parking spaces while others may have street parking. Please check the specific property details or ask about a particular listing.', 'amenities', 'contains'],
    ['utilities', 'Utility inclusions vary by property. Some rentals include water and electricity, while others may be separate. Please check the property details or ask about specific utilities for the property you\'re interested in.', 'utilities', 'contains'],
    ['pets', 'Pet policies vary by property and owner preference. Some properties welcome pets while others don\'t allow them. Please ask about the specific pet policy for the property you\'re interested in.', 'policies', 'contains'],
    ['hello', 'Hello! Welcome to HanapBahay. How can I help you find your ideal rental property today?', 'greeting', 'contains'],
    ['hi', 'Hi there! Welcome to HanapBahay. How can I help you find your ideal rental property today?', 'greeting', 'contains'],
    ['thank you', 'You\'re welcome! Feel free to ask if you have any other questions about our rental properties.', 'courtesy', 'contains'],
    ['thanks', 'You\'re welcome! Feel free to ask if you have any other questions about our rental properties.', 'courtesy', 'contains']
];

echo "<br><h3>Inserting Auto-Reply Patterns</h3>\n";
foreach ($auto_replies as $reply) {
    $check_sql = "SELECT COUNT(*) as count FROM chat_auto_replies WHERE trigger_pattern = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param('s', $reply[0]);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    $check_stmt->close();

    if ($count == 0) {
        $insert_sql = "INSERT INTO chat_auto_replies (trigger_pattern, response_message, category, match_type) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_sql);
        $stmt->bind_param('ssss', $reply[0], $reply[1], $reply[2], $reply[3]);
        if ($stmt->execute()) {
            echo "✅ Added trigger: '{$reply[0]}'<br>\n";
        } else {
            echo "❌ Error adding trigger: '{$reply[0]}' - " . $stmt->error . "<br>\n";
        }
        $stmt->close();
    } else {
        echo "⚠️ Already exists: '{$reply[0]}'<br>\n";
    }
}

echo "<br><h3>Setup Complete!</h3>\n";
echo "<p>You can now delete this setup file for security.</p>\n";

$conn->close();
?>