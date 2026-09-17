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
        $error = 'No students selected.';
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

        $notice = "$promoted student(s) promoted." . ($skipped > 0 ? " $skipped skipped (already at highest level or not approved)." : '');
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Promote Students - Abhidham Registration</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <h1>Promote Students</h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="logout.php">Log out</a>
    </nav>

    <?php if ($notice): ?><p class="success"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <form action="promotions.php" method="post">
        <?= csrfField() ?>
        <table>
            <thead>
                <tr><th></th><th>Student ID</th><th>Name</th><th>รุ่น</th><th>Current level</th></tr>
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
        </table>
        <button type="submit">Promote selected to next level</button>
    </form>
</body>
</html>
