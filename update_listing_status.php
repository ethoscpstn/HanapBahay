<?php
/**
 * Syncs listing availability fields based on current occupancy.
 *
 * @param int $listing_id
 * @param mysqli|null $conn
 * @return bool
 */
function updateListingAvailability($listing_id, $conn = null) {
    if (!$conn) {
        require_once 'mysql_connect.php';
        $conn = $GLOBALS['conn'];
    }

    try {
        $stmt = $conn->prepare("
            SELECT total_units,
                   occupied_units,
                   is_available,
                   auto_delist,
                   is_archived,
                   is_visible,
                   availability_status
            FROM tblistings
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->bind_param("i", $listing_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $listing = $result->fetch_assoc();
        $stmt->close();

        if (!$listing) {
            return false;
        }

        $total_units = max(0, (int)$listing['total_units']);
        $occupied_units = max(0, (int)$listing['occupied_units']);
        $available_units = max(0, $total_units - $occupied_units);
        $should_be_available = $available_units > 0;

        $new_is_available = $should_be_available ? 1 : 0;
        $new_status = $should_be_available ? 'available' : 'unavailable';
        $new_is_visible = $should_be_available ? 1 : 0;
        $new_is_archived = $should_be_available ? 0 : 1;

        $is_currently_available = (int)$listing['is_available'] === 1;
        $is_currently_archived = (int)$listing['is_archived'] === 1;
        $is_currently_visible = (int)$listing['is_visible'] === 1;
        $current_status = (string)$listing['availability_status'];

        if (
            $new_is_available !== $is_currently_available ||
            $new_is_archived !== $is_currently_archived ||
            $new_is_visible !== $is_currently_visible ||
            $new_status !== $current_status
        ) {
            $stmt = $conn->prepare("
                UPDATE tblistings
                SET is_available = ?,
                    availability_status = ?,
                    is_visible = ?,
                    is_archived = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                "isiii",
                $new_is_available,
                $new_status,
                $new_is_visible,
                $new_is_archived,
                $listing_id
            );

            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        return true;
    } catch (Exception $e) {
        error_log("Error updating listing availability: " . $e->getMessage());
        return false;
    }
}

/**
 * Applies a delta to occupied units and keeps availability data in sync.
 *
 * @param int $listing_id
 * @param int $delta Positive numbers reserve units, negative numbers free units.
 * @param mysqli|null $conn
 * @return bool
 */
function applyOccupancyChange($listing_id, $delta = 0, $conn = null) {
    if (!$conn) {
        require_once 'mysql_connect.php';
        $conn = $GLOBALS['conn'];
    }

    if ($delta === 0) {
        return updateListingAvailability($listing_id, $conn);
    }

    $delta = (int)$delta;
    $absDelta = abs($delta);

    try {
        if ($delta > 0) {
            $stmt = $conn->prepare("
                UPDATE tblistings
                SET occupied_units = LEAST(total_units, occupied_units + ?)
                WHERE id = ?
            ");
        } else {
            $stmt = $conn->prepare("
                UPDATE tblistings
                SET occupied_units = GREATEST(0, occupied_units - ?)
                WHERE id = ?
            ");
        }

        $stmt->bind_param("ii", $absDelta, $listing_id);
        $stmt->execute();
        $stmt->close();

        return updateListingAvailability($listing_id, $conn);
    } catch (Exception $e) {
        error_log("Error applying occupancy change: " . $e->getMessage());
        return false;
    }
}
