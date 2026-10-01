<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/otp.php';
require_once __DIR__ . '/../includes/rate_limit.php';

ensureSessionStarted();

$pendingAdminId = $_SESSION['otp_pending_admin_id'] ?? null;

if ($pendingAdminId === null) {
    header('Location: login.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (!checkRateLimit('otp_verify', (string) $pendingAdminId)) {
        $error = 'มีการลองหลายครั้งเกินไป กรุณาลองใหม่ภายหลัง';
    } else {
        recordRateLimitHit('otp_verify', (string) $pendingAdminId);

        $code = cleanCode($_POST['code'] ?? '');

        if (verifyOtp((int) $pendingAdminId, $code)) {
            loginAdmin((int) $pendingAdminId);
            header('Location: dashboard.php');
            exit;
        }

        $error = 'รหัสไม่ถูกต้องหรือหมดอายุแล้ว';
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
    <title>ยืนยันรหัส - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body class="admin">
    <h1>กรอกรหัสยืนยันการเข้าสู่ระบบ</h1>
    <p>ระบบได้ส่งรหัส 6 หลักไปยังอีเมลที่คุณลงทะเบียนไว้แล้ว</p>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form action="verify_otp.php" method="post">
        <?= csrfField() ?>
        <label for="code">รหัสยืนยัน</label>
        <input type="text" id="code" name="code" maxlength="6" required>
        <button type="submit">ยืนยัน</button>
    </form>
</body>
</html>
