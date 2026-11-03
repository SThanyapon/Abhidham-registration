<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/auth.php';

$adminId = requireAdminLogin();
requireFeature($adminId, 1);

$mysqli = getDbConnection();
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_batch') {
        $batchNo = (int) ($_POST['batch_no'] ?? 0);
        $name = cleanText($_POST['name'] ?? '');

        if ($batchNo <= 0) {
            $error = 'กรุณาระบุรุ่นที่';
        } else {
            $stmt = $mysqli->prepare('INSERT INTO batches (batch_no, name) VALUES (?, ?)');
            $stmt->bind_param('is', $batchNo, $name);
            $stmt->execute();
            $stmt->close();
            $notice = "เปิดรุ่นที่ $batchNo เรียบร้อยแล้ว";
        }
    } elseif ($action === 'toggle_registration') {
        $batchId = (int) ($_POST['batch_id'] ?? 0);
        // Only the latest batch (highest batch_no) may have its registration opened/closed.
        $latest = $mysqli->query('SELECT id FROM batches ORDER BY batch_no DESC LIMIT 1')->fetch_assoc();

        if (!$latest || (int) $latest['id'] !== $batchId) {
            $error = 'เปิด/ปิดรับลงทะเบียนได้เฉพาะรุ่นล่าสุดเท่านั้น';
        } else {
            $stmt = $mysqli->prepare('UPDATE batches SET registration_open = NOT registration_open WHERE id = ?');
            $stmt->bind_param('i', $batchId);
            $stmt->execute();
            $stmt->close();
            $notice = 'อัปเดตสถานะการลงทะเบียนเรียบร้อยแล้ว';
        }
    }
}

$batches = $mysqli->query(
    'SELECT b.id, b.batch_no, b.name, b.registration_open,
            (SELECT COUNT(*) FROM class_instances ci WHERE ci.batch_id = b.id) AS class_count
     FROM batches b
     ORDER BY b.batch_no DESC'
)->fetch_all(MYSQLI_ASSOC);
$latestBatchId = isset($batches[0]) ? (int) $batches[0]['id'] : null;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap">
    <title>จัดการรุ่น - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body class="admin">
    <h1>จัดการรุ่น</h1>
    <nav>
        <a href="dashboard.php">กลับหน้าแผงควบคุม</a>
        <a href="classes.php">ห้องเรียนทั้งหมด</a>
        <form class="nav-logout" action="logout.php" method="post"><?= csrfField() ?><button type="submit">ออกจากระบบ</button></form>
    </nav>

    <?php if ($notice): ?><p class="success"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <h2>รุ่นที่เปิดสอน</h2>
    <div class="table-wrap"><table>
        <thead>
            <tr><th>รุ่นที่</th><th>ชื่อรุ่น</th><th>สถานะการลงทะเบียน</th><th>ห้องเรียน</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($batches as $batch): ?>
                <tr>
                    <td><?= $batch['batch_no'] ?></td>
                    <td><?= htmlspecialchars($batch['name'] ?? '') ?></td>
                    <td><?= $batch['registration_open'] ? 'เปิดลงทะเบียน' : 'ยังไม่เปิดลงทะเบียน' ?></td>
                    <td><a href="classes.php?batch_id=<?= $batch['id'] ?>">จัดการห้องเรียน (<?= (int) $batch['class_count'] ?>)</a></td>
                    <td>
                        <?php if ((int) $batch['id'] === $latestBatchId): ?>
                            <form action="batches.php" method="post" class="inline-form">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_registration">
                                <input type="hidden" name="batch_id" value="<?= $batch['id'] ?>">
                                <button type="submit" class="secondary"><?= $batch['registration_open'] ? 'ปิดรับลงทะเบียน' : 'เปิดรับลงทะเบียน' ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>

    <form action="batches.php" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_batch">
        <label for="batch_no">รุ่นที่เปิดใหม่</label>
        <input type="number" id="batch_no" name="batch_no" required>
        <label for="name">ชื่อรุ่นที่เปิดใหม่</label>
        <input type="text" id="name" name="name" placeholder="เช่น รุ่น 7 ฉัฏฐญาณะ">
        <button type="submit">เปิดรุ่นใหม่</button>
    </form>

</body>
</html>
