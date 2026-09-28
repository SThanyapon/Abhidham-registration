<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backup.php';

$adminId = requireAdminLogin();
requireFeature($adminId, 5);

$mysqli = getDbConnection();
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (($_POST['action'] ?? '') === 'backup_now') {
        $result = runBackup('manual');
        $notice = 'สำรองข้อมูลเรียบร้อยแล้วที่ ' . $result['file_path'] . ($result['emailed'] ? ' และส่งอีเมลแจ้งเตือนแล้ว' : ' (ส่งอีเมลแจ้งเตือนไม่สำเร็จ - กรุณาตรวจสอบการตั้งค่าอีเมล)');
    }
}

$runs = $mysqli->query('SELECT file_path, triggered_by, emailed_to, created_at FROM backup_runs ORDER BY id DESC LIMIT 20')
    ->fetch_all(MYSQLI_ASSOC);

$triggerLabels = ['manual' => 'ด้วยตนเอง', 'scheduled' => 'ตามกำหนดเวลา'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap">
    <title>สำรองฐานข้อมูล - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <h1>สำรองฐานข้อมูล</h1>
    <nav>
        <a href="dashboard.php">กลับหน้าแผงควบคุม</a>
        <a href="logout.php">ออกจากระบบ</a>
    </nav>

    <?php if ($notice): ?><p class="success"><?= htmlspecialchars($notice) ?></p><?php endif; ?>

    <form action="backup.php" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="backup_now">
        <button type="submit">สำรองข้อมูลตอนนี้</button>
    </form>

    <p>หากต้องการสำรองข้อมูลตามกำหนดเวลา ให้ตั้งค่า Windows Task Scheduler (หรือ cron) ให้เรียก
       <code>cron/backup.php</code> ผ่าน PHP CLI เช่น <code>php cron/backup.php</code> ตามช่วงเวลาที่ต้องการ</p>

    <h2>ประวัติการสำรองข้อมูลล่าสุด</h2>
    <div class="table-wrap"><table>
        <thead>
            <tr><th>ไฟล์</th><th>วิธีสำรอง</th><th>ส่งอีเมลถึง</th><th>เวลา</th></tr>
        </thead>
        <tbody>
            <?php foreach ($runs as $run): ?>
                <tr>
                    <td><?= htmlspecialchars($run['file_path']) ?></td>
                    <td><?= htmlspecialchars($triggerLabels[$run['triggered_by']] ?? $run['triggered_by']) ?></td>
                    <td><?= htmlspecialchars($run['emailed_to'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($run['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</body>
</html>
