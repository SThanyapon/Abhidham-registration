<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/input.php';

$error = null;
$result = null;
$searched = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $ip = getClientIp();

    if (!checkRateLimit('lookup', $ip)) {
        $error = 'มีการลองหลายครั้งเกินไป กรุณาลองใหม่ภายหลัง';
    } else {
        recordRateLimitHit('lookup', $ip);
        $searched = true;

        $fullName = cleanText($_POST['full_name'] ?? '');

        $mysqli = getDbConnection();
        // A name may have several registrations (e.g. rejected, then re-registered); report the most
        // relevant one: approved, then pending, then rejected, newest first.
        $stmt = $mysqli->prepare(
            "SELECT student_no, status FROM students WHERE full_name = ?
             ORDER BY FIELD(status, 'approved', 'pending', 'rejected'), created_at DESC
             LIMIT 1"
        );
        $stmt->bind_param('s', $fullName);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap">
    <title>ค้นหารหัสนักศึกษา - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <h1>ค้นหารหัสนักศึกษา</h1>
    <nav>
        <a href="index.php">ลงทะเบียน</a>
        <a href="checkin.php">ลงชื่อเข้าเรียน</a>
        <a href="lookup.php">ค้นหารหัสนักศึกษา</a>
        <a class="nav-admin" href="admin/login.php">ผู้ดูแลระบบ</a>
    </nav>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form action="lookup.php" method="post">
        <?= csrfField() ?>
        <label for="full_name">ชื่อ-นามสกุล (ภาษาไทย)</label>
        <input type="text" id="full_name" name="full_name" required>
        <button type="submit">ค้นหา</button>
    </form>

    <?php if ($searched): ?>
        <?php
        $nameLine = 'ชื่อ-นามสกุล: <strong>' . htmlspecialchars($fullName) . '</strong><br>';
        ?>
        <?php if ($result === null): ?>
            <p class="error"><?= $nameLine ?>ไม่พบการลงทะเบียนของท่าน กรุณาทำการลงทะเบียนก่อนค่ะ</p>
        <?php elseif ($result['status'] === 'approved'): ?>
            <p class="success"><?= $nameLine ?>รหัสนักศึกษาของคุณ: <strong><?= htmlspecialchars($result['student_no'] ?? '-') ?></strong></p>
        <?php elseif ($result['status'] === 'pending'): ?>
            <p class="notice"><?= $nameLine ?>คุณได้ทำการลงทะเบียนแล้ว แต่ยังไม่ได้รับการตรวจสอบจากผู้ดูแลระบบ กรุณาตรวจสอบใหม่ภายหลัง และขอความร่วมมือไม่ลงทะเบียนซ้ำ</p>
        <?php else: ?>
            <p class="error"><?= $nameLine ?>คุณไม่ได้รับการอนุมัติการลงทะเบียน ขอบคุณค่ะ</p>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
