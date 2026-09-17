<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

$adminId = requireAdminLogin();

$mysqli = getDbConnection();
$stmt = $mysqli->prepare('SELECT feature FROM admin_permissions WHERE admin_user_id = ?');
$stmt->bind_param('i', $adminId);
$stmt->execute();
$features = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'feature'));
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - Abhidham Registration</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <h1>Admin Dashboard</h1>
    <nav>
        <a href="logout.php">Log out</a>
    </nav>

    <div class="card feature-links">
        <?php if (in_array(1, $features, true)): ?>
            <a href="classes.php">1) Manage classes &amp; schedules</a>
        <?php endif; ?>
        <?php if (in_array(2, $features, true)): ?>
            <a href="approvals.php">2) Approve enrollments</a>
        <?php endif; ?>
        <?php if (in_array(3, $features, true)): ?>
            <a href="reports.php">3) Reports</a>
        <?php endif; ?>
        <?php if (in_array(4, $features, true)): ?>
            <a href="promotions.php">4) Promote students</a>
        <?php endif; ?>
        <?php if (in_array(5, $features, true)): ?>
            <a href="backup.php">5) Backup</a>
        <?php endif; ?>
        <?php if ($features === []): ?>
            <p>You don't have access to any admin features yet. Ask another admin to grant access.</p>
        <?php endif; ?>
    </div>
</body>
</html>
