<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/student_id.php';

$adminId = requireAdminLogin();
requireFeature($adminId, 2);

$mysqli = getDbConnection();
$notice = null;
$error = null;

// Level 1 in sort_order is จูฬตรี - every new registration starts there.
$firstLevel = $mysqli->query('SELECT id FROM class_levels WHERE sort_order = 1')->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $studentId = (int) ($_POST['student_id'] ?? 0);

    if ($action === 'reject') {
        $stmt = $mysqli->prepare('UPDATE students SET status = "rejected" WHERE id = ?');
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $stmt->close();
        $notice = 'Student rejected.';
    } elseif ($action === 'approve') {
        $override = trim($_POST['override_student_no'] ?? '');

        $stmt = $mysqli->prepare('SELECT prefix, batch_id FROM students WHERE id = ? AND status = "pending"');
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$student) {
            $error = 'Student not found or already processed.';
        } else {
            $batchRow = $mysqli->query(
                'SELECT batch_no FROM batches WHERE id = ' . (int) $student['batch_id']
            )->fetch_assoc();

            $studentNo = $override !== ''
                ? $override
                : generateStudentNo($mysqli, (int) $student['batch_id'], (int) $batchRow['batch_no'], $student['prefix']);

            try {
                $update = $mysqli->prepare(
                    'UPDATE students SET student_no = ?, status = "approved", current_class_level_id = ? WHERE id = ?'
                );
                $update->bind_param('sii', $studentNo, $firstLevel['id'], $studentId);
                $update->execute();
                $update->close();
                $notice = "Student approved with ID $studentNo.";
            } catch (mysqli_sql_exception $e) {
                $error = "Could not assign ID '$studentNo' (it may already be in use). "
                    . 'Please retry with a manually specified ID.';
            }
        }
    }
}

$pending = $mysqli->query(
    "SELECT s.id, s.prefix, s.prefix_other, s.full_name, s.age, s.phone, s.line_id, s.reference_person,
            b.batch_no, b.name AS batch_name
     FROM students s
     JOIN batches b ON b.id = s.batch_id
     WHERE s.status = 'pending'
     ORDER BY s.created_at"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title> อนุมัติการลงทะเบียน</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <h1>อนุมัติการลงทะเบียน</h1>
    <nav>
        <a href="dashboard.php">กลับหน้าแผงควบคุม</a>
        <a href="logout.php">ออกจากระบบ</a>
    </nav>

    <?php if ($notice): ?><p class="success"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <?php if ($pending === []): ?>
        <p>ไม่มีการลงทะเบียนที่รอการอนุมัติ</p>
    <?php endif; ?>

    <?php foreach ($pending as $student): ?>
        <div class="card">
            <p>
                <strong><?= htmlspecialchars(($student['prefix'] === 'อื่นๆ' ? $student['prefix_other'] : $student['prefix']) . ' ' . $student['full_name']) ?></strong>
                — <?= htmlspecialchars($student['batch_name'] ?: 'รุ่น ' . $student['batch_no']) ?>
            </p>
            <p>
                อายุ: <?= htmlspecialchars((string) ($student['age'] ?? '-')) ?> |
                หมายเลขโทรศัพท์: <?= htmlspecialchars($student['phone']) ?> |
                ไอดีไลน์: <?= htmlspecialchars($student['line_id'] ?? '-') ?> |
                ผู้แนะนำ: <?= htmlspecialchars($student['reference_person'] ?? '-') ?>
            </p>

            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <form action="approvals.php" method="post" style="flex-direction:row; align-items:center; gap:8px; background:none; box-shadow:none; padding:0; margin:0;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="student_id" value="<?= $student['id'] ?>">
                    <label style="font-weight:normal;"> กำหนดรหัสนักศึกษาเอง (optional)</label>
                    <input type="text" name="override_student_no" placeholder="กำหนดอัตโนมัติ">
                    <button type="submit">อนุมัติ</button>
                </form>
                <form action="approvals.php" method="post" style="display:inline; box-shadow:none; padding:0; background:none; margin:0;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="student_id" value="<?= $student['id'] ?>">
                    <button type="submit" class="danger">ไม่อนุมัติ</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</body>
</html>
