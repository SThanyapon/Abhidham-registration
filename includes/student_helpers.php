<?php

require_once __DIR__ . '/db.php';

/**
 * The prefix to display: the free-text prefix_other when the student chose "อื่นๆ", else prefix.
 * Expects an array with 'prefix' and 'prefix_other' keys (e.g. a students row).
 */
function studentPrefix(array $student): string
{
    return $student['prefix'] === 'อื่นๆ' ? (string) ($student['prefix_other'] ?? '') : (string) $student['prefix'];
}

/**
 * The class instance for the student's batch + current level, or null if that class hasn't been
 * created yet.
 */
function studentClassInstanceId(mysqli $mysqli, int $studentId): ?int
{
    $stmt = $mysqli->prepare(
        'SELECT ci.id
         FROM students s
         JOIN class_instances ci ON ci.batch_id = s.batch_id AND ci.class_level_id = s.current_class_level_id
         WHERE s.id = ?'
    );
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int) $row['id'] : null;
}
