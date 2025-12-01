<?php
/**
 * Shared helpers for working with location tokens and suggestions.
 */

if (!function_exists('hb_extract_location_tokens')) {
    /**
     * Extract significant location tokens from a free-form address string.
     *
     * @param string|null $address
     * @return array<int, string> Deduplicated tokens preserving readable casing.
     */
    function hb_extract_location_tokens(?string $address): array
    {
        if (!$address) {
            return [];
        }

        $tokens = [];
        $parts = preg_split('/[,;|]+/', $address);
        if (!$parts) {
            return [];
        }

        foreach ($parts as $part) {
            $part = trim(preg_replace('/\s+/', ' ', (string)$part));
            if ($part === '' || mb_strlen($part) < 3) {
                continue;
            }

            // Skip values that are purely numeric (e.g., street numbers)
            if (preg_match('/^\d+$/', $part)) {
                continue;
            }

            $normalized = mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
            $key = mb_strtolower($normalized, 'UTF-8');

            if (!isset($tokens[$key])) {
                $tokens[$key] = $normalized;
            }
        }

        return array_values($tokens);
    }
}

if (!function_exists('hb_collect_location_suggestions')) {
    /**
     * Build a ranked list of popular location strings from listings.
     *
     * @param array<int, array<string, mixed>> $entries
     * @param int $limit
     * @return array<int, string>
     */
    function hb_collect_location_suggestions(array $entries, int $limit = 12): array
    {
        $counts = [];

        foreach ($entries as $entry) {
            $tokens = [];

            if (isset($entry['location_tokens']) && is_array($entry['location_tokens'])) {
                $tokens = $entry['location_tokens'];
            } elseif (isset($entry['address'])) {
                $tokens = hb_extract_location_tokens($entry['address']);
            } elseif (isset($entry['address_text'])) {
                $tokens = hb_extract_location_tokens($entry['address_text']);
            }

            foreach ($tokens as $token) {
                $key = mb_strtolower($token, 'UTF-8');
                if ($key === '') {
                    continue;
                }

                if (!isset($counts[$key])) {
                    $counts[$key] = ['label' => $token, 'count' => 0];
                } elseif (mb_strlen($token) > mb_strlen($counts[$key]['label'])) {
                    // Prefer the longest descriptive label encountered
                    $counts[$key]['label'] = $token;
                }

                $counts[$key]['count']++;
            }
        }

        if (empty($counts)) {
            return [];
        }

        uasort($counts, static function ($a, $b) {
            if ($a['count'] === $b['count']) {
                return strcasecmp($a['label'], $b['label']);
            }
            return $b['count'] <=> $a['count'];
        });

        $suggestions = [];
        foreach ($counts as $meta) {
            $suggestions[] = $meta['label'];
            if (count($suggestions) >= $limit) {
                break;
            }
        }

        return $suggestions;
    }
}
