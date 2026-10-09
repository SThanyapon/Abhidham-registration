<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

$adminId = requireAdminLogin();
requireFeature($adminId, 4);

$mysqli = getDbConnection();
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $studentIds = array_map('intval', $_POST['student_ids'] ?? []);

    if ($studentIds === []) {
        $error = 'ยังไม่ได้เลือกนักศึกษา';
    } else {
        $promoted = 0;
        $skipped = 0;

        foreach ($studentIds as $studentId) {
            $stmt = $mysqli->prepare(
                'SELECT s.current_class_level_id, cl.sort_order
                 FROM students s
                 JOIN class_levels cl ON cl.id = s.current_class_level_id
                 WHERE s.id = ? AND s.status = "approved"'
            );
            $stmt->bind_param('i', $studentId);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$current) {
                $skipped++;
                continue;
            }

            $nextLevel = $mysqli->prepare('SELECT id FROM class_levels WHERE sort_order = ?');
            $nextSortOrder = (int) $current['sort_order'] + 1;
            $nextLevel->bind_param('i', $nextSortOrder);
            $nextLevel->execute();
            $next = $nextLevel->get_result()->fetch_assoc();
            $nextLevel->close();

            if (!$next) {
                $skipped++; // already at the highest level
                continue;
            }

            $update = $mysqli->prepare('UPDATE students SET current_class_level_id = ? WHERE id = ?');
            $update->bind_param('ii', $next['id'], $studentId);
            $update->execute();
            $update->close();

            $log = $mysqli->prepare(
                'INSERT INTO promotions (student_id, from_class_level_id, to_class_level_id, promoted_by)
                 VALUES (?, ?, ?, ?)'
            );
            $log->bind_param('iiii', $studentId, $current['current_class_level_id'], $next['id'], $adminId);
            $log->execute();
            $log->close();

            $promoted++;
        }

        $notice = "เลื่อนชั้นนักศึกษาแล้ว $promoted คน" . ($skipped > 0 ? " ข้าม $skipped คน (อยู่ระดับสูงสุดแล้วหรือยังไม่ได้รับการอนุมัติ)" : '');
    }
}

$students = $mysqli->query(
    "SELECT s.id, s.student_no, s.full_name, cl.name AS level_name, cl.sort_order, b.batch_no, b.name AS batch_name
     FROM students s
     JOIN class_levels cl ON cl.id = s.current_class_level_id
     JOIN batches b ON b.id = s.batch_id
     WHERE s.status = 'approved'
     ORDER BY b.batch_no DESC, cl.sort_order, s.full_name"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap">
    <title>เลื่อนชั้นนักศึกษา - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body class="admin">
    <h1>เลื่อนชั้นนักศึกษา</h1>
    <nav>
        <a href="dashboard.php">กลับหน้าแผงควบคุม</a>
        <form class="nav-logout" action="logout.php" method="post"><?= csrfField() ?><button type="submit">ออกจากระบบ</button></form>
    </nav>

    <?php if ($notice): ?><p class="success"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <form action="promotions.php" method="post">
        <?= csrfField() ?>
        <div class="table-wrap"><table>
            <thead>
                <tr><th></th><th>รหัสนักศึกษา</th><th>ชื่อ-นามสกุล</th><th>รุ่น</th><th>ระดับชั้นปัจจุบัน</th></tr>
            </thead>
            <tbody>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td><input type="checkbox" name="student_ids[]" value="<?= $student['id'] ?>"></td>
                        <td><?= htmlspecialchars($student['student_no'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($student['full_name']) ?></td>
                        <td><?= htmlspecialchars($student['batch_name'] ?: 'รุ่น ' . $student['batch_no']) ?></td>
                        <td><?= htmlspecialchars($student['level_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <button type="submit">เลื่อนชั้นนักศึกษาที่เลือกไปยังระดับถัดไป</button>
    </form>
</body>
</html>
