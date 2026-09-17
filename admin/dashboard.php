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
    <title> ส่วนสำหรับผู้ดูแลระบบ </title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <h1>แผงควบคุมสำหรับผู้ดูแลระบบ</h1>
    <nav>
        <a href="logout.php">ออกจากระบบ</a>
    </nav>

    <div class="card feature-links">
        <?php if (in_array(1, $features, true)): ?>
            <a href="classes.php">1) จัดการชั้นเรียน และตารางเรียน </a>
        <?php endif; ?>
        <?php if (in_array(2, $features, true)): ?>
            <a href="approvals.php">2) อนุมัติการลงทะเบียน</a>
        <?php endif; ?>
        <?php if (in_array(3, $features, true)): ?>
            <a href="reports.php">3) รายงาน</a>
        <?php endif; ?>
        <?php if (in_array(4, $features, true)): ?>
            <a href="promotions.php">4) การเลื่อนชั้นนักศึกษา</a>
        <?php endif; ?>
        <?php if (in_array(5, $features, true)): ?>
            <a href="backup.php">5) การสำรองข้อมูล</a>
        <?php endif; ?>
        <?php if ($features === []): ?>
            <p>คุณไม่มีสิทธิ์ในการเข้าถึงฟีเจอร์ผู้ดูแลระบบใด ๆ โปรดขอให้ผู้ดูแลระบบคนอื่นให้สิทธิ์การเข้าถึง</p>
        <?php endif; ?>
    </div>
</body>
</html>
