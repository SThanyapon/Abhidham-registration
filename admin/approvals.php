<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/student_id.php';
require_once __DIR__ . '/../includes/student_helpers.php';

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
        $notice = 'ไม่อนุมัตินักศึกษาเรียบร้อยแล้ว';
    } elseif ($action === 'approve') {
        $override = cleanCode($_POST['override_student_no'] ?? '');

        $stmt = $mysqli->prepare('SELECT prefix, batch_id FROM students WHERE id = ? AND status = "pending"');
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$student) {
            $error = 'ไม่พบนักศึกษา หรือได้ดำเนินการไปแล้ว';
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
                $notice = "อนุมัตินักศึกษาเรียบร้อย รหัสนักศึกษา $studentNo";
            } catch (mysqli_sql_exception $e) {
                $error = "ไม่สามารถกำหนดรหัส '$studentNo' ได้ (อาจมีผู้ใช้รหัสนี้แล้ว) "
                    . 'กรุณาลองใหม่โดยกำหนดรหัสเอง';
            }
        }
    }
}

$pending = $mysqli->query(
    "SELECT s.*, b.batch_no, b.name AS batch_name
     FROM students s
     JOIN batches b ON b.id = s.batch_id
     WHERE s.status = 'pending'
     ORDER BY s.created_at"
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
    <title>อนุมัติการลงทะเบียน - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body class="admin">
    <h1>อนุมัติการลงทะเบียน</h1>
    <nav>
        <a href="dashboard.php">กลับหน้าแผงควบคุม</a>
        <form class="nav-logout" action="logout.php" method="post"><?= csrfField() ?><button type="submit">ออกจากระบบ</button></form>
    </nav>

    <?php if ($notice): ?><p class="success"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <?php if ($pending === []): ?>
        <p>ไม่มีการลงทะเบียนที่รอการอนุมัติ</p>
    <?php endif; ?>

    <?php foreach ($pending as $student): ?>
        <div class="card">
            <p>
                <strong><?= htmlspecialchars(studentPrefix($student) . ' ' . $student['full_name']) ?></strong>
                — <?= htmlspecialchars($student['batch_name'] ?: 'รุ่น ' . $student['batch_no']) ?>
            </p>
            <p>
                อายุ: <?= htmlspecialchars((string) ($student['age'] ?? '-')) ?> |
                เบอร์มือถือ: <?= htmlspecialchars($student['phone']) ?> |
                ชื่อไลน์: <?= htmlspecialchars($student['line_name'] ?: '-') ?> |
                LINE ID: <?= htmlspecialchars($student['line_id'] ?: '-') ?>
            </p>
            <p>
                ที่อยู่: <?= htmlspecialchars(trim(($student['address'] ?? '') . ' ' . ($student['province'] ?? '') . ' ' . ($student['postal_code'] ?? '')) ?: '-') ?>
            </p>
            <p>
                ทราบข่าวจาก: <?= htmlspecialchars(studentChoice($student, 'heard_from')) ?> |
                นักศึกษา: <?= htmlspecialchars(studentChoice($student, 'student_type')) ?>
                <?php if ($student['previous_student_no']): ?>
                    (รหัสเดิม <?= htmlspecialchars($student['previous_student_no']) ?>)
                <?php endif; ?> |
                เพื่อนที่แนะนำ: <?= htmlspecialchars($student['reference_person'] ?: '-') ?>
            </p>
            <p>
                ใช้ ZOOM: <?= htmlspecialchars(studentChoice($student, 'zoom_skill')) ?> |
                เข้าห้องเรียน: <?= htmlspecialchars(studentChoice($student, 'joined_classroom')) ?>
            </p>
            <p>เหตุผลที่มาเรียน: <?= nl2br(htmlspecialchars($student['study_reason'] ?: '-')) ?></p>

            <div class="action-row">
                <form action="approvals.php" method="post" class="inline-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="student_id" value="<?= $student['id'] ?>">
                    <label class="checkbox-label">กำหนดรหัสนักศึกษาเอง (ไม่บังคับ)</label>
                    <input type="text" name="override_student_no" placeholder="กำหนดอัตโนมัติ">
                    <button type="submit">อนุมัติ</button>
                </form>
                <form action="approvals.php" method="post" class="inline-form">
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
