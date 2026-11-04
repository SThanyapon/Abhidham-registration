<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/student_validation.php';

/**
 * The prefix to display: the free-text prefix_other when the student chose "อื่นๆ", else prefix.
 * Expects an array with 'prefix' and 'prefix_other' keys (e.g. a students row).
 */
function studentPrefix(array $student): string
{
    return $student['prefix'] === 'อื่นๆ' ? (string) ($student['prefix_other'] ?? '') : (string) $student['prefix'];
}

/**
 * A registration choice answer for display/CSV, with อื่นๆ shown as "อื่นๆ: <free text>" ('multi'
 * answers keep their comma-separated list), '-' when unanswered. $field is a STUDENT_CHOICE_FIELDS
 * key, read with its {field}_other from $student.
 */
function studentChoice(array $student, string $field): string
{
    $values = choiceValues($student[$field] ?? null);
    if ($values === []) {
        return '-';
    }

    return implode(STUDENT_MULTI_SEPARATOR, array_map(
        fn (string $value): string => $value === 'อื่นๆ' ? 'อื่นๆ: ' . ($student[$field . '_other'] ?? '') : $value,
        $values
    ));
}

/**
 * An address (a students or book_confirmations row: address, sub_district, district, province,
 * postal_code, country) as display lines: the street address lines, then ตำบล อำเภอ จังหวัด
 * รหัสไปรษณีย์ on one line, then the country when abroad. Empty parts are skipped.
 */
function formatAddressLines(array $row): array
{
    $lines = preg_split('/\R/', trim((string) ($row['address'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
    $area = implode(' ', array_filter(
        [$row['sub_district'] ?? '', $row['district'] ?? '', $row['province'] ?? '', $row['postal_code'] ?? ''],
        fn ($part): bool => (string) $part !== ''
    ));
    if ($area !== '') {
        $lines[] = $area;
    }
    if (studentResidence($row) === RESIDENCE_ABROAD) {
        $lines[] = (string) $row['country'];
    }

    return $lines;
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
