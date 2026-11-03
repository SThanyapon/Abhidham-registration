<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/date_helpers.php';
require_once __DIR__ . '/../includes/attendance.php';

$adminId = requireAdminLogin();
requireFeature($adminId, 1);

$mysqli = getDbConnection();
$error = null;

function dayOfWeekLabel(string $isoDate, array $dayLabels): string
{
    $date = new DateTime($isoDate);

    return $dayLabels[(int) $date->format('N')];
}

function generateScheduleDates(string $startDate, array $daysOfWeek, int $numberOfSessions): array
{
    $dates = [];
    $current = new DateTime($startDate);

    while (count($dates) < $numberOfSessions) {
        if (in_array((int) $current->format('N'), $daysOfWeek, true)) {
            $dates[] = $current->format('Y-m-d');
        }
        $current->modify('+1 day');
    }

    return $dates;
}

$dayLabels = [
    1 => 'จันทร์', 2 => 'อังคาร', 3 => 'พุธ', 4 => 'พฤหัสบดี',
    5 => 'ศุกร์', 6 => 'เสาร์', 7 => 'อาทิตย์',
];

$classInstanceId = (int) ($_GET['class_instance_id'] ?? 0);

$stmt = $mysqli->prepare(
    'SELECT ci.id, ci.batch_id, cl.name AS level_name, cl.sort_order,
            b.batch_no, b.name AS batch_name
     FROM class_instances ci
     JOIN class_levels cl ON cl.id = ci.class_level_id
     JOIN batches b ON b.id = ci.batch_id
     WHERE ci.id = ?'
);
$stmt->bind_param('i', $classInstanceId);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$class) {
    header('Location: classes.php');
    exit;
}

// Only a batch's current class (highest class level) may have its schedule managed.
$stmt = $mysqli->prepare(
    'SELECT MAX(cl.sort_order) AS max_sort
     FROM class_instances ci
     JOIN class_levels cl ON cl.id = ci.class_level_id
     WHERE ci.batch_id = ?'
);
$stmt->bind_param('i', $class['batch_id']);
$stmt->execute();
$isCurrentClass = (int) $stmt->get_result()->fetch_assoc()['max_sort'] === (int) $class['sort_order'];
$stmt->close();

$selfUrl = 'schedule.php?class_instance_id=' . $classInstanceId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $notice = null;

    if (!$isCurrentClass) {
        $error = 'จัดการตารางเรียนได้เฉพาะชั้นเรียนปัจจุบันของรุ่นเท่านั้น';
    } elseif ($action === 'generate_schedule') {
        $startDate = $_POST['schedule_start_date'] ?? '';
        $numberOfSessions = (int) ($_POST['number_of_sessions'] ?? 0);
        $daysOfWeek = array_values(array_intersect(
            array_map('intval', $_POST['days_of_week'] ?? []),
            array_keys($dayLabels)
        ));

        if ($startDate === '' || $numberOfSessions <= 0 || $daysOfWeek === []) {
            $error = 'กรุณาระบุวันที่เริ่มเรียน จำนวนครั้ง และวันเรียน';
        } else {
            $existingMax = $mysqli->prepare(
                'SELECT COALESCE(MAX(session_number), 0) AS max_num FROM sessions WHERE class_instance_id = ?'
            );
            $existingMax->bind_param('i', $classInstanceId);
            $existingMax->execute();
            $startNumber = (int) $existingMax->get_result()->fetch_assoc()['max_num'] + 1;
            $existingMax->close();

            $dates = generateScheduleDates($startDate, $daysOfWeek, $numberOfSessions);

            $insert = $mysqli->prepare(
                'INSERT INTO sessions (class_instance_id, session_number, session_date) VALUES (?, ?, ?)'
            );
            foreach ($dates as $i => $date) {
                $sessionNumber = $startNumber + $i;
                $insert->bind_param('iis', $classInstanceId, $sessionNumber, $date);
                $insert->execute();
            }
            $insert->close();
            $notice = 'สร้างตารางเรียนแล้ว ' . count($dates) . ' ครั้ง';
        }
    } elseif ($action === 'update_session') {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $isCancelled = isset($_POST['is_cancelled']) ? 1 : 0;

        $stmt = $mysqli->prepare('UPDATE sessions SET is_cancelled = ? WHERE id = ? AND class_instance_id = ?');
        $stmt->bind_param('iii', $isCancelled, $sessionId, $classInstanceId);
        $stmt->execute();
        $stmt->close();
        $notice = 'บันทึกตารางเรียนเรียบร้อยแล้ว';
    }

    // Post/Redirect/Get, so refreshing the page can't generate the schedule twice.
    if ($notice !== null) {
        $_SESSION['schedule_notice'] = $notice;
        header('Location: ' . $selfUrl);
        exit;
    }
}

