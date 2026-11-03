<?php

require_once __DIR__ . '/db.php';

/**
 * Numbers sessions 1..n in the given order (by session_number), skipping
 * cancelled ones, so a cancelled class leaves no gap in what students and
 * admins see. Stored session_number is never shown. Cancelled rows (and only
 * rows that carry is_cancelled) get display_number = null.
 */
function addDisplayNumbers(array $sessions): array
{
    $number = 0;
    foreach ($sessions as &$session) {
        $session['display_number'] = empty($session['is_cancelled']) ? ++$number : null;
    }
    unset($session);

    return $sessions;
}

/**
 * Builds the check-in grid for a student in a class instance, and computes
 * % complete against only sessions that have already happened (session_date
 * <= the most recent past session's date), not future scheduled ones.
 */
function getAttendanceSummary(mysqli $mysqli, int $classInstanceId, int $studentId): array
{
    $stmt = $mysqli->prepare(
        'SELECT id, session_number, session_date FROM sessions
         WHERE class_instance_id = ? AND is_cancelled = 0
         ORDER BY session_number'
    );
    $stmt->bind_param('i', $classInstanceId);
    $stmt->execute();
    $sessions = addDisplayNumbers($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    $checkinStmt = $mysqli->prepare('SELECT session_id FROM checkins WHERE student_id = ?');
    $checkinStmt->bind_param('i', $studentId);
    $checkinStmt->execute();
    $checkedInIds = array_column($checkinStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'session_id');
    $checkinStmt->close();

    $today = date('Y-m-d');
    $pastDates = array_filter(array_column($sessions, 'session_date'), fn ($d) => $d <= $today);
    $latestPastDate = $pastDates === [] ? null : max($pastDates);

    $conductedCount = 0;
    $completedCount = 0;

    foreach ($sessions as &$session) {
        $session['checked_in'] = in_array((int) $session['id'], $checkedInIds, true);

        if ($latestPastDate !== null && $session['session_date'] <= $latestPastDate) {
            $conductedCount++;
            if ($session['checked_in']) {
                $completedCount++;
            }
        }
    }
    unset($session);

    $percent = $conductedCount > 0 ? round($completedCount / $conductedCount * 100, 1) : 0.0;

    return [
        'sessions' => $sessions,
        'percent' => $percent,
        'conducted_count' => $conductedCount,
        'completed_count' => $completedCount,
    ];
}
