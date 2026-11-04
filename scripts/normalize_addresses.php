<?php

// One-off cleanup: rewrites students' จังหวัด/อำเภอ/ตำบล to the postcode data's spelling
// (matchThaiPostcodeAddress(), the same check the CSV import now applies), e.g. กรุงเทพฯ ->
// กรุงเทพมหานคร, so their registration address passes books.php's strict check. Rows that don't fit
// their postcode are listed for fixing by hand in admin/students.php.
//
//   php scripts/normalize_addresses.php          dry run: list what would change
//   php scripts/normalize_addresses.php --apply  write the corrections (idempotent)
//
// Run `php cron/backup.php` before --apply.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/student_validation.php';

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$apply = in_array('--apply', $argv, true);
$mysqli = getDbConnection();

// Thai residence (blank country or ประเทศไทย) with a postcode; abroad rows have nothing to check.
$rows = $mysqli->query(
    "SELECT id, student_no, full_name, status, country, province, district, sub_district, postal_code
     FROM students
     WHERE (country IS NULL OR country IN ('', '" . COUNTRY_THAILAND . "'))
       AND postal_code IS NOT NULL AND postal_code <> ''
     ORDER BY id"
)->fetch_all(MYSQLI_ASSOC);

$update = $mysqli->prepare('UPDATE students SET province = ?, district = ?, sub_district = ? WHERE id = ?');
$fixable = 0;
$unfixable = 0;

foreach ($rows as $row) {
    $label = ($row['student_no'] ?? '-') . ' ' . $row['full_name'] . ' (' . $row['status'] . ', id ' . $row['id'] . ')';
    [$fixed, $corrections, $error] = matchThaiPostcodeAddress($row);

    if ($error !== null) {
        $unfixable++;
        echo "CANNOT FIX  $label: $error\n";
        continue;
    }
    if ($corrections === []) {
        continue;
    }

    $fixable++;
    echo ($apply ? 'FIXED       ' : 'WOULD FIX   ') . "$label: " . implode(', ', $corrections) . "\n";
    if ($apply) {
        // Blank parts stay NULL, like the forms store them.
        $province = $fixed['province'] === '' ? null : $fixed['province'];
        $district = $fixed['district'] === '' ? null : $fixed['district'];
        $subDistrict = $fixed['sub_district'] === '' ? null : $fixed['sub_district'];
        $id = (int) $row['id'];
        $update->bind_param('sssi', $province, $district, $subDistrict, $id);
        $update->execute();
    }
}

$update->close();
echo count($rows) . ' Thai address(es) checked: ' . $fixable . ($apply ? ' fixed' : ' to fix (dry run, use --apply)')
    . ', ' . $unfixable . " cannot be fixed automatically (edit in admin/students.php)\n";