$notice = $_SESSION['schedule_notice'] ?? null;
unset($_SESSION['schedule_notice']);

$stmt = $mysqli->prepare(
    'SELECT id, session_number, session_date, is_cancelled FROM sessions
     WHERE class_instance_id = ? ORDER BY session_number'
);
$stmt->bind_param('i', $classInstanceId);
$stmt->execute();
$sessions = addDisplayNumbers($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
$stmt->close();

$cancelledCount = count(array_filter($sessions, fn ($s) => (int) $s['is_cancelled'] === 1));

// The class start date is its first non-cancelled session (sessions are in session_number order).
$firstSessionDate = null;
foreach ($sessions as $session) {
    if ($session['display_number'] !== null) {
        $firstSessionDate = $session['session_date'];
        break;
    }
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
    <title>จัดการตารางเรียน - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body class="admin">
    <h1>จัดการตารางเรียน</h1>
    <nav>
        <a href="classes.php">กลับหน้าจัดการชั้นเรียน</a>
        <a href="dashboard.php">กลับหน้าแผงควบคุม</a>
        <form class="nav-logout" action="logout.php" method="post"><?= csrfField() ?><button type="submit">ออกจากระบบ</button></form>
    </nav>

    <div class="card">
        <p><strong>รุ่น:</strong> <?= htmlspecialchars($class['batch_name'] ?: 'รุ่น ' . $class['batch_no']) ?></p>
        <p><strong>ระดับ:</strong> <?= htmlspecialchars($class['level_name']) ?></p>
        <p><strong>วันที่เริ่มเรียน:</strong> <?= $firstSessionDate ? htmlspecialchars(formatDateBEShort($firstSessionDate)) : 'ยังไม่มีตารางเรียน' ?></p>
        <p><strong>จำนวนครั้งทั้งหมด:</strong> <?= count($sessions) - $cancelledCount ?> ครั้ง<?= $cancelledCount > 0 ? ' (ยกเลิก ' . $cancelledCount . ' ครั้ง)' : '' ?></p>
    </div>

    <?php if ($notice): ?><p class="success"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <?php if (!$isCurrentClass): ?>
        <p class="notice">ชั้นเรียนนี้ไม่ใช่ชั้นเรียนปัจจุบันของรุ่น จึงดูตารางเรียนได้อย่างเดียว</p>
    <?php else: ?>
        <h2>สร้างตารางเรียน</h2>
        <form action="<?= htmlspecialchars($selfUrl) ?>" method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="generate_schedule">

            <label for="schedule_start_date">วันที่เริ่มเรียน</label>
            <input type="date" id="schedule_start_date" name="schedule_start_date" required>

            <label for="number_of_sessions">จำนวนครั้งที่เรียน</label>
            <input type="number" id="number_of_sessions" name="number_of_sessions" min="1" required>

            <label>วันเรียน</label>
            <div>
                <?php foreach ($dayLabels as $num => $label): ?>
                    <label class="checkbox-label">
                        <input type="checkbox" name="days_of_week[]" value="<?= $num ?>"> <?= $label ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <button type="submit">สร้างตารางเรียน</button>
        </form>
    <?php endif; ?>

    <h2>ตารางเรียน</h2>
    <?php if ($sessions === []): ?>
        <p>ยังไม่มีตารางเรียน</p>
    <?php else: ?>
        <div class="session-table">
            <div class="session-table-header">
                <span>ครั้งที่</span>
                <span>วัน</span>
                <span>วันที่</span>
                <span>ยกเลิกตารางเรียน</span>
                <span>บันทึก</span>
            </div>
            <?php foreach ($sessions as $session): ?>
                <form action="<?= htmlspecialchars($selfUrl) ?>" method="post"
                      class="session-row <?= $session['is_cancelled'] ? 'session-cancelled' : '' ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_session">
                    <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
                    <span><?= $session['display_number'] === null ? '—' : '#' . $session['display_number'] ?></span>
                    <span><?= htmlspecialchars(dayOfWeekLabel($session['session_date'], $dayLabels)) ?></span>
                    <span><?= htmlspecialchars(formatDateBEShort($session['session_date'])) ?></span>
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_cancelled" <?= $session['is_cancelled'] ? 'checked' : '' ?><?= $isCurrentClass ? '' : ' disabled' ?>> ยกเลิกตารางเรียน
                    </label>
                    <button type="submit" class="secondary"<?= $isCurrentClass ? '' : ' disabled' ?>>บันทึก</button>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</body>
</html>
