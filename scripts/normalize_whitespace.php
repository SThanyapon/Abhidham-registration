<?php

// One-off cleanup: applies includes/input.php's whitespace rules to rows stored before those rules
// existed, so exact-match lookups (check-in, student ID lookup) work for older registrations too.
// Idempotent - safe to re-run. Consider running `php cron/backup.php` first.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/input.php';

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$targets = [
    ['students', 'full_name', 'cleanText'],
    ['students', 'prefix_other', 'cleanText'],
    ['students', 'phone', 'cleanText'],
    ['students', 'line_id', 'cleanText'],
    ['students', 'reference_person', 'cleanText'],
    ['students', 'address', 'cleanMultilineText'],
    ['students', 'province', 'cleanText'],
    ['students', 'line_name', 'cleanText'],
    ['students', 'heard_from_other', 'cleanText'],
    ['students', 'student_type_other', 'cleanText'],
    ['students', 'zoom_skill_other', 'cleanText'],
    ['students', 'joined_classroom_other', 'cleanText'],
    ['students', 'study_reason', 'cleanMultilineText'],
    ['batches', 'name', 'cleanText'],
];

$mysqli = getDbConnection();

foreach ($targets as [$table, $column, $cleaner]) {
    // Table/column names come from the fixed list above, never from input.
    $rows = $mysqli->query("SELECT id, `$column` AS value FROM `$table` WHERE `$column` IS NOT NULL")
        ->fetch_all(MYSQLI_ASSOC);

    $update = $mysqli->prepare("UPDATE `$table` SET `$column` = ? WHERE id = ?");
    $changed = 0;

    foreach ($rows as $row) {
        $cleaned = $cleaner($row['value']);
        if ($cleaned === $row['value']) {
            continue;
        }

        $id = (int) $row['id'];
        $update->bind_param('si', $cleaned, $id);
        $update->execute();
        $changed++;
    }

    $update->close();
    echo "$table.$column: $changed row(s) updated\n";
}
