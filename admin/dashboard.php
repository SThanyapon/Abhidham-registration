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
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap">
    <title>แผงควบคุม - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body class="admin">
    <h1>แผงควบคุมสำหรับผู้ดูแลระบบ</h1>
    <nav>
        <form class="nav-logout" action="logout.php" method="post"><?= csrfField() ?><button type="submit">ออกจากระบบ</button></form>
    </nav>

    <div class="card feature-links">
        <?php if (in_array(0, $features, true)): ?>
            <a href="admins.php">0) จัดการผู้ดูแลระบบ</a>
        <?php endif; ?>
        <?php if (in_array(1, $features, true)): ?>
            <a href="classes.php">1) จัดการชั้นเรียนและตารางเรียน</a>
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
        <?php if (in_array(6, $features, true)): ?>
            <a href="import_students.php">6) นำเข้ารายชื่อนักศึกษา (CSV)</a>
        <?php endif; ?>
        <?php if (in_array(7, $features, true)): ?>
            <a href="students.php">7) แก้ไขข้อมูลนักศึกษา</a>
        <?php endif; ?>
        <?php if (in_array(9, $features, true)): ?>
            <a href="backup.php">9) การสำรองข้อมูล</a>
        <?php endif; ?>
        <?php if ($features === []): ?>
            <p>คุณไม่มีสิทธิ์ในการเข้าถึงฟีเจอร์ผู้ดูแลระบบใด ๆ โปรดขอให้ผู้ดูแลระบบคนอื่นให้สิทธิ์การเข้าถึง</p>
        <?php endif; ?>
    </div>
</body>
</html>
