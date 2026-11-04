<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/date_helpers.php';
require_once __DIR__ . '/../includes/student_helpers.php';
require_once __DIR__ . '/../includes/input.php';

$adminId = requireAdminLogin();
requireFeature($adminId, 3);

$mysqli = getDbConnection();

// Three views: approved students with attendance (default), applicants whose registration was
// rejected (no student_no/level/attendance, so they get their own columns), or ยืนยันการรับหนังสือ
// (book_confirmations per ห้องเรียน; shares the approved view's filters and student dropdown).
$view = in_array($_GET['view'] ?? '', ['rejected', 'books'], true) ? $_GET['view'] : 'approved';

// Optional filters (0 = all): รุ่น for every view, ระดับชั้น (current level) for approved and books.
$batchOptions = $mysqli->query('SELECT id, batch_no, name AS batch_name FROM batches ORDER BY batch_no DESC')
    ->fetch_all(MYSQLI_ASSOC);
$levelOptions = $mysqli->query('SELECT id, name FROM class_levels ORDER BY sort_order')->fetch_all(MYSQLI_ASSOC);
$filterBatchId = (int) ($_GET['batch_id'] ?? 0);
$filterLevelId = $view !== 'rejected' ? (int) ($_GET['level_id'] ?? 0) : 0;
if (!in_array($filterBatchId, array_map('intval', array_column($batchOptions, 'id')), true)) {
    $filterBatchId = 0;
}
if (!in_array($filterLevelId, array_map('intval', array_column($levelOptions, 'id')), true)) {
    $filterLevelId = 0;
}

// Optional ห้องเรียน filter (approved and books views): one class instance, any class incl. finished/closed ones,
// listed only for the chosen รุ่น when that filter is set.
$classOptions = [];
$filterClass = null;
if ($view !== 'rejected') {
    $stmt = $mysqli->prepare(
        'SELECT ci.id, ci.batch_id, ci.is_closed, cl.name AS level_name, cl.sort_order, b.batch_no, b.name AS batch_name
         FROM class_instances ci
         JOIN class_levels cl ON cl.id = ci.class_level_id
         JOIN batches b ON b.id = ci.batch_id
         WHERE (? = 0 OR ci.batch_id = ?)
         ORDER BY b.batch_no DESC, cl.sort_order'
    );
    $stmt->bind_param('ii', $filterBatchId, $filterBatchId);
    $stmt->execute();
    $classOptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $requestedClassId = (int) ($_GET['class_instance_id'] ?? 0);
    foreach ($classOptions as $option) {
        if ((int) $option['id'] === $requestedClassId) {
            $filterClass = $option;
            break;
        }
    }
}
$filterClassId = $filterClass ? (int) $filterClass['id'] : 0;
// A chosen ห้องเรียน lists every approved student of its batch who has reached its level (including
// those promoted since), so it overrides the รุ่น filter and ignores the ระดับชั้น (current level) one.
$minLevelSort = 0;
if ($filterClass) {
    $filterBatchId = (int) $filterClass['batch_id'];
    $filterLevelId = 0;
    $minLevelSort = (int) $filterClass['sort_order'];
}

// Query-string suffix that carries the active filters through sort, export and view links.
function filterQuery(int $batchId, int $levelId, int $classInstanceId = 0): string
{
    return ($batchId > 0 ? '&batch_id=' . $batchId : '')
        . ($levelId > 0 ? '&level_id=' . $levelId : '')
        . ($classInstanceId > 0 ? '&class_instance_id=' . $classInstanceId : '');
}

if ($view !== 'rejected') {
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
    $defaultSort = 'student_no';
    $defaultDir = 'asc';
} else {
    // All columns of the rejected table; only the keys in $rejectedSortable are clickable.
    $sortColumns = [
        'prefix' => 'คำนำหน้า',
        'full_name' => 'ชื่อ-นามสกุล',
        'batch' => 'รุ่น',
        'age' => 'อายุ',
        'phone' => 'เบอร์โทรศัพท์',
        'line_id' => 'Line ID',
        'line_name' => 'ชื่อไลน์',
        'reference_person' => 'ผู้แนะนำ',
        'country' => 'ประเทศ',
        'province' => 'จังหวัด',
        'district' => 'อำเภอ/เขต',
        'sub_district' => 'ตำบล/แขวง',
        'postal_code' => 'รหัสไปรษณีย์',
        'heard_from' => 'ทราบข่าวจาก',
        'student_type' => 'นักศึกษาเก่า/ใหม่',
        'previous_student_no' => 'รหัสนักศึกษาเดิม',
        'study_reason' => 'เหตุผลที่มาเรียน',
        'zoom_skill' => 'ใช้ ZOOM',
        'joined_classroom' => 'เข้าห้องเรียน',
        'created_at' => 'วันที่สมัคร',
    ];
    $rejectedSortable = ['prefix', 'full_name', 'batch', 'created_at'];
    $defaultSort = 'created_at';
    $defaultDir = 'desc';
}

