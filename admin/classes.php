<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/date_helpers.php';

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
    } elseif ($action === 'create_class_instance') {
        $batchId = (int) ($_POST['batch_id'] ?? 0);
        $classLevelId = (int) ($_POST['class_level_id'] ?? 0);

        if ($batchId <= 0 || $classLevelId <= 0) {
            $error = 'กรุณาระบุรุ่นและระดับชั้น';
        } else {
            // The start date comes from the schedule (admin/schedule.php), not from here.
            $stmt = $mysqli->prepare('INSERT INTO class_instances (batch_id, class_level_id) VALUES (?, ?)');
            $stmt->bind_param('ii', $batchId, $classLevelId);
            $stmt->execute();
            $stmt->close();
            $notice = 'สร้างห้องเรียนเรียบร้อยแล้ว';
        }
    }
}

$batches = $mysqli->query('SELECT id, batch_no, name, registration_open FROM batches ORDER BY batch_no DESC')
    ->fetch_all(MYSQLI_ASSOC);
$latestBatchId = isset($batches[0]) ? (int) $batches[0]['id'] : null;

$classLevels = $mysqli->query('SELECT id, name, sort_order FROM class_levels ORDER BY sort_order')
    ->fetch_all(MYSQLI_ASSOC);

$classInstances = $mysqli->query(
    "SELECT ci.id, ci.batch_id, cl.name AS level_name, cl.sort_order, b.batch_no, b.name AS batch_name,
            (SELECT COUNT(*) FROM sessions s WHERE s.class_instance_id = ci.id AND s.is_cancelled = 0) AS session_count,
            (SELECT MIN(s.session_date) FROM sessions s WHERE s.class_instance_id = ci.id AND s.is_cancelled = 0) AS first_session_date
     FROM class_instances ci
     JOIN class_levels cl ON cl.id = ci.class_level_id
     JOIN batches b ON b.id = ci.batch_id
     ORDER BY b.batch_no DESC, cl.sort_order"
)->fetch_all(MYSQLI_ASSOC);

// Schedule management is linked only for each batch's highest class level.
$maxSortByBatch = [];
foreach ($classInstances as $class) {
    $maxSortByBatch[$class['batch_id']] = max($maxSortByBatch[$class['batch_id']] ?? 0, (int) $class['sort_order']);
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
    <title>การจัดการชั้นเรียน - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body class="admin">
    <h1>การจัดการชั้นเรียน</h1>
    <nav>
        <a href="dashboard.php">กลับหน้าแผงควบคุม</a>
        <form class="nav-logout" action="logout.php" method="post"><?= csrfField() ?><button type="submit">ออกจากระบบ</button></form>
    </nav>

    <?php if ($notice): ?><p class="success"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <h2>รุ่นที่เปิดสอน</h2>
    <div class="table-wrap"><table>
        <thead>
            <tr><th>รุ่นที่</th><th>ชื่อรุ่น</th><th>สถานการลงทะเบียน</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($batches as $batch): ?>
                <tr>
                    <td><?= $batch['batch_no'] ?></td>
                    <td><?= htmlspecialchars($batch['name'] ?? '') ?></td>
                    <td><?= $batch['registration_open'] ? 'เปิดลงทะเบียน' : 'ยังไม่เปิดลงทะเบียน' ?></td>
                    <td>
                        <?php if ((int) $batch['id'] === $latestBatchId): ?>
                            <form action="classes.php" method="post" class="inline-form">
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

    <form action="classes.php" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_batch">
        <label for="batch_no">รุ่นที่เปิดใหม่</label>
        <input type="number" id="batch_no" name="batch_no" required>
        <label for="name">ชื่อรุ่นที่เปิดใหม่</label>
        <input type="text" id="name" name="name" placeholder="เช่น รุ่น 7 ฉัฏฐญาณะ">
        <button type="submit">เปิดรุ่นใหม่</button>
    </form>

    <h2>ห้องเรียน</h2>
    <div class="table-wrap"><table>
        <thead>
            <tr><th>รุ่นที่</th><th>ระดับ</th><th>วันที่เริ่มเรียน</th><th>จำนวนครั้ง</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($classInstances as $class): ?>
                <tr>
                    <td><?= htmlspecialchars($class['batch_name'] ?: 'รุ่น ' . $class['batch_no']) ?></td>
                    <td><?= htmlspecialchars($class['level_name']) ?></td>
                    <td><?= $class['first_session_date'] ? htmlspecialchars(formatDateBEShort($class['first_session_date'])) : '—' ?></td>
                    <td><?= (int) $class['session_count'] ?></td>
                    <td>
                        <?php if ((int) $class['sort_order'] === $maxSortByBatch[$class['batch_id']]): ?>
                            <a href="schedule.php?class_instance_id=<?= $class['id'] ?>">จัดการตารางเรียน</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>

    <form action="classes.php" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_class_instance">
        <label for="batch_id">รุ่นที่</label>
        <select id="batch_id" name="batch_id" required>
            <?php foreach ($batches as $batch): ?>
                <option value="<?= $batch['id'] ?>"><?= htmlspecialchars($batch['name'] ?: 'รุ่น ' . $batch['batch_no']) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="class_level_id">ระดับ</label>
        <select id="class_level_id" name="class_level_id" required>
            <?php foreach ($classLevels as $level): ?>
                <option value="<?= $level['id'] ?>"><?= htmlspecialchars($level['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">สร้างห้องเรียน</button>
    </form>

</body>
</html>
