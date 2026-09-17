<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/date_helpers.php';

$adminId = requireAdminLogin();
requireFeature($adminId, 3);

$mysqli = getDbConnection();

$students = $mysqli->query(
    "SELECT s.id, s.student_no, s.full_name, cl.name AS level_name, b.batch_no, b.name AS batch_name
     FROM students s
     JOIN class_levels cl ON cl.id = s.current_class_level_id
     JOIN batches b ON b.id = s.batch_id
     WHERE s.status = 'approved'
     ORDER BY b.batch_no DESC, cl.sort_order, s.full_name"
)->fetch_all(MYSQLI_ASSOC);

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
        fputcsv($out, ['Student ID', 'Name', 'Batch', 'Level']);
        fputcsv($out, [
            $selectedStudent['student_no'] ?? '-',
            $selectedStudent['full_name'],
            $selectedStudent['batch_name'] ?: 'รุ่น ' . $selectedStudent['batch_no'],
            $selectedStudent['level_name'],
        ]);
        fputcsv($out, []);
        fputcsv($out, ['Session number', 'Session date', 'Checked in']);
        foreach (($singleReport['sessions'] ?? []) as $session) {
            fputcsv($out, [
                $session['session_number'],
                $session['session_date'],
                $session['checked_in'] ? 'Yes' : 'No',
            ]);
        }
    } else {
        fputcsv($out, ['Student ID', 'Name', 'Batch', 'Level', 'Completed', 'Conducted', 'Percent']);
        foreach ($allReports as $row) {
            $s = $row['student'];
            $summary = $row['summary'];
            fputcsv($out, [
                $s['student_no'] ?? '-',
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

$exportHref = 'reports.php?export=csv' . ($selectedStudentId > 0 ? '&student_id=' . $selectedStudentId : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports - Abhidham Registration</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <h1>Attendance Reports</h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="logout.php">Log out</a>
    </nav>

    <form action="reports.php" method="get">
        <label for="student_id">Student</label>
        <select id="student_id" name="student_id" onchange="this.form.submit()">
            <option value="0" <?= $selectedStudentId === 0 ? 'selected' : '' ?>>-- All students --</option>
            <?php foreach ($students as $student): ?>
                <option value="<?= $student['id'] ?>" <?= $selectedStudentId === (int) $student['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars(($student['student_no'] ?? '-') . ' - ' . $student['full_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">View</button>
    </form>

    <p><a href="<?= htmlspecialchars($exportHref) ?>">Export this report to CSV</a></p>

    <?php if ($selectedStudentId > 0): ?>
        <?php if (!$selectedStudent): ?>
            <p class="error">Student not found.</p>
        <?php elseif (!$singleReport): ?>
            <p class="error">No class schedule found yet for this student's current level.</p>
        <?php else: ?>
            <div class="card">
                <p>
                    <strong><?= htmlspecialchars(($selectedStudent['student_no'] ?? '-') . ' ' . $selectedStudent['full_name']) ?></strong>
                    — <?= htmlspecialchars($selectedStudent['batch_name'] ?: 'รุ่น ' . $selectedStudent['batch_no']) ?>
                    / <?= htmlspecialchars($selectedStudent['level_name']) ?>
                </p>
                <p class="progress">
                    Progress: <?= $singleReport['completed_count'] ?> / <?= $singleReport['conducted_count'] ?>
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
        <table>
            <thead>
                <tr><th>Student ID</th><th>Name</th><th>รุ่น</th><th>Level</th><th>Completed</th><th>%</th></tr>
            </thead>
            <tbody>
                <?php foreach ($allReports as $row): $s = $row['student']; $summary = $row['summary']; ?>
                    <tr>
                        <td><?= htmlspecialchars($s['student_no'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($s['full_name']) ?></td>
                        <td><?= htmlspecialchars($s['batch_name'] ?: 'รุ่น ' . $s['batch_no']) ?></td>
                        <td><?= htmlspecialchars($s['level_name']) ?></td>
                        <td><?= $summary !== null ? $summary['completed_count'] . ' / ' . $summary['conducted_count'] : '-' ?></td>
                        <td><?= $summary !== null ? $summary['percent'] . '%' : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>
