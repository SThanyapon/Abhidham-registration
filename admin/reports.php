<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/date_helpers.php';
require_once __DIR__ . '/../includes/student_helpers.php';

$adminId = requireAdminLogin();
requireFeature($adminId, 3);

$mysqli = getDbConnection();

// Sortable columns for the all-students table: key => header label. Default: student ID ascending.
$sortColumns = [
    'student_no' => 'รหัสนักศึกษา',
    'prefix' => 'คำนำหน้า',
    'full_name' => 'ชื่อ-นามสกุล',
    'batch' => 'รุ่น',
    'level' => 'ระดับชั้น',
    'completed' => 'เข้าเรียน',
    'percent' => '%',
];
$sort = array_key_exists($_GET['sort'] ?? '', $sortColumns) ? $_GET['sort'] : 'student_no';
$dir = ($_GET['dir'] ?? '') === 'desc' ? 'desc' : 'asc';

// Student IDs are numeric strings of varying length, so order by length first (7201 < 10101).
$studentNoOrder = "s.student_no IS NULL, LENGTH(s.student_no) $dir, s.student_no $dir";
// Stored columns sort in SQL (MySQL collation handles Thai names); computed attendance columns are
// sorted in PHP below, on top of the default student ID order.
$orderBy = match ($sort) {
    // Sort by the displayed prefix (the free-text one when "อื่นๆ"), matching studentPrefix().
    'prefix' => "CASE WHEN s.prefix = 'อื่นๆ' THEN s.prefix_other ELSE s.prefix END $dir, " . $studentNoOrder,
    'full_name' => "s.full_name $dir, " . $studentNoOrder,
    'batch' => "b.batch_no $dir, " . $studentNoOrder,
    'level' => "cl.sort_order $dir, " . $studentNoOrder,
    'student_no' => $studentNoOrder,
    default => 's.student_no IS NULL, LENGTH(s.student_no), s.student_no',
};

$students = $mysqli->query(
    "SELECT s.id, s.student_no, s.prefix, s.prefix_other, s.full_name, cl.name AS level_name, b.batch_no, b.name AS batch_name
     FROM students s
     JOIN class_levels cl ON cl.id = s.current_class_level_id
     JOIN batches b ON b.id = s.batch_id
     WHERE s.status = 'approved'
     ORDER BY $orderBy"
)->fetch_all(MYSQLI_ASSOC);

$selectedStudentId = (int) ($_GET['student_id'] ?? 0);
$exportCsv = ($_GET['export'] ?? '') === 'csv';

$selectedStudent = null;
$singleReport = null;

if ($selectedStudentId > 0) {
    foreach ($students as $student) {
        if ((int) $student['id'] === $selectedStudentId) {
            $selectedStudent = $student;
            break;
        }
    }

    if ($selectedStudent) {
        $classInstanceId = studentClassInstanceId($mysqli, $selectedStudentId);
        if ($classInstanceId !== null) {
            $singleReport = getAttendanceSummary($mysqli, $classInstanceId, $selectedStudentId);
        }
    }
}

$allReports = [];
if ($selectedStudentId === 0) {
    foreach ($students as $student) {
        $classInstanceId = studentClassInstanceId($mysqli, (int) $student['id']);
        $allReports[] = [
            'student' => $student,
            'summary' => $classInstanceId !== null
                ? getAttendanceSummary($mysqli, $classInstanceId, (int) $student['id'])
                : null,
        ];
    }

    if ($sort === 'completed' || $sort === 'percent') {
        $metric = $sort === 'completed' ? 'completed_count' : 'percent';
        // usort is stable, so ties keep the student ID order from the query.
        usort($allReports, function (array $a, array $b) use ($metric, $dir): int {
            // Students with no class schedule have no summary; keep them last either way.
            if ($a['summary'] === null || $b['summary'] === null) {
                return ($a['summary'] === null) <=> ($b['summary'] === null);
            }
            $cmp = $a['summary'][$metric] <=> $b['summary'][$metric];

            return $dir === 'desc' ? -$cmp : $cmp;
        });
    }
}

function sortHeader(string $key, string $label, string $sort, string $dir): string
{
    $isActive = $key === $sort;
    $nextDir = $isActive && $dir === 'asc' ? 'desc' : 'asc';
    $indicator = $isActive
        ? '<span class="sort-indicator">' . ($dir === 'asc' ? '▲' : '▼') . '</span>'
        : '<span class="sort-indicator inactive">↕</span>';

    return '<a class="sort-link" href="reports.php?sort=' . urlencode($key) . '&amp;dir=' . $nextDir . '">'
        . htmlspecialchars($label) . $indicator . '</a>';
}

