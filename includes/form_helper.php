<?php
// Store form data in session on error
function store_form_data() {
    $_SESSION['form_data'] = [
        'property_type' => $_POST['property_type'] ?? '',
        'capacity' => $_POST['capacity'] ?? '',
        'total_units' => $_POST['total_units'] ?? '1',
        'description' => $_POST['description'] ?? '',
        'price' => $_POST['price'] ?? '',
        'rental_type' => $_POST['rental_type'] ?? 'residential',
        'address' => $_POST['address'] ?? '',
        'latitude' => $_POST['latitude'] ?? '',
        'longitude' => $_POST['longitude'] ?? '',
        'amenities' => $_POST['amenities'] ?? [],
        'bedroom' => $_POST['bedroom'] ?? '',
        'unit_sqm' => $_POST['unit_sqm'] ?? '',
        'kitchen' => $_POST['kitchen'] ?? '',
        'kitchen_type' => $_POST['kitchen_type'] ?? '',
        'gender_specific' => $_POST['gender_specific'] ?? '',
        'pets' => $_POST['pets'] ?? ''
    ];
}

// Clear stored form data
function clear_form_data() {
    unset($_SESSION['form_data']);
}

// Get stored form data or default value
function get_form_value($field, $default = '') {
    if (isset($_SESSION['form_data'][$field])) {
        $value = $_SESSION['form_data'][$field];
        if (is_array($value)) {
            return $value;
        }
        return htmlspecialchars($value);
    }
    return $default;
}
?>