$sortable = $view !== 'rejected' ? array_keys($sortColumns) : $rejectedSortable;
$sort = in_array($_GET['sort'] ?? '', $sortable, true) ? $_GET['sort'] : $defaultSort;
$dir = ($_GET['dir'] ?? $defaultDir) === 'desc' ? 'desc' : 'asc';

// Sort by the displayed prefix (the free-text one when "อื่นๆ"), matching studentPrefix().
$prefixOrder = "CASE WHEN s.prefix = 'อื่นๆ' THEN s.prefix_other ELSE s.prefix END $dir";

$students = [];
$rejected = [];

if ($view !== 'rejected') {
    // Student IDs are numeric strings of varying length, so order by length first (7201 < 10101).
    $studentNoOrder = "s.student_no IS NULL, LENGTH(s.student_no) $dir, s.student_no $dir";
    // Stored columns sort in SQL (MySQL collation handles Thai names); computed attendance columns are
    // sorted in PHP below, on top of the default student ID order.
    $orderBy = match ($sort) {
        'prefix' => "$prefixOrder, " . $studentNoOrder,
        'full_name' => "s.full_name $dir, " . $studentNoOrder,
        'batch' => "b.batch_no $dir, " . $studentNoOrder,
        'level' => "cl.sort_order $dir, " . $studentNoOrder,
        'student_no' => $studentNoOrder,
        default => 's.student_no IS NULL, LENGTH(s.student_no), s.student_no',
    };

    $stmt = $mysqli->prepare(
        "SELECT s.id, s.student_no, s.prefix, s.prefix_other, s.full_name, s.batch_id, cl.name AS level_name,
                cl.sort_order AS level_sort, b.batch_no, b.name AS batch_name
         FROM students s
         JOIN class_levels cl ON cl.id = s.current_class_level_id
         JOIN batches b ON b.id = s.batch_id
         WHERE s.status = 'approved'
           AND (? = 0 OR s.batch_id = ?)
           AND (? = 0 OR s.current_class_level_id = ?)
           AND (? = 0 OR cl.sort_order >= ?)
         ORDER BY $orderBy"
    );
    $stmt->bind_param(
        'iiiiii',
        $filterBatchId, $filterBatchId, $filterLevelId, $filterLevelId, $minLevelSort, $minLevelSort
    );
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $orderBy = match ($sort) {
        'prefix' => "$prefixOrder, s.created_at DESC",
        'full_name' => "s.full_name $dir, s.created_at DESC",
        'batch' => "b.batch_no $dir, s.created_at DESC",
        default => "s.created_at $dir, s.id $dir",
    };

    // No class-level join: rejected students never get a level assigned.
    $stmt = $mysqli->prepare(
        "SELECT s.*, b.batch_no, b.name AS batch_name
         FROM students s
         JOIN batches b ON b.id = s.batch_id
         WHERE s.status = 'rejected'
           AND (? = 0 OR s.batch_id = ?)
         ORDER BY $orderBy"
    );
    $stmt->bind_param('ii', $filterBatchId, $filterBatchId);
    $stmt->execute();
    $rejected = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$selectedStudentId = $view !== 'rejected' ? (int) ($_GET['student_id'] ?? 0) : 0;
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

    if ($selectedStudent && $view === 'approved') {
        $classInstanceId = studentClassInstanceId($mysqli, $selectedStudentId);
        if ($classInstanceId !== null) {
            $singleReport = getAttendanceSummary($mysqli, $classInstanceId, $selectedStudentId);
        }
    }
}

