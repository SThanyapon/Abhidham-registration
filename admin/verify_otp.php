<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/otp.php';

ensureSessionStarted();

$pendingAdminId = $_SESSION['otp_pending_admin_id'] ?? null;

if ($pendingAdminId === null) {
    header('Location: login.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $code = trim($_POST['code'] ?? '');

    if (verifyOtp((int) $pendingAdminId, $code)) {
        loginAdmin((int) $pendingAdminId);
        header('Location: dashboard.php');
        exit;
    }

    $error = 'Invalid or expired code.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Code - Abhidham Registration</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <h1>Enter your login code</h1>
    <p>We emailed a 6-digit code to your registered email address.</p>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form action="verify_otp.php" method="post">
        <?= csrfField() ?>
        <label for="code">Code</label>
        <input type="text" id="code" name="code" maxlength="6" required>
        <button type="submit">Verify</button>
    </form>
</body>
</html>
