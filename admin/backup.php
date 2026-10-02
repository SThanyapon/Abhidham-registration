<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/input.php';

$adminId = requireAdminLogin();
requireFeature($adminId, 9);

$mysqli = getDbConnection();
$notice = null;
$error = null;
$recipientsInput = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_recipients') {
        $recipientsInput = cleanText($_POST['recipient_emails'] ?? '');
        $emails = array_values(array_filter(array_map('trim', explode(',', $recipientsInput)), static fn ($e) => $e !== ''));
        $invalid = array_filter($emails, static fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) === false);

        if ($emails === []) {
            $error = 'กรุณาระบุอีเมลผู้รับอย่างน้อย 1 อีเมล';
        } elseif ($invalid !== []) {
            $error = 'อีเมลไม่ถูกต้อง: ' . implode(', ', $invalid);
        } elseif (mb_strlen(implode(', ', $emails)) > 255) {
            $error = 'รายการอีเมลยาวเกินไป (ไม่เกิน 255 ตัวอักษร)';
        } else {
            saveBackupRecipients(array_values(array_unique($emails)));
            $recipientsInput = null;
            $notice = 'บันทึกอีเมลผู้รับเรียบร้อยแล้ว';
        }
    } elseif ($action === 'backup_now') {
        $result = runBackup('manual');
        if ($result['emailed']) {
            $notice = 'สำรองข้อมูลเรียบร้อยแล้วที่ ' . $result['file_path'] . ' และส่งไฟล์ (บีบอัด) ทางอีเมลแล้ว';
        } else {
            $error = 'สำรองข้อมูลเรียบร้อยแล้วที่ ' . $result['file_path']
                . ' แต่ส่งอีเมลไม่สำเร็จ - กรุณาตรวจสอบอีเมลผู้รับและการตั้งค่าอีเมล';
        }
    }
}

$currentRecipients = implode(', ', getBackupRecipients());

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
<body class="admin">
    <h1>สำรองฐานข้อมูล</h1>
    <nav>
        <a href="dashboard.php">กลับหน้าแผงควบคุม</a>
        <a class="nav-logout" href="logout.php">ออกจากระบบ</a>
    </nav>

    <?php if ($notice): ?><p class="success"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <div class="card">
        <form action="backup.php" method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_recipients">
            <label for="recipient_emails">อีเมลผู้รับไฟล์สำรองข้อมูล<span class="required-mark">*</span></label>
            <input type="text" id="recipient_emails" name="recipient_emails" required
                   value="<?= htmlspecialchars($recipientsInput ?? $currentRecipients) ?>">
            <p class="form-note">คั่นหลายอีเมลด้วยเครื่องหมายจุลภาค (,) ไฟล์สำรองข้อมูลจะถูกบีบอัด (.sql.gz) และแนบไปกับอีเมล</p>
            <button type="submit" class="secondary">บันทึก</button>
        </form>
    </div>

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
