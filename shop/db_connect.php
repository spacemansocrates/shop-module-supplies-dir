<?php
// db_connect.php

require_once __DIR__ . '/config.php';

// --- Create Connection ---
$conn = getMySQLi();


/**
 * Converts a datetime string to a "time ago" format.
 * This version is compatible with PHP 8.2+ and avoids dynamic property creation.
 *
 * @param string|null $datetime The datetime string to convert.
 * @param bool $full Whether to return a full string (e.g., "1 month, 2 weeks, 3 days ago") or just the largest unit ("1 month ago").
 * @return string The formatted time ago string.
 */
function time_ago($datetime, $full = false) {
    if ($datetime === null) {
        return 'N/A';
    }
    try {
        $now = new DateTime;
        $ago = new DateTime($datetime);
    } catch (Exception $e) {
        return 'Invalid date';
    }

    $diff = $now->diff($ago);

    // Calculate weeks and remaining days without modifying the $diff object.
    $weeks = floor($diff->d / 7);
    $days = $diff->d % 7;

    // Use a separate array to hold the time parts and their labels.
    $time_parts = [
        'y' => ['value' => $diff->y, 'label' => 'year'],
        'm' => ['value' => $diff->m, 'label' => 'month'],
        'w' => ['value' => $weeks, 'label' => 'week'],
        'd' => ['value' => $days, 'label' => 'day'],
        'h' => ['value' => $diff->h, 'label' => 'hour'],
        'i' => ['value' => $diff->i, 'label' => 'minute'],
        's' => ['value' => $diff->s, 'label' => 'second'],
    ];

    $result_strings = [];
    foreach ($time_parts as $part) {
        if ($part['value'] > 0) {
            $result_strings[] = $part['value'] . ' ' . $part['label'] . ($part['value'] > 1 ? 's' : '');
        }
    }

    if (empty($result_strings)) {
        return 'just now';
    }

    if (!$full) {
        // Return only the first (largest) time unit.
        return $result_strings[0] . ' ago';
    }

    return implode(', ', $result_strings) . ' ago';
}