// Matrix view (a ห้องเรียน chosen, no student selected): the class's non-cancelled sessions as
// columns. A session counts as conducted once its date has passed, matching getAttendanceSummary().
$matrixSessions = [];
$today = date('Y-m-d');
if ($view === 'approved' && $filterClass && $selectedStudentId === 0) {
    $stmt = $mysqli->prepare(
        'SELECT id, session_number, session_date FROM sessions
         WHERE class_instance_id = ? AND is_cancelled = 0 ORDER BY session_number'
    );
    $stmt->bind_param('i', $filterClassId);
    $stmt->execute();
    $matrixSessions = addDisplayNumbers($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
}

$allReports = [];
if ($view === 'approved' && $selectedStudentId === 0) {
    foreach ($students as $student) {
        // With a ห้องเรียน chosen every row reports on that class; otherwise on the student's current one.
        $classInstanceId = $filterClass ? $filterClassId : studentClassInstanceId($mysqli, (int) $student['id']);
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

// ยืนยันการรับหนังสือ view. $bookSummary: one row per ห้องเรียน that is open for confirmation or has
// any confirmation, narrowed by รุ่น / ระดับชั้น (the class's level). Its student count is the class's
// current students plus anyone who confirmed there and has been promoted since.
// $bookRows: the confirmations themselves (for a chosen ห้องเรียน, or every listed one), also the CSV.
// $studentBookRows: a chosen student's classes in that set, confirmed or not.
$bookSummary = [];
$bookRows = [];
$studentBookRows = [];
if ($view === 'books') {
    $stmt = $mysqli->prepare(
        "SELECT ci.id, ci.batch_id, ci.book_confirmation_open, cl.name AS level_name, b.batch_no, b.name AS batch_name,
                (SELECT COUNT(*) FROM book_confirmations bc WHERE bc.class_instance_id = ci.id) AS confirmed_count,
                (SELECT COUNT(*) FROM students s
                  WHERE s.status = 'approved' AND s.batch_id = ci.batch_id
                    AND (s.current_class_level_id = ci.class_level_id
                         OR EXISTS (SELECT 1 FROM book_confirmations bc
                                    WHERE bc.class_instance_id = ci.id AND bc.student_id = s.id))) AS student_count
         FROM class_instances ci
         JOIN class_levels cl ON cl.id = ci.class_level_id
         JOIN batches b ON b.id = ci.batch_id
         WHERE (ci.book_confirmation_open = 1
                OR EXISTS (SELECT 1 FROM book_confirmations bc WHERE bc.class_instance_id = ci.id))
           AND (? = 0 OR ci.id = ?)
           AND (? = 0 OR ci.batch_id = ?)
           AND (? = 0 OR ci.class_level_id = ?)
         ORDER BY b.batch_no DESC, cl.sort_order"
    );
    $stmt->bind_param('iiiiii', $filterClassId, $filterClassId, $filterBatchId, $filterBatchId, $filterLevelId, $filterLevelId);
    $stmt->execute();
    $bookSummary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($selectedStudent) {
        $stmt = $mysqli->prepare(
            "SELECT ci.id AS class_instance_id, ci.book_confirmation_open, cl.name AS class_level_name,
                    b.batch_no, b.name AS batch_name, bc.id AS confirmation_id, bc.recipient_name, bc.phone,
                    bc.address, bc.country, bc.province, bc.district, bc.sub_district, bc.postal_code,
                    bc.same_as_registration, bc.confirmed_at
             FROM class_instances ci
             JOIN class_levels cl ON cl.id = ci.class_level_id
             JOIN batches b ON b.id = ci.batch_id
             LEFT JOIN book_confirmations bc ON bc.class_instance_id = ci.id AND bc.student_id = ?
             WHERE ci.batch_id = ? AND cl.sort_order <= ?
               AND (ci.book_confirmation_open = 1 OR bc.id IS NOT NULL)
             ORDER BY cl.sort_order"
        );
        $levelSort = (int) $selectedStudent['level_sort'];
        $studentBatchId = (int) $selectedStudent['batch_id'];
        $stmt->bind_param('iii', $selectedStudentId, $studentBatchId, $levelSort);
        $stmt->execute();
        $studentBookRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } elseif ($selectedStudentId === 0) {
        $stmt = $mysqli->prepare(
            "SELECT bc.*, s.student_no, s.prefix, s.prefix_other, s.full_name, cl.name AS class_level_name,
                    b.batch_no, b.name AS batch_name
             FROM book_confirmations bc
             JOIN students s ON s.id = bc.student_id
             JOIN class_instances ci ON ci.id = bc.class_instance_id
             JOIN class_levels cl ON cl.id = ci.class_level_id
             JOIN batches b ON b.id = ci.batch_id
             WHERE (? = 0 OR ci.id = ?)
               AND (? = 0 OR ci.batch_id = ?)
               AND (? = 0 OR ci.class_level_id = ?)
             ORDER BY b.batch_no DESC, cl.sort_order, LENGTH(s.student_no), s.student_no"
        );
        $stmt->bind_param('iiiiii', $filterClassId, $filterClassId, $filterBatchId, $filterBatchId, $filterLevelId, $filterLevelId);
        $stmt->execute();
        $bookRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Shipping CSV / detail-table columns for a confirmation row (blank parts shown as '-').
const BOOK_COLUMNS = [
    'รุ่น', 'ห้องเรียน', 'รหัสนักศึกษา', 'คำนำหน้า', 'ชื่อ-นามสกุล (ที่ลงทะเบียน)', 'ชื่อ-นามสกุลผู้รับ',
    'เบอร์มือถือผู้รับ', 'ที่อยู่', 'ตำบล/แขวง', 'อำเภอ/เขต', 'จังหวัด', 'รหัสไปรษณีย์', 'ประเทศ',
    'ใช้ที่อยู่ที่ลงทะเบียน', 'วันที่ยืนยัน',
];

function bookRowValues(array $row, array $student): array
{
    return [
        batchLabel($row),
        $row['class_level_name'],
        $student['student_no'] ?? '-',
        studentPrefix($student),
        $student['full_name'],
        $row['recipient_name'],
        $row['phone'],
        $row['address'],
        $row['sub_district'] ?: '-',
        $row['district'] ?: '-',
        $row['province'] ?: '-',
        $row['postal_code'] ?: '-',
        $row['country'] ?: '-',
        $row['same_as_registration'] ? 'ใช่' : 'ไม่ใช่',
        substr((string) $row['confirmed_at'], 0, 10),
    ];
}

function sortHeader(string $key, string $label, string $sort, string $dir, string $view, string $filters): string
{
    $isActive = $key === $sort;
    $nextDir = $isActive && $dir === 'asc' ? 'desc' : 'asc';
    $indicator = $isActive
        ? '<span class="sort-indicator">' . ($dir === 'asc' ? '▲' : '▼') . '</span>'
        : '<span class="sort-indicator inactive">↕</span>';

    $href = 'reports.php?view=' . $view . '&sort=' . urlencode($key) . '&dir=' . $nextDir . $filters;

    return '<a class="sort-link" href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . $indicator . '</a>';
}

function batchLabel(array $row): string
{
    return $row['batch_name'] ?: 'รุ่น ' . $row['batch_no'];
}

/**
 * One matrix cell: $checked if the student checked in to $session, $missed if it has been held
 * (dated today or earlier) without a check-in, $future if it hasn't been held yet.
 */
function matrixCell(array $summary, array $session, string $today, string $checked, string $missed, string $future): string
{
    foreach ($summary['sessions'] as $s) {
        if ((int) $s['id'] === (int) $session['id'] && $s['checked_in']) {
            return $checked;
        }
    }

    return $session['session_date'] <= $today ? $missed : $future;
}

if ($exportCsv) {
    if ($view === 'rejected') {
        $filename = 'rejected_applicants.csv';
    } elseif ($view === 'books') {
        $filename = $selectedStudent
            ? 'book_shipping_' . preg_replace('/[^A-Za-z0-9]+/', '_', $selectedStudent['student_no'] ?? (string) $selectedStudentId) . '.csv'
            : ($filterClass ? 'book_shipping_class_' . $filterClassId . '.csv' : 'book_shipping.csv');
    } else {
        $filename = $selectedStudent
            ? 'attendance_' . preg_replace('/[^A-Za-z0-9]+/', '_', $selectedStudent['student_no'] ?? (string) $selectedStudentId) . '.csv'
            : ($filterClass ? 'attendance_class_' . $filterClassId . '.csv' : 'attendance_all_students.csv');
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders Thai text correctly

    if ($view === 'books') {
        // Shipping list for the shipping staff: confirmed rows only.
        fputcsv($out, BOOK_COLUMNS);
        if ($selectedStudent) {
            foreach ($studentBookRows as $row) {
                if ($row['confirmation_id'] !== null) {
                    fputcsv($out, csvSafe(bookRowValues($row, $selectedStudent)));
                }
            }
        } else {
            foreach ($bookRows as $row) {
                fputcsv($out, csvSafe(bookRowValues($row, $row)));
            }
        }
    } elseif ($view === 'rejected') {
        fputcsv($out, array_values($sortColumns));
        foreach ($rejected as $r) {
            fputcsv($out, csvSafe([
                studentPrefix($r),
                $r['full_name'],
                batchLabel($r),
                $r['age'] ?? '-',
                $r['phone'] ?: '-',
                $r['line_id'] ?: '-',
                $r['line_name'] ?: '-',
                $r['reference_person'] ?: '-',
                $r['country'] ?: '-',
                $r['province'] ?: '-',
                $r['district'] ?: '-',
                $r['sub_district'] ?: '-',
                $r['postal_code'] ?: '-',
                studentChoice($r, 'heard_from'),
                studentChoice($r, 'student_type'),
                $r['previous_student_no'] ?: '-',
                $r['study_reason'] ?: '-',
                studentChoice($r, 'zoom_skill'),
                studentChoice($r, 'joined_classroom'),
                substr((string) $r['created_at'], 0, 10),
            ]));
        }
    } elseif ($selectedStudent) {
        fputcsv($out, ['รหัสนักศึกษา', 'คำนำหน้า', 'ชื่อ-นามสกุล', 'รุ่น', 'ระดับชั้น']);
        fputcsv($out, csvSafe([
            $selectedStudent['student_no'] ?? '-',
            studentPrefix($selectedStudent),
            $selectedStudent['full_name'],
            batchLabel($selectedStudent),
            $selectedStudent['level_name'],
        ]));
        fputcsv($out, []);
        fputcsv($out, ['ครั้งที่', 'วันที่เรียน', 'ลงชื่อเข้าเรียน']);
        foreach (($singleReport['sessions'] ?? []) as $session) {
            fputcsv($out, csvSafe([
                $session['display_number'],
                $session['session_date'],
                $session['checked_in'] ? 'ใช่' : 'ไม่ใช่',
            ]));
        }
    } elseif ($filterClass) {
        // Per-session detail for one ห้องเรียน: ✓ checked in, - missed, blank = not held yet.
        fputcsv($out, csvSafe(['รุ่น', batchLabel($filterClass), 'ระดับชั้น', $filterClass['level_name']]));
        fputcsv($out, []);
        $header = ['รหัสนักศึกษา', 'คำนำหน้า', 'ชื่อ-นามสกุล', 'ระดับชั้นปัจจุบัน'];
        foreach ($matrixSessions as $session) {
            $header[] = 'ครั้งที่ ' . $session['display_number'] . ' (' . $session['session_date'] . ')';
        }
        fputcsv($out, array_merge($header, ['เข้าเรียน (ครั้ง)', 'จัดสอนแล้ว (ครั้ง)', 'ร้อยละ']));
        foreach ($allReports as $row) {
            $s = $row['student'];
            $summary = $row['summary'];
            $line = [$s['student_no'] ?? '-', studentPrefix($s), $s['full_name'], $s['level_name']];
            foreach ($matrixSessions as $session) {
                $line[] = matrixCell($summary, $session, $today, '✓', '-', '');
            }
            fputcsv($out, csvSafe(array_merge($line, [
                $summary['completed_count'],
                $summary['conducted_count'],
                $summary['percent'] . '%',
            ])));
        }
    } else {
        fputcsv($out, ['รหัสนักศึกษา', 'คำนำหน้า', 'ชื่อ-นามสกุล', 'รุ่น', 'ระดับชั้น', 'เข้าเรียน (ครั้ง)', 'จัดสอนแล้ว (ครั้ง)', 'ร้อยละ']);
        foreach ($allReports as $row) {
            $s = $row['student'];
            $summary = $row['summary'];
            fputcsv($out, csvSafe([
                $s['student_no'] ?? '-',
                studentPrefix($s),
                $s['full_name'],
                batchLabel($s),
                $s['level_name'],
                $summary['completed_count'] ?? '-',
                $summary['conducted_count'] ?? '-',
                $summary !== null ? $summary['percent'] . '%' : '-',
            ]));
        }
    }

    fclose($out);
    exit;
}

$filters = filterQuery($filterBatchId, $filterLevelId, $filterClassId);
// The student list with the current filters and sort, without a selected student.
$listHref = 'reports.php?view=' . $view . '&sort=' . urlencode($sort) . '&dir=' . $dir . $filters;
$exportHref = 'reports.php?export=csv&view=' . $view . '&sort=' . urlencode($sort) . '&dir=' . $dir
    . ($selectedStudentId > 0 ? '&student_id=' . $selectedStudentId : '') . $filters;
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
<body class="admin">
    <h1>รายงานการเข้าเรียน</h1>
    <nav>
        <a href="dashboard.php">กลับหน้าแผงควบคุม</a>
        <form class="nav-logout" action="logout.php" method="post"><?= csrfField() ?><button type="submit">ออกจากระบบ</button></form>
    </nav>

    <div class="view-toggle">
        <a href="reports.php?view=approved<?= filterQuery($filterBatchId, 0) ?>" class="<?= $view === 'approved' ? 'active' : '' ?>">นักศึกษาที่อนุมัติแล้ว</a>
        <a href="reports.php?view=rejected<?= filterQuery($filterBatchId, 0) ?>" class="<?= $view === 'rejected' ? 'active' : '' ?>">ผู้สมัครที่ไม่ได้รับการอนุมัติ</a>
        <a href="reports.php?view=books<?= filterQuery($filterBatchId, 0) ?>" class="<?= $view === 'books' ? 'active' : '' ?>">ยืนยันการรับหนังสือ</a>
    </div>

    <form action="reports.php" method="get">
        <input type="hidden" name="view" value="<?= $view ?>">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
        <input type="hidden" name="dir" value="<?= $dir ?>">

        <label for="batch_id">รุ่น</label>
        <select id="batch_id" name="batch_id" onchange="resetStudentAndSubmit(this.form)">
            <option value="0">-- ทุกรุ่น --</option>
            <?php foreach ($batchOptions as $batch): ?>
                <option value="<?= (int) $batch['id'] ?>" <?= $filterBatchId === (int) $batch['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars(batchLabel($batch)) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($view !== 'rejected'): ?>
            <label for="level_id">ระดับชั้น</label>
            <select id="level_id" name="level_id" onchange="resetStudentAndSubmit(this.form)">
                <option value="0">-- ทุกระดับชั้น --</option>
                <?php foreach ($levelOptions as $level): ?>
                    <option value="<?= (int) $level['id'] ?>" <?= $filterLevelId === (int) $level['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($level['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="class_instance_id"><?= $view === 'books' ? 'ห้องเรียน (รายชื่อผู้ยืนยันการรับหนังสือ)' : 'ห้องเรียน (รายละเอียดการเข้าเรียนรายครั้ง)' ?></label>
            <select id="class_instance_id" name="class_instance_id" onchange="resetStudentAndSubmit(this.form, false)">
                <option value="0">-- ไม่เลือก (<?= $view === 'books' ? 'ดูสรุปทุกห้องเรียน' : 'ดูสรุปตามชั้นเรียนปัจจุบัน' ?>) --</option>
                <?php foreach ($classOptions as $option): ?>
                    <option value="<?= (int) $option['id'] ?>" <?= $filterClassId === (int) $option['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(batchLabel($option) . ' - ' . $option['level_name'] . ($option['is_closed'] ? ' (ปิดแล้ว)' : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="student_id">นักศึกษา</label>
            <select id="student_id" name="student_id" onchange="this.form.submit()">
                <option value="0" <?= $selectedStudentId === 0 ? 'selected' : '' ?>>-- นักศึกษาทั้งหมด --</option>
                <?php foreach ($students as $student): ?>
                    <option value="<?= $student['id'] ?>" <?= $selectedStudentId === (int) $student['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(($student['student_no'] ?? '-') . ' - ' . studentPrefix($student) . ' ' . $student['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="submit">ดูรายงาน</button>
    </form>

    <script>
        // A changed filter may exclude the selected student, so go back to "all students". Changing
        // รุ่น or ระดับชั้น also clears the ห้องเรียน, which would otherwise override them.
        function resetStudentAndSubmit(form, clearClass = true) {
            if (form.student_id) {
                form.student_id.value = '0';
            }
            if (clearClass && form.class_instance_id) {
                form.class_instance_id.value = '0';
            }
            form.submit();
        }
    </script>

    <p><a href="<?= htmlspecialchars($exportHref) ?>">ส่งออกรายงานนี้เป็นไฟล์ CSV</a></p>

    <?php if ($view === 'books'): ?>
        <?php if ($selectedStudentId > 0): ?>
            <p><a href="<?= htmlspecialchars($listHref) ?>">กลับไปสรุปการยืนยันการรับหนังสือ</a></p>
            <?php if (!$selectedStudent): ?>
                <p class="error">ไม่พบนักศึกษา</p>
            <?php else: ?>
                <h2><?= htmlspecialchars(($selectedStudent['student_no'] ?? '-') . ' ' . studentPrefix($selectedStudent) . ' ' . $selectedStudent['full_name']) ?></h2>
                <?php if ($studentBookRows === []): ?>
                    <p>ไม่มีห้องเรียนที่เปิดหรือเคยเปิดให้ยืนยันการรับหนังสือสำหรับนักศึกษาคนนี้</p>
                <?php else: ?>
                    <div class="table-wrap"><table>
                        <thead>
                            <tr><th>ห้องเรียน</th><th>สถานะ</th><th>ชื่อ-นามสกุลผู้รับ</th><th>เบอร์มือถือผู้รับ</th><th>ที่อยู่จัดส่ง</th><th>วันที่ยืนยัน</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($studentBookRows as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars(batchLabel($row) . ' - ' . $row['class_level_name']) ?></td>
                                    <td><?= $row['book_confirmation_open'] ? 'เปิด' : 'ปิด' ?></td>
                                    <?php if ($row['confirmation_id'] === null): ?>
                                        <td colspan="4">ยังไม่ยืนยัน</td>
                                    <?php else: ?>
                                        <td><?= htmlspecialchars($row['recipient_name']) ?></td>
                                        <td><?= htmlspecialchars($row['phone']) ?></td>
                                        <td><?= implode('<br>', array_map('htmlspecialchars', formatAddressLines($row))) ?></td>
                                        <td><?= htmlspecialchars(formatDateBEShort(substr((string) $row['confirmed_at'], 0, 10))) ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($bookSummary === []): ?>
                <p>ยังไม่มีห้องเรียนที่เปิดให้ยืนยันการรับหนังสือ (เปิดได้ที่หน้าจัดการห้องเรียน)</p>
            <?php else: ?>
                <div class="table-wrap"><table>
                    <thead>
                        <tr><th>รุ่น</th><th>ห้องเรียน</th><th>สถานะ</th><th>ยืนยันแล้ว</th><th>ยังไม่ยืนยัน</th><th>นักศึกษาในห้อง</th><th>%</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookSummary as $row):
                            $confirmed = (int) $row['confirmed_count'];
                            $total = (int) $row['student_count']; ?>
                            <tr>
                                <td><?= htmlspecialchars(batchLabel($row)) ?></td>
                                <td><a href="<?= htmlspecialchars('reports.php?view=books' . filterQuery((int) $row['batch_id'], 0, (int) $row['id'])) ?>"><?= htmlspecialchars($row['level_name']) ?></a></td>
                                <td><?= $row['book_confirmation_open'] ? 'เปิด' : 'ปิด' ?></td>
                                <td><?= $confirmed ?></td>
                                <td><?= max(0, $total - $confirmed) ?></td>
                                <td><?= $total ?></td>
                                <td><?= $total > 0 ? round($confirmed * 100 / $total) : 0 ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>

            <?php if ($filterClass): ?>
                <h2>ผู้ยืนยันการรับหนังสือ: <?= htmlspecialchars(batchLabel($filterClass) . ' - ' . $filterClass['level_name']) ?></h2>
                <?php if ($bookRows === []): ?>
                    <p>ยังไม่มีผู้ยืนยันการรับหนังสือในห้องเรียนนี้</p>
                <?php else: ?>
                    <div class="table-wrap"><table>
                        <thead>
                            <tr>
                                <?php foreach (array_slice(BOOK_COLUMNS, 2) as $label): ?>
                                    <th><?= htmlspecialchars($label) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookRows as $row): $values = bookRowValues($row, $row); ?>
                                <tr>
                                    <td><?= htmlspecialchars($values[2]) ?></td>
                                    <td><?= htmlspecialchars($values[3]) ?></td>
                                    <td><a href="<?= htmlspecialchars($listHref . '&student_id=' . (int) $row['student_id']) ?>"><?= htmlspecialchars($values[4]) ?></a></td>
                                    <?php foreach (array_slice($values, 5, 9) as $value): ?>
                                        <td><?= nl2br(htmlspecialchars((string) $value)) ?></td>
                                    <?php endforeach; ?>
                                    <td><?= htmlspecialchars(formatDateBEShort($values[14])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            <?php else: ?>
                <p class="form-note">คลิกชื่อห้องเรียนเพื่อดูรายชื่อผู้ยืนยัน · ไฟล์ CSV มีที่อยู่จัดส่งของทุกห้องเรียนในตารางนี้</p>
            <?php endif; ?>
        <?php endif; ?>
    <?php elseif ($view === 'rejected'): ?>
        <?php if ($rejected === []): ?>
            <p>ไม่มีผู้สมัครที่ไม่ได้รับการอนุมัติ</p>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead>
                    <tr>
                        <?php foreach ($sortColumns as $key => $label): ?>
                            <th><?= in_array($key, $rejectedSortable, true) ? sortHeader($key, $label, $sort, $dir, $view, $filters) : htmlspecialchars($label) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rejected as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars(studentPrefix($r)) ?></td>
                            <td><?= htmlspecialchars($r['full_name']) ?></td>
                            <td><?= htmlspecialchars(batchLabel($r)) ?></td>
                            <td><?= htmlspecialchars((string) ($r['age'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars($r['phone'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($r['line_id'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($r['line_name'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($r['reference_person'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($r['country'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($r['province'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($r['district'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($r['sub_district'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($r['postal_code'] ?: '-') ?></td>
                            <td><?= htmlspecialchars(studentChoice($r, 'heard_from')) ?></td>
                            <td><?= htmlspecialchars(studentChoice($r, 'student_type')) ?></td>
                            <td><?= htmlspecialchars($r['previous_student_no'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($r['study_reason'] ?: '-') ?></td>
                            <td><?= htmlspecialchars(studentChoice($r, 'zoom_skill')) ?></td>
                            <td><?= htmlspecialchars(studentChoice($r, 'joined_classroom')) ?></td>
                            <td><?= htmlspecialchars(formatDateBEShort(substr((string) $r['created_at'], 0, 10))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    <?php elseif ($selectedStudentId > 0): ?>
        <?php if (!$selectedStudent): ?>
            <p class="error">ไม่พบนักศึกษา</p>
        <?php elseif (!$singleReport): ?>
            <p class="error">ยังไม่มีตารางเรียนสำหรับระดับชั้นปัจจุบันของนักศึกษาคนนี้</p>
        <?php endif; ?>
        <p><a href="<?= htmlspecialchars($listHref) ?>">กลับไปรายชื่อนักศึกษา</a></p>
        <?php if ($selectedStudent && $singleReport): ?>
            <div class="card">
                <p>
                    <strong><?= htmlspecialchars(($selectedStudent['student_no'] ?? '-') . ' ' . studentPrefix($selectedStudent) . ' ' . $selectedStudent['full_name']) ?></strong>
                    — <?= htmlspecialchars(batchLabel($selectedStudent)) ?>
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
                            <?= $session['display_number'] ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php elseif ($filterClass): ?>
        <h2><?= htmlspecialchars(batchLabel($filterClass) . ' - ' . $filterClass['level_name']) ?><?= $filterClass['is_closed'] ? ' (ปิดแล้ว)' : '' ?></h2>
        <?php if ($matrixSessions === []): ?>
            <p class="notice">ห้องเรียนนี้ยังไม่มีตารางเรียน</p>
        <?php elseif ($allReports === []): ?>
            <p>ไม่มีนักศึกษาในห้องเรียนนี้</p>
        <?php else: ?>
            <p class="form-note">สีเขียว = ลงชื่อเข้าเรียน · สีเทา = ไม่ได้ลงชื่อ · ช่องว่าง = ยังไม่ถึงวันเรียน · คลิกชื่อเพื่อดูรายบุคคล</p>
            <div class="table-wrap"><table class="attendance-matrix">
                <thead>
                    <tr>
                        <?php foreach (['student_no', 'prefix', 'full_name', 'level'] as $key): ?>
                            <th><?= sortHeader($key, $sortColumns[$key], $sort, $dir, $view, $filters) ?></th>
                        <?php endforeach; ?>
                        <?php foreach ($matrixSessions as $session): $dateBE = formatDateBEShort($session['session_date']); ?>
                            <th class="matrix-session-head" title="<?= htmlspecialchars($dateBE) ?>">
                                <?= (int) $session['display_number'] ?><br><small><?= htmlspecialchars($dateBE) ?></small>
                            </th>
                        <?php endforeach; ?>
                        <th><?= sortHeader('completed', $sortColumns['completed'], $sort, $dir, $view, $filters) ?></th>
                        <th><?= sortHeader('percent', $sortColumns['percent'], $sort, $dir, $view, $filters) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allReports as $row): $s = $row['student']; $summary = $row['summary']; ?>
                        <tr>
                            <td><?= htmlspecialchars($s['student_no'] ?? '-') ?></td>
                            <td><?= htmlspecialchars(studentPrefix($s)) ?></td>
                            <td><a href="<?= htmlspecialchars($listHref . '&student_id=' . (int) $s['id']) ?>"><?= htmlspecialchars($s['full_name']) ?></a></td>
                            <td><?= htmlspecialchars($s['level_name']) ?></td>
                            <?php foreach ($matrixSessions as $session): $cell = matrixCell($summary, $session, $today, 'checked', 'missing', ''); ?>
                                <td class="matrix-cell">
                                    <?php if ($cell !== ''): ?>
                                        <span class="session-box mini <?= $cell ?>" title="<?= htmlspecialchars('ครั้งที่ ' . $session['display_number'] . ' ' . formatDateBEShort($session['session_date'])) ?>"></span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td><?= $summary['completed_count'] . ' / ' . $summary['conducted_count'] ?></td>
                            <td><?= $summary['percent'] ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <?php foreach ($sortColumns as $key => $label): ?>
                        <th><?= sortHeader($key, $label, $sort, $dir, $view, $filters) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allReports as $row): $s = $row['student']; $summary = $row['summary']; ?>
                    <tr>
                        <td><?= htmlspecialchars($s['student_no'] ?? '-') ?></td>
                        <td><?= htmlspecialchars(studentPrefix($s)) ?></td>
                        <td><a href="<?= htmlspecialchars($listHref . '&student_id=' . (int) $s['id']) ?>"><?= htmlspecialchars($s['full_name']) ?></a></td>
                        <td><?= htmlspecialchars(batchLabel($s)) ?></td>
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
