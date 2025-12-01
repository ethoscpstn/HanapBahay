<?php

if (!function_exists('normalize_property_type_label')) {
    function normalize_property_type_label($value) {
        $value = strtolower(trim((string)$value));
        if ($value === '') {
            return '';
        }

        $map = [
            'studio' => 'Studio',
            'condominium' => 'Condominium',
            'condo' => 'Condominium',
            'apartment' => 'Apartment',
            'apt' => 'Apartment',
            'house' => 'House',
            'townhouse' => 'House',
            'room' => 'Room',
            'bedspace' => 'Room',
            'dorm' => 'Dormitory',
            'loft' => 'Loft',
            'boarding' => 'Boarding House',
            'boarding house' => 'Boarding House'
        ];

        foreach ($map as $needle => $label) {
            if (strpos($value, $needle) !== false) {
                return $label;
            }
        }

        return ucwords($value);
    }
}

if (!function_exists('infer_property_type_from_title')) {
    function infer_property_type_from_title($title) {
        $title = (string)$title;
        if ($title === '') return '';
        return normalize_property_type_label($title);
    }
}

if (!function_exists('extract_city_from_address')) {
    function extract_city_from_address($address) {
        if (!$address) return '';
        $address = strtolower((string)$address);
        $parts = array_map('trim', explode(',', $address));

        $city_map = [
            'manila' => 'Manila',
            'quezon city' => 'Quezon City',
            'caloocan' => 'Caloocan',
            'las piñas' => 'Las Piñas',
            'las pinas' => 'Las Piñas',
            'makati' => 'Makati',
            'malabon' => 'Malabon',
            'mandaluyong' => 'Mandaluyong',
            'marikina' => 'Marikina',
            'muntinlupa' => 'Muntinlupa',
            'navotas' => 'Navotas',
            'parañaque' => 'Parañaque',
            'paranaque' => 'Parañaque',
            'pasay' => 'Pasay',
            'pasig' => 'Pasig',
            'pateros' => 'Pateros',
            'san juan' => 'San Juan',
            'taguig' => 'Taguig',
            'valenzuela' => 'Valenzuela'
        ];

        foreach ($parts as $part) {
            foreach ($city_map as $needle => $label) {
                if (strpos($part, $needle) !== false) {
                    return $label;
                }
            }
        }

        return '';
    }
}
