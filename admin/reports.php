<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/date_helpers.php';
require_once __DIR__ . '/../includes/student_helpers.php';

$adminId = requireAdminLogin();
requireFeature($adminId, 3);

$mysqli = getDbConnection();

// Two views: approved students with attendance (default), or applicants whose registration was
// rejected. Rejected rows have no student_no/level/attendance, so they get their own columns.
$view = ($_GET['view'] ?? '') === 'rejected' ? 'rejected' : 'approved';

// Optional filters (0 = all): รุ่น for both views, ระดับชั้น (current level) for approved only.
$batchOptions = $mysqli->query('SELECT id, batch_no, name AS batch_name FROM batches ORDER BY batch_no DESC')
    ->fetch_all(MYSQLI_ASSOC);
$levelOptions = $mysqli->query('SELECT id, name FROM class_levels ORDER BY sort_order')->fetch_all(MYSQLI_ASSOC);
$filterBatchId = (int) ($_GET['batch_id'] ?? 0);
$filterLevelId = $view === 'approved' ? (int) ($_GET['level_id'] ?? 0) : 0;
if (!in_array($filterBatchId, array_map('intval', array_column($batchOptions, 'id')), true)) {
    $filterBatchId = 0;
}
if (!in_array($filterLevelId, array_map('intval', array_column($levelOptions, 'id')), true)) {
    $filterLevelId = 0;
}

// Query-string suffix that carries the active filters through sort, export and view links.
function filterQuery(int $batchId, int $levelId): string
{
    return ($batchId > 0 ? '&batch_id=' . $batchId : '') . ($levelId > 0 ? '&level_id=' . $levelId : '');
}

if ($view === 'approved') {
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
        'province' => 'จังหวัด',
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

$sortable = $view === 'approved' ? array_keys($sortColumns) : $rejectedSortable;
$sort = in_array($_GET['sort'] ?? '', $sortable, true) ? $_GET['sort'] : $defaultSort;
$dir = ($_GET['dir'] ?? $defaultDir) === 'desc' ? 'desc' : 'asc';

// Sort by the displayed prefix (the free-text one when "อื่นๆ"), matching studentPrefix().
$prefixOrder = "CASE WHEN s.prefix = 'อื่นๆ' THEN s.prefix_other ELSE s.prefix END $dir";

$students = [];
$rejected = [];

if ($view === 'approved') {
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
        "SELECT s.id, s.student_no, s.prefix, s.prefix_other, s.full_name, cl.name AS level_name, b.batch_no, b.name AS batch_name
         FROM students s
         JOIN class_levels cl ON cl.id = s.current_class_level_id
         JOIN batches b ON b.id = s.batch_id
         WHERE s.status = 'approved'
           AND (? = 0 OR s.batch_id = ?)
           AND (? = 0 OR s.current_class_level_id = ?)
         ORDER BY $orderBy"
    );
    $stmt->bind_param('iiii', $filterBatchId, $filterBatchId, $filterLevelId, $filterLevelId);
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

$selectedStudentId = $view === 'approved' ? (int) ($_GET['student_id'] ?? 0) : 0;
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
if ($view === 'approved' && $selectedStudentId === 0) {
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

if ($exportCsv) {
    if ($view === 'rejected') {
        $filename = 'rejected_applicants.csv';
    } else {
        $filename = $selectedStudent
            ? 'attendance_' . preg_replace('/[^A-Za-z0-9]+/', '_', $selectedStudent['student_no'] ?? (string) $selectedStudentId) . '.csv'
            : 'attendance_all_students.csv';
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders Thai text correctly

    if ($view === 'rejected') {
        fputcsv($out, array_values($sortColumns));
        foreach ($rejected as $r) {
            fputcsv($out, [
                studentPrefix($r),
                $r['full_name'],
                batchLabel($r),
                $r['age'] ?? '-',
                $r['phone'] ?: '-',
                $r['line_id'] ?: '-',
                $r['line_name'] ?: '-',
                $r['reference_person'] ?: '-',
                $r['province'] ?: '-',
                $r['postal_code'] ?: '-',
                studentChoice($r, 'heard_from'),
                studentChoice($r, 'student_type'),
                $r['previous_student_no'] ?: '-',
                $r['study_reason'] ?: '-',
                studentChoice($r, 'zoom_skill'),
                studentChoice($r, 'joined_classroom'),
                substr((string) $r['created_at'], 0, 10),
            ]);
        }
    } elseif ($selectedStudent) {
        fputcsv($out, ['รหัสนักศึกษา', 'คำนำหน้า', 'ชื่อ-นามสกุล', 'รุ่น', 'ระดับชั้น']);
        fputcsv($out, [
            $selectedStudent['student_no'] ?? '-',
            studentPrefix($selectedStudent),
            $selectedStudent['full_name'],
            batchLabel($selectedStudent),
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
                batchLabel($s),
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

$filters = filterQuery($filterBatchId, $filterLevelId);
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
        <a class="nav-logout" href="logout.php">ออกจากระบบ</a>
    </nav>

    <div class="view-toggle">
        <a href="reports.php?view=approved<?= filterQuery($filterBatchId, 0) ?>" class="<?= $view === 'approved' ? 'active' : '' ?>">นักศึกษาที่อนุมัติแล้ว</a>
        <a href="reports.php?view=rejected<?= filterQuery($filterBatchId, 0) ?>" class="<?= $view === 'rejected' ? 'active' : '' ?>">ผู้สมัครที่ไม่ได้รับการอนุมัติ</a>
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

        <?php if ($view === 'approved'): ?>
            <label for="level_id">ระดับชั้น</label>
            <select id="level_id" name="level_id" onchange="resetStudentAndSubmit(this.form)">
                <option value="0">-- ทุกระดับชั้น --</option>
                <?php foreach ($levelOptions as $level): ?>
                    <option value="<?= (int) $level['id'] ?>" <?= $filterLevelId === (int) $level['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($level['name']) ?>
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
        // A changed filter may exclude the selected student, so go back to "all students".
        function resetStudentAndSubmit(form) {
            if (form.student_id) {
                form.student_id.value = '0';
            }
            form.submit();
        }
    </script>

    <p><a href="<?= htmlspecialchars($exportHref) ?>">ส่งออกรายงานนี้เป็นไฟล์ CSV</a></p>

    <?php if ($view === 'rejected'): ?>
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
                            <td><?= htmlspecialchars($r['province'] ?: '-') ?></td>
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
        <?php else: ?>
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
                        <th><?= sortHeader($key, $label, $sort, $dir, $view, $filters) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allReports as $row): $s = $row['student']; $summary = $row['summary']; ?>
                    <tr>
                        <td><?= htmlspecialchars($s['student_no'] ?? '-') ?></td>
                        <td><?= htmlspecialchars(studentPrefix($s)) ?></td>
                        <td><?= htmlspecialchars($s['full_name']) ?></td>
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
