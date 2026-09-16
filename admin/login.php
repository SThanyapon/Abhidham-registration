<?php

require_once __DIR__ . '/../config.php';
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

    if (!checkRateLimit('admin_login', $ip)) {
        $error = 'Too many login attempts. Please try again later.';
    } else {
        recordRateLimitHit('admin_login', $ip);

        $username = trim($_POST['username'] ?? '');
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

        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login - Abhidham Registration</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <h1>Admin Login</h1>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form action="login.php" method="post">
        <?= csrfField() ?>
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required>

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>

        <button type="submit">Log in</button>
    </form>
</body>
</html>
