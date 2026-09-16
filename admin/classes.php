<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/date_helpers.php';

$adminId = requireAdminLogin();
requireFeature($adminId, 1);

$mysqli = getDbConnection();
$notice = null;
$error = null;

function dayOfWeekLabel(string $isoDate, array $dayLabels): string
{
    $date = new DateTime($isoDate);

    return $dayLabels[(int) $date->format('N')];
}

function parseDateBE(string $thaiDate): ?string
{
    if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', trim($thaiDate), $m)) {
        return null;
    }

    [, $day, $month, $buddhistYear] = $m;
    $gregorianYear = (int) $buddhistYear - 543;

    if (!checkdate((int) $month, (int) $day, $gregorianYear)) {
        return null;
    }

    return sprintf('%04d-%02d-%02d', $gregorianYear, (int) $month, (int) $day);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_batch') {
        $batchNo = (int) ($_POST['batch_no'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if ($batchNo <= 0) {
            $error = 'Batch number is required.';
        } else {
            $stmt = $mysqli->prepare('INSERT INTO batches (batch_no, name) VALUES (?, ?)');
            $stmt->bind_param('is', $batchNo, $name);
            $stmt->execute();
            $stmt->close();
            $notice = "Batch $batchNo created.";
        }
    } elseif ($action === 'toggle_registration') {
        $batchId = (int) ($_POST['batch_id'] ?? 0);
        $stmt = $mysqli->prepare('UPDATE batches SET registration_open = NOT registration_open WHERE id = ?');
        $stmt->bind_param('i', $batchId);
        $stmt->execute();
        $stmt->close();
        $notice = 'Registration status updated.';
    } elseif ($action === 'create_class_instance') {
        $batchId = (int) ($_POST['batch_id'] ?? 0);
        $classLevelId = (int) ($_POST['class_level_id'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';

        if ($batchId <= 0 || $classLevelId <= 0 || $startDate === '') {
            $error = 'Batch, class level, and start date are required.';
        } else {
            $stmt = $mysqli->prepare(
                'INSERT INTO class_instances (batch_id, class_level_id, start_date) VALUES (?, ?, ?)'
            );
            $stmt->bind_param('iis', $batchId, $classLevelId, $startDate);
            $stmt->execute();
            $stmt->close();
            $notice = 'Class created.';
        }
    } elseif ($action === 'generate_schedule') {
        $classInstanceId = (int) ($_POST['class_instance_id'] ?? 0);
        $startDate = $_POST['schedule_start_date'] ?? '';
        $numberOfSessions = (int) ($_POST['number_of_sessions'] ?? 0);
        $daysOfWeek = array_map('intval', $_POST['days_of_week'] ?? []);

        if ($classInstanceId <= 0 || $startDate === '' || $numberOfSessions <= 0 || $daysOfWeek === []) {
            $error = 'Class, start date, number of sessions, and days of week are required.';
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
            $notice = count($dates) . ' session(s) scheduled.';
        }
    } elseif ($action === 'update_session') {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $sessionDate = parseDateBE($_POST['session_date'] ?? '');
        $isCancelled = isset($_POST['is_cancelled']) ? 1 : 0;

        if ($sessionDate === null) {
            $error = 'Invalid date. Please use dd/mm/yyyy (Buddhist Era) format.';
        } else {
            $stmt = $mysqli->prepare('UPDATE sessions SET session_date = ?, is_cancelled = ? WHERE id = ?');
            $stmt->bind_param('sii', $sessionDate, $isCancelled, $sessionId);
            $stmt->execute();
            $stmt->close();
            $notice = 'Session updated.';
        }
    }
}

$batches = $mysqli->query('SELECT id, batch_no, name, registration_open FROM batches ORDER BY batch_no DESC')
    ->fetch_all(MYSQLI_ASSOC);

$classLevels = $mysqli->query('SELECT id, name, sort_order FROM class_levels ORDER BY sort_order')
    ->fetch_all(MYSQLI_ASSOC);

$classInstances = $mysqli->query(
    "SELECT ci.id, ci.start_date, cl.name AS level_name, b.batch_no, b.name AS batch_name
     FROM class_instances ci
     JOIN class_levels cl ON cl.id = ci.class_level_id
     JOIN batches b ON b.id = ci.batch_id
     ORDER BY b.batch_no DESC, cl.sort_order"
)->fetch_all(MYSQLI_ASSOC);

$selectedClassId = (int) ($_GET['class_instance_id'] ?? 0);
$sessions = [];
if ($selectedClassId > 0) {
    $stmt = $mysqli->prepare(
        'SELECT id, session_number, session_date, is_cancelled FROM sessions
         WHERE class_instance_id = ? ORDER BY session_number'
    );
    $stmt->bind_param('i', $selectedClassId);
    $stmt->execute();
    $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$dayLabels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Classes - Abhidham Registration</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <h1>Manage Classes &amp; Schedules</h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="logout.php">Log out</a>
    </nav>

    <?php if ($notice): ?><p class="success"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <h2>Batches (รุ่น)</h2>
    <table>
        <thead>
            <tr><th>รุ่น</th><th>Name</th><th>Registration</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($batches as $batch): ?>
                <tr>
                    <td><?= $batch['batch_no'] ?></td>
                    <td><?= htmlspecialchars($batch['name'] ?? '') ?></td>
                    <td><?= $batch['registration_open'] ? 'Open' : 'Closed' ?></td>
                    <td>
                        <form action="classes.php" method="post" style="display:inline; box-shadow:none; padding:0; background:none;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_registration">
                            <input type="hidden" name="batch_id" value="<?= $batch['id'] ?>">
                            <button type="submit" class="secondary"><?= $batch['registration_open'] ? 'Close' : 'Open' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <form action="classes.php" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_batch">
        <label for="batch_no">New batch number (รุ่น)</label>
        <input type="number" id="batch_no" name="batch_no" required>
        <label for="name">Name</label>
        <input type="text" id="name" name="name" placeholder="e.g. รุ่น 7 ฉัฏฐญาณะ">
        <button type="submit">Create batch</button>
    </form>

    <h2>Classes</h2>
    <table>
        <thead>
            <tr><th>รุ่น</th><th>Level</th><th>Start date</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($classInstances as $class): ?>
                <tr>
                    <td><?= htmlspecialchars($class['batch_name'] ?: 'รุ่น ' . $class['batch_no']) ?></td>
                    <td><?= htmlspecialchars($class['level_name']) ?></td>
                    <td><?= htmlspecialchars(formatDateBE($class['start_date'])) ?></td>
                    <td><a href="classes.php?class_instance_id=<?= $class['id'] ?>">Manage sessions</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <form action="classes.php" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_class_instance">
        <label for="batch_id">Batch</label>
        <select id="batch_id" name="batch_id" required>
            <?php foreach ($batches as $batch): ?>
                <option value="<?= $batch['id'] ?>"><?= htmlspecialchars($batch['name'] ?: 'รุ่น ' . $batch['batch_no']) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="class_level_id">Level</label>
        <select id="class_level_id" name="class_level_id" required>
            <?php foreach ($classLevels as $level): ?>
                <option value="<?= $level['id'] ?>"><?= htmlspecialchars($level['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="start_date">Start date</label>
        <input type="date" id="start_date" name="start_date" required>
        <button type="submit">Create class</button>
    </form>

    <?php if ($selectedClassId > 0): ?>
        <h2>Sessions</h2>

        <form action="classes.php" method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="generate_schedule">
            <input type="hidden" name="class_instance_id" value="<?= $selectedClassId ?>">

            <label for="schedule_start_date">Schedule start date</label>
            <input type="date" id="schedule_start_date" name="schedule_start_date" required>

            <label for="number_of_sessions">Number of sessions</label>
            <input type="number" id="number_of_sessions" name="number_of_sessions" min="1" required>

            <label>Days of week</label>
            <div>
                <?php foreach ($dayLabels as $num => $label): ?>
                    <label style="font-weight:normal; display:inline-block; margin-right:8px;">
                        <input type="checkbox" name="days_of_week[]" value="<?= $num ?>"> <?= $label ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <button type="submit">Generate schedule</button>
        </form>

        <?php foreach ($sessions as $session): ?>
            <form action="classes.php?class_instance_id=<?= $selectedClassId ?>" method="post"
                  style="flex-direction:row; align-items:center; gap:8px; padding:12px;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_session">
                <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
                <span>#<?= $session['session_number'] ?></span>
                <span><?= htmlspecialchars(dayOfWeekLabel($session['session_date'], $dayLabels)) ?></span>
                <input type="text" name="session_date" value="<?= htmlspecialchars(formatDateBE($session['session_date'])) ?>"
                       pattern="\d{1,2}/\d{1,2}/\d{4}" placeholder="dd/mm/yyyy (พ.ศ.)" required>
                <label style="font-weight:normal;">
                    <input type="checkbox" name="is_cancelled" <?= $session['is_cancelled'] ? 'checked' : '' ?>> Cancelled
                </label>
                <button type="submit" class="secondary">Save</button>
            </form>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