if ($exportCsv) {
    $filename = $selectedStudent
        ? 'attendance_' . preg_replace('/[^A-Za-z0-9]+/', '_', $selectedStudent['student_no'] ?? (string) $selectedStudentId) . '.csv'
        : 'attendance_all_students.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders Thai text correctly

    if ($selectedStudent) {
        fputcsv($out, ['รหัสนักศึกษา', 'คำนำหน้า', 'ชื่อ-นามสกุล', 'รุ่น', 'ระดับชั้น']);
        fputcsv($out, [
            $selectedStudent['student_no'] ?? '-',
            studentPrefix($selectedStudent),
            $selectedStudent['full_name'],
            $selectedStudent['batch_name'] ?: 'รุ่น ' . $selectedStudent['batch_no'],
            $selectedStudent['level_name'],
        ]);
        fputcsv($out, []);
        fputcsv($out, ['ครั้งที่', 'วันที่เรียน', 'ลงชื่อเข้าเรียน']);
        foreach (($singleReport['sessions'] ?? []) as $session) {
            fputcsv($out, [
                $session['session_number'],
                $session['session_date'],
                $session['checked_in'] ? 'ใช่' : 'ไม่ใช่',
            ]);
        }
    } else {
        fputcsv($out, ['รหัสนักศึกษา', 'คำนำหน้า', 'ชื่อ-นามสกุล', 'รุ่น', 'ระดับชั้น', 'เข้าเรียน (ครั้ง)', 'จัดสอนแล้ว (ครั้ง)', 'ร้อยละ']);
        foreach ($allReports as $row) {
            $s = $row['student'];
            $summary = $row['summary'];
            fputcsv($out, [
                $s['student_no'] ?? '-',
                studentPrefix($s),
                $s['full_name'],
                $s['batch_name'] ?: 'รุ่น ' . $s['batch_no'],
                $s['level_name'],
                $summary['completed_count'] ?? '-',
                $summary['conducted_count'] ?? '-',
                $summary !== null ? $summary['percent'] . '%' : '-',
            ]);
        }
    }

    fclose($out);
    exit;
}

$exportHref = 'reports.php?export=csv&sort=' . urlencode($sort) . '&dir=' . $dir
    . ($selectedStudentId > 0 ? '&student_id=' . $selectedStudentId : '');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap">
    <title>รายงานการเข้าเรียน - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <h1>รายงานการเข้าเรียน</h1>
    <nav>
        <a href="dashboard.php">กลับหน้าแผงควบคุม</a>
        <a class="nav-logout" href="logout.php">ออกจากระบบ</a>
    </nav>

    <form action="reports.php" method="get">
        <label for="student_id">นักศึกษา</label>
        <select id="student_id" name="student_id" onchange="this.form.submit()">
            <option value="0" <?= $selectedStudentId === 0 ? 'selected' : '' ?>>-- นักศึกษาทั้งหมด --</option>
            <?php foreach ($students as $student): ?>
                <option value="<?= $student['id'] ?>" <?= $selectedStudentId === (int) $student['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars(($student['student_no'] ?? '-') . ' - ' . studentPrefix($student) . ' ' . $student['full_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">ดูรายงาน</button>
    </form>

    <p><a href="<?= htmlspecialchars($exportHref) ?>">ส่งออกรายงานนี้เป็นไฟล์ CSV</a></p>

    <?php if ($selectedStudentId > 0): ?>
        <?php if (!$selectedStudent): ?>
            <p class="error">ไม่พบนักศึกษา</p>
        <?php elseif (!$singleReport): ?>
            <p class="error">ยังไม่มีตารางเรียนสำหรับระดับชั้นปัจจุบันของนักศึกษาคนนี้</p>
        <?php else: ?>
            <div class="card">
                <p>
                    <strong><?= htmlspecialchars(($selectedStudent['student_no'] ?? '-') . ' ' . studentPrefix($selectedStudent) . ' ' . $selectedStudent['full_name']) ?></strong>
                    — <?= htmlspecialchars($selectedStudent['batch_name'] ?: 'รุ่น ' . $selectedStudent['batch_no']) ?>
                    / <?= htmlspecialchars($selectedStudent['level_name']) ?>
                </p>
                <p class="progress">
                    ความก้าวหน้า: <?= $singleReport['completed_count'] ?> / <?= $singleReport['conducted_count'] ?>
                    (<?= $singleReport['percent'] ?>%)
                </p>
                <div class="session-grid">
                    <?php foreach ($singleReport['sessions'] as $session): ?>
                        <div class="session-box <?= $session['checked_in'] ? 'checked' : 'missing' ?>"
                             title="<?= htmlspecialchars(formatDateBEShort($session['session_date'])) ?>">
                            <?= $session['session_number'] ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <?php foreach ($sortColumns as $key => $label): ?>
                        <th><?= sortHeader($key, $label, $sort, $dir) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allReports as $row): $s = $row['student']; $summary = $row['summary']; ?>
                    <tr>
                        <td><?= htmlspecialchars($s['student_no'] ?? '-') ?></td>
                        <td><?= htmlspecialchars(studentPrefix($s)) ?></td>
                        <td><?= htmlspecialchars($s['full_name']) ?></td>
                        <td><?= htmlspecialchars($s['batch_name'] ?: 'รุ่น ' . $s['batch_no']) ?></td>
                        <td><?= htmlspecialchars($s['level_name']) ?></td>
                        <td><?= $summary !== null ? $summary['completed_count'] . ' / ' . $summary['conducted_count'] : '-' ?></td>
                        <td><?= $summary !== null ? $summary['percent'] . '%' : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</body>
</html>
