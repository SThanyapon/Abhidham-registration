<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/date_helpers.php';

$adminId = requireAdminLogin();
requireFeature($adminId, 1);

$mysqli = getDbConnection();
$error = null;

$batches = $mysqli->query('SELECT id, batch_no, name FROM batches ORDER BY batch_no DESC')
    ->fetch_all(MYSQLI_ASSOC);

// Optional ?batch_id= filter (the ห้องเรียน link on batches.php); an unknown ID means no filter.
$filterBatch = null;
$requestedBatchId = (int) ($_GET['batch_id'] ?? 0);
foreach ($batches as $batch) {
    if ((int) $batch['id'] === $requestedBatchId) {
        $filterBatch = $batch;
        break;
    }
}
$filterBatchId = $filterBatch ? (int) $filterBatch['id'] : null;
$selfUrl = 'classes.php' . ($filterBatchId ? '?batch_id=' . $filterBatchId : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $notice = null;

    if ($action === 'create_class_instance') {
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
    } elseif ($action === 'set_closed') {
        $classInstanceId = (int) ($_POST['class_instance_id'] ?? 0);
        // The target state is posted explicitly, so resubmitting the form can't flip it back.
        $isClosed = ($_POST['is_closed'] ?? '') === '1' ? 1 : 0;

        $stmt = $mysqli->prepare('UPDATE class_instances SET is_closed = ? WHERE id = ?');
        $stmt->bind_param('ii', $isClosed, $classInstanceId);
        $stmt->execute();
        $stmt->close();
        $notice = $isClosed
            ? 'ปิดห้องเรียนแล้ว นักศึกษาจะลงชื่อเข้าเรียนห้องนี้ไม่ได้'
            : 'เปิดห้องเรียนแล้ว';
    }

    // Post/Redirect/Get, so refreshing the page can't repeat the action.
    if ($notice !== null) {
        $_SESSION['classes_notice'] = $notice;
        header('Location: ' . $selfUrl);
        exit;
    }
}

$notice = $_SESSION['classes_notice'] ?? null;
unset($_SESSION['classes_notice']);

$classLevels = $mysqli->query('SELECT id, name, sort_order FROM class_levels ORDER BY sort_order')
    ->fetch_all(MYSQLI_ASSOC);

$classSql =
    "SELECT ci.id, ci.batch_id, ci.is_closed, cl.name AS level_name, cl.sort_order, b.batch_no, b.name AS batch_name,
            (SELECT COUNT(*) FROM sessions s WHERE s.class_instance_id = ci.id AND s.is_cancelled = 0) AS session_count,
            (SELECT MIN(s.session_date) FROM sessions s WHERE s.class_instance_id = ci.id AND s.is_cancelled = 0) AS first_session_date
     FROM class_instances ci
     JOIN class_levels cl ON cl.id = ci.class_level_id
     JOIN batches b ON b.id = ci.batch_id"
    . ($filterBatchId ? ' WHERE ci.batch_id = ?' : '')
    . ' ORDER BY b.batch_no DESC, cl.sort_order';
$stmt = $mysqli->prepare($classSql);
if ($filterBatchId) {
    $stmt->bind_param('i', $filterBatchId);
}
$stmt->execute();
$classInstances = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Schedule management is linked only for each batch's highest class level.
$maxSortByBatch = [];
foreach ($classInstances as $class) {
    $maxSortByBatch[$class['batch_id']] = max($maxSortByBatch[$class['batch_id']] ?? 0, (int) $class['sort_order']);
}

// Works for both a batches row (name) and a class row (batch_name).
$batchLabel = fn (array $batch): string => ($batch['batch_name'] ?? $batch['name'] ?? '') ?: 'รุ่น ' . $batch['batch_no'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap">
    <title>จัดการห้องเรียน - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body class="admin">
    <h1>จัดการห้องเรียน</h1>
    <nav>
        <a href="batches.php">กลับหน้าจัดการรุ่น</a>
        <a href="dashboard.php">กลับหน้าแผงควบคุม</a>
        <form class="nav-logout" action="logout.php" method="post"><?= csrfField() ?><button type="submit">ออกจากระบบ</button></form>
    </nav>

    <?php if ($notice): ?><p class="success"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <h2><?= $filterBatch ? 'ห้องเรียนของ ' . htmlspecialchars($batchLabel($filterBatch)) : 'ห้องเรียนทั้งหมด' ?></h2>
    <?php if ($filterBatch): ?>
        <p><a href="classes.php">แสดงห้องเรียนทุกรุ่น</a></p>
    <?php endif; ?>
    <p class="form-note">เมื่อเรียนจบระดับแล้ว ให้ปิดห้องเรียน นักศึกษาจะลงชื่อเข้าเรียนห้องนั้นไม่ได้ (ยังดูความก้าวหน้าได้) และตารางเรียนจะแก้ไขไม่ได้</p>

    <?php if ($classInstances === []): ?>
        <p>ยังไม่มีห้องเรียน</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead>
                <tr><th>รุ่นที่</th><th>ระดับ</th><th>วันที่เริ่มเรียน</th><th>จำนวนครั้ง</th><th>สถานะ</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($classInstances as $class): ?>
                    <tr>
                        <td><?= htmlspecialchars($batchLabel($class)) ?></td>
                        <td><?= htmlspecialchars($class['level_name']) ?></td>
                        <td><?= $class['first_session_date'] ? htmlspecialchars(formatDateBEShort($class['first_session_date'])) : '—' ?></td>
                        <td><?= (int) $class['session_count'] ?></td>
                        <td><?= $class['is_closed'] ? 'ปิดแล้ว' : 'เปิด' ?></td>
                        <td>
                            <div class="action-row">
                                <?php if ((int) $class['sort_order'] === $maxSortByBatch[$class['batch_id']]): ?>
                                    <a href="schedule.php?class_instance_id=<?= $class['id'] ?>">จัดการตารางเรียน</a>
                                <?php endif; ?>
                                <form action="<?= htmlspecialchars($selfUrl) ?>" method="post" class="inline-form"
                                      <?= $class['is_closed'] ? '' : 'onsubmit="return confirm(\'ปิดห้องเรียนนี้? นักศึกษาจะลงชื่อเข้าเรียนไม่ได้\');"' ?>>
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="set_closed">
                                    <input type="hidden" name="class_instance_id" value="<?= $class['id'] ?>">
                                    <?php if ($class['is_closed']): ?>
                                        <input type="hidden" name="is_closed" value="0">
                                        <button type="submit" class="secondary">เปิดห้องเรียน</button>
                                    <?php else: ?>
                                        <input type="hidden" name="is_closed" value="1">
                                        <button type="submit" class="danger">ปิดห้องเรียน</button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>

    <h2>สร้างห้องเรียน</h2>
    <form action="<?= htmlspecialchars($selfUrl) ?>" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_class_instance">
        <label for="batch_id">รุ่นที่</label>
        <select id="batch_id" name="batch_id" required>
            <?php foreach ($batches as $batch): ?>
                <option value="<?= $batch['id'] ?>"<?= (int) $batch['id'] === $filterBatchId ? ' selected' : '' ?>><?= htmlspecialchars($batchLabel($batch)) ?></option>
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
