<?php
session_start();
require 'mysql_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ensure the user is logged in
    if (!isset($_SESSION['owner_id'])) {
        header("Location: LoginModule.php");
        exit();
    }

    $owner_id   = $_SESSION['owner_id'];
    $title      = trim($_POST['title']);
    $description= trim($_POST['description']);
    $address    = trim($_POST['address']);
    $price      = (float)$_POST['price'];
    $capacity   = (int)$_POST['capacity'];

    // --- Google Geocoding API ---
    $apiKey = "AIzaSyCrKcnAX9KOdNp_TNHwWwzbLSQodgYqgnU"; // ✅ your key
    $lat = null;
    $lng = null;

    if ($address !== '') {
        $url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($address) . "&key=" . $apiKey;
        $resp = file_get_contents($url);
        $data = json_decode($resp, true);

        if ($data && $data['status'] === 'OK') {
            $loc = $data['results'][0]['geometry']['location'];
            $lat = $loc['lat'];
            $lng = $loc['lng'];
        }
    }

    // --- Insert listing ---
    $sql = "INSERT INTO tblistings (title, description, address, latitude, longitude, price, capacity, owner_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssdddii", $title, $description, $address, $lat, $lng, $price, $capacity, $owner_id);

    if ($stmt->execute()) {
        header("Location: DashboardUO.php");
        exit();
    } else {
        echo "Error: " . $stmt->error;
    }

    $stmt->close();
    $conn->close();
}
?>
