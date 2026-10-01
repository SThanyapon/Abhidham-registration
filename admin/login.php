<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/otp.php';
require_once __DIR__ . '/../includes/rate_limit.php';

ensureSessionStarted();

if (currentAdminId() !== null) {
    header('Location: dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $ip = getClientIp();
    $username = cleanText($_POST['username'] ?? '');

    $ipOk = checkRateLimit('admin_login', $ip);
    $accountOk = $username === '' || checkRateLimit('admin_login_account', $username);

    if (!$ipOk || !$accountOk) {
        $error = 'มีการพยายามเข้าสู่ระบบหลายครั้งเกินไป กรุณาลองใหม่ภายหลัง';
    } else {
        recordRateLimitHit('admin_login', $ip);
        if ($username !== '') {
            recordRateLimitHit('admin_login_account', $username);
        }

        $password = $_POST['password'] ?? '';

        $mysqli = getDbConnection();
        $stmt = $mysqli->prepare(
            'SELECT id, email, password_hash FROM admin_users WHERE username = ? AND is_active = 1'
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            $_SESSION['otp_pending_admin_id'] = (int) $admin['id'];
            generateAndSendOtp((int) $admin['id'], $admin['email']);
            header('Location: verify_otp.php');
            exit;
        }

        $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
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
    <title>เข้าสู่ระบบผู้ดูแล - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body class="admin">
    <h1>เข้าสู่ระบบผู้ดูแล</h1>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form action="login.php" method="post">
        <?= csrfField() ?>
        <label for="username">ชื่อผู้ใช้</label>
        <input type="text" id="username" name="username" required>

        <label for="password">รหัสผ่าน</label>
        <input type="password" id="password" name="password" required>

        <button type="submit">เข้าสู่ระบบ</button>
    </form>
</body>
</html>
