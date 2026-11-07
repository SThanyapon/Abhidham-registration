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

// Minimum wait between OTP emails (from login.php's first send or the last resend).
const OTP_RESEND_COOLDOWN_SECONDS = 120;

$error = null;
$success = null;

// Result of a resend, shown once after the redirect.
if (isset($_SESSION['otp_flash'])) {
    if ($_SESSION['otp_flash']['type'] === 'success') {
        $success = $_SESSION['otp_flash']['text'];
    } else {
        $error = $_SESSION['otp_flash']['text'];
    }
    unset($_SESSION['otp_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    verifyCsrf();

    $wait = (int) ($_SESSION['otp_sent_at'] ?? 0) + OTP_RESEND_COOLDOWN_SECONDS - time();
    if ($wait > 0) {
        $flash = ['type' => 'error', 'text' => "กรุณารอ $wait วินาทีก่อนขอรหัสใหม่"];
    } elseif (!checkRateLimit('otp_resend', (string) $pendingAdminId)) {
        $flash = ['type' => 'error', 'text' => 'ขอรหัสใหม่หลายครั้งเกินไป กรุณาลองใหม่ภายหลัง'];
    } else {
        recordRateLimitHit('otp_resend', (string) $pendingAdminId);

        $stmt = getDbConnection()->prepare('SELECT email FROM admin_users WHERE id = ? AND is_active = 1');
        $stmt->bind_param('i', $pendingAdminId);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$admin) {
            // Disabled or deleted since the password step.
            unset($_SESSION['otp_pending_admin_id'], $_SESSION['otp_sent_at']);
            header('Location: login.php');
            exit;
        }

        // generateAndSendOtp() retires the earlier codes, so only the new one works.
        if (generateAndSendOtp((int) $pendingAdminId, $admin['email'])) {
            $_SESSION['otp_sent_at'] = time();
            $flash = ['type' => 'success', 'text' => 'ส่งรหัสใหม่ไปยังอีเมลแล้ว รหัสเดิมใช้ไม่ได้อีกต่อไป'];
        } else {
            $flash = ['type' => 'error', 'text' => 'ส่งรหัส OTP ทางอีเมลไม่สำเร็จ กรุณาลองใหม่อีกครั้ง หรือติดต่อผู้ดูแลระบบ'];
        }
    }

    // Redirect so a refresh can't send another email.
    $_SESSION['otp_flash'] = $flash;
    header('Location: verify_otp.php');
    exit;
}

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
    <?php if ($success): ?>
        <p class="success"><?= htmlspecialchars($success) ?></p>
    <?php endif; ?>

    <form action="verify_otp.php" method="post">
        <?= csrfField() ?>
        <label for="code">รหัสยืนยัน</label>
        <input type="text" id="code" name="code" maxlength="6" required>
        <button type="submit">ยืนยัน</button>
    </form>

    <form action="verify_otp.php" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="resend">
        <p class="form-note">ไม่ได้รับอีเมล? ขอรหัสใหม่ได้ทุก <?= intdiv(OTP_RESEND_COOLDOWN_SECONDS, 60) ?> นาที (รหัสเดิมจะใช้ไม่ได้)</p>
        <button type="submit" class="secondary">ส่งรหัสยืนยันการเข้าสู่ระบบอีกครั้ง</button>
    </form>
</body>
</html>
