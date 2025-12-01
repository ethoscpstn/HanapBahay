<?php

/**
 * Lightweight file-based cache for ML price predictions.
 * This avoids repeatedly calling the ML service on each page load.
 */

if (!defined('HB_ML_CACHE_FILE')) {
    define('HB_ML_CACHE_FILE', __DIR__ . '/../cache/ml_price_cache.json');
}

if (!defined('HB_ML_CACHE_TTL')) {
    // Cache predictions for 1 hour by default.
    define('HB_ML_CACHE_TTL', 3600);
}

if (!function_exists('hb_ml_ensure_cache_dir')) {
    function hb_ml_ensure_cache_dir(): void {
        $cacheDir = dirname(HB_ML_CACHE_FILE);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
    }
}

if (!function_exists('hb_ml_read_cache')) {
    function hb_ml_read_cache(): array {
        hb_ml_ensure_cache_dir();
        if (!file_exists(HB_ML_CACHE_FILE)) {
            return [];
        }

        $json = @file_get_contents(HB_ML_CACHE_FILE);
        if ($json === false) {
            return [];
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('hb_ml_write_cache')) {
    function hb_ml_write_cache(array $cache): void {
        hb_ml_ensure_cache_dir();
        $payload = json_encode($cache, JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        @file_put_contents(HB_ML_CACHE_FILE, $payload, LOCK_EX);
    }
}

if (!function_exists('hb_ml_feature_signature')) {
    function hb_ml_feature_signature(array $features): string {
        return sha1(json_encode($features, JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('hb_ml_build_features')) {
    function hb_ml_build_features(array $listing): array {
        $capacity   = max(1, (int)($listing['capacity'] ?? 1));
        $bedrooms   = max(1, (int)($listing['bedroom'] ?? 1));
        $unit_sqm   = (float)($listing['unit_sqm'] ?? 20);

        if (function_exists('infer_property_type_from_title')) {
            $property_type = infer_property_type_from_title($listing['title'] ?? '');
        } else {
            $property_type = (string)($listing['property_type'] ?? 'Apartment');
        }

        if (function_exists('extract_city_from_address')) {
            $city = extract_city_from_address($listing['address'] ?? '') ?: 'Metro Manila';
        } else {
            $city = 'Metro Manila';
        }

        return [
            'Capacity' => $capacity,
            'Bedroom' => $bedrooms,
            'unit_sqm' => $unit_sqm,
            'cap_per_bedroom' => $capacity / max(1, $bedrooms),
            'Type' => $property_type ?: 'Apartment',
            'Kitchen' => $listing['kitchen'] ?? 'Yes',
            'Kitchen type' => $listing['kitchen_type'] ?? 'Private',
            'Gender specific' => $listing['gender_specific'] ?? 'Mixed',
            'Pets' => $listing['pets'] ?? 'Allowed',
            'Location' => $city,
        ];
    }
}

if (!function_exists('hb_ml_request_prediction')) {
    function hb_ml_request_prediction(string $ml_api_url, array $payload): array {
        if (!function_exists('curl_init')) {
            return [null, 'curl_not_available'];
        }

        $ch = curl_init();
        if (!$ch) {
            return [null, 'curl_init_failed'];
        }

        curl_setopt($ch, CURLOPT_URL, $ml_api_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['inputs' => [$payload]], JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === null) {
            return [null, $error ?: 'empty_response'];
        }

        $decoded = json_decode($response, true);
        if (isset($decoded['prediction'])) {
            return [(float)$decoded['prediction'], null];
        }

        return [null, 'invalid_response'];
    }
}

if (!function_exists('hb_ml_get_price_prediction')) {
    /**
     * @return array{prediction: float|null, source: string, timestamp: int|null, error: string|null}
     */
    function hb_ml_get_price_prediction(array $listing, string $ml_api_url, int $ttl = HB_ML_CACHE_TTL): array {
        $features = hb_ml_build_features($listing);
        $signature = hb_ml_feature_signature($features);
        $cacheKey = (string)($listing['id'] ?? $signature);

        $cache = hb_ml_read_cache();
        $cached = $cache[$cacheKey] ?? null;
        $now = time();

        if ($cached && isset($cached['prediction'], $cached['signature'], $cached['timestamp'])) {
            $age = $now - (int)$cached['timestamp'];
            if ($cached['signature'] === $signature && $age <= $ttl) {
                return [
                    'prediction' => (float)$cached['prediction'],
                    'source' => 'cache',
                    'timestamp' => (int)$cached['timestamp'],
                    'error' => null,
                ];
            }
        }

        [$prediction, $error] = hb_ml_request_prediction($ml_api_url, $features);
        if ($prediction !== null) {
            $cache[$cacheKey] = [
                'prediction' => $prediction,
                'signature' => $signature,
                'timestamp' => $now,
            ];
            hb_ml_write_cache($cache);

            return [
                'prediction' => $prediction,
                'source' => 'live',
                'timestamp' => $now,
                'error' => null,
            ];
        }

        if ($cached && isset($cached['prediction'], $cached['timestamp'])) {
            return [
                'prediction' => (float)$cached['prediction'],
                'source' => 'stale',
                'timestamp' => (int)$cached['timestamp'],
                'error' => $error,
            ];
        }

        return [
            'prediction' => null,
            'source' => 'none',
            'timestamp' => null,
            'error' => $error,
        ];
    }
}
