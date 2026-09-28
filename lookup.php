<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/input.php';

$error = null;
$studentNo = null;
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
        $stmt = $mysqli->prepare(
            'SELECT student_no FROM students WHERE full_name = ? AND status = "approved"'
        );
        $stmt->bind_param('s', $fullName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $studentNo = $row['student_no'] ?? null;
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
        <?php if ($studentNo): ?>
            <p class="success">รหัสนักศึกษาของคุณ: <strong><?= htmlspecialchars($studentNo) ?></strong></p>
        <?php else: ?>
            <p class="error">ไม่พบนักศึกษาที่ได้รับการอนุมัติในชื่อนี้</p>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
