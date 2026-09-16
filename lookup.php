<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/csrf.php';

$error = null;
$studentNo = null;
$searched = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $ip = getClientIp();

    if (!checkRateLimit('lookup', $ip)) {
        $error = 'Too many attempts. Please try again later.';
    } else {
        recordRateLimitHit('lookup', $ip);
        $searched = true;

        $fullName = trim($_POST['full_name'] ?? '');

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
    <title>Find my Student ID - Abhidham Registration</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <h1>Find my Student ID</h1>
    <nav>
        <a href="index.php">Register</a>
        <a href="checkin.php">Check-in</a>
        <a href="lookup.php">Find my Student ID</a>
        <a href="admin/login.php">Admin</a>
    </nav>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form action="lookup.php" method="post">
        <?= csrfField() ?>
        <label for="full_name">Name-Surname (Thai)</label>
        <input type="text" id="full_name" name="full_name" required>
        <button type="submit">Search</button>
    </form>

    <?php if ($searched): ?>
        <?php if ($studentNo): ?>
            <p class="success">Your Student ID: <strong><?= htmlspecialchars($studentNo) ?></strong></p>
        <?php else: ?>
            <p class="error">No approved student found with that name.</p>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
