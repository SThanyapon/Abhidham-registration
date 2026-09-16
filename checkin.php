<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/attendance.php';

$mysqli = getDbConnection();
$error = null;
$attendance = null;
$selectedClassInstanceId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $ip = getClientIp();

    if (!checkRateLimit('checkin', $ip)) {
        $error = 'Too many attempts. Please try again later.';
    } else {
        recordRateLimitHit('checkin', $ip);

        $fullName = trim($_POST['full_name'] ?? '');
        $studentNo = trim($_POST['student_no'] ?? '');
        $classInstanceId = (int) ($_POST['class_instance_id'] ?? 0);
        $sessionId = (int) ($_POST['session_id'] ?? 0);

        $stmt = $mysqli->prepare(
            'SELECT s.id, ci.id AS class_instance_id
             FROM students s
             JOIN class_instances ci ON ci.batch_id = s.batch_id AND ci.class_level_id = s.current_class_level_id
             WHERE s.student_no = ? AND s.full_name = ? AND s.status = "approved" AND ci.id = ?'
        );
        $stmt->bind_param('ssi', $studentNo, $fullName, $classInstanceId);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$student) {
            $error = 'Name, Student ID, or Class does not match our records.';
        } else {
            $sessionStmt = $mysqli->prepare(
                'SELECT id FROM sessions WHERE id = ? AND class_instance_id = ? AND is_cancelled = 0'
            );
            $sessionStmt->bind_param('ii', $sessionId, $classInstanceId);
            $sessionStmt->execute();
            $session = $sessionStmt->get_result()->fetch_assoc();
            $sessionStmt->close();

            if (!$session) {
                $error = 'Invalid session selected.';
            } else {
                $dupStmt = $mysqli->prepare(
                    'SELECT id FROM checkins WHERE student_id = ? AND session_id = ?'
                );
                $dupStmt->bind_param('ii', $student['id'], $sessionId);
                $dupStmt->execute();
                $already = $dupStmt->get_result()->fetch_assoc();
                $dupStmt->close();

                if ($already) {
                    $error = 'You have already checked in for this session.';
                } else {
                    $insert = $mysqli->prepare(
                        'INSERT INTO checkins (student_id, session_id) VALUES (?, ?)'
                    );
                    $insert->bind_param('ii', $student['id'], $sessionId);
                    $insert->execute();
                    $insert->close();
                }
            }

            $selectedClassInstanceId = $classInstanceId;
            $attendance = getAttendanceSummary($mysqli, $classInstanceId, $student['id']);
        }
    }
}

$classes = $mysqli->query(
    "SELECT ci.id, cl.name AS level_name, b.batch_no, b.name AS batch_name
     FROM class_instances ci
     JOIN class_levels cl ON cl.id = ci.class_level_id
     JOIN batches b ON b.id = ci.batch_id
     ORDER BY b.batch_no DESC, cl.sort_order"
)->fetch_all(MYSQLI_ASSOC);

$sessionsByClass = [];
foreach ($classes as $class) {
    $sessionStmt = $mysqli->prepare(
        'SELECT id, session_number, session_date FROM sessions
         WHERE class_instance_id = ? AND is_cancelled = 0 ORDER BY session_number'
    );
    $sessionStmt->bind_param('i', $class['id']);
    $sessionStmt->execute();
    $sessionsByClass[$class['id']] = $sessionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sessionStmt->close();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Check-in - Abhidham Registration</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <h1>Class Check-in</h1>
    <nav>
        <a href="index.php">Register</a>
        <a href="checkin.php">Check-in</a>
        <a href="lookup.php">Find my Student ID</a>
        <a href="admin/login.php">Admin</a>
    </nav>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form action="checkin.php" method="post">
        <?= csrfField() ?>

        <label for="full_name">Name-Surname (Thai)</label>
        <input type="text" id="full_name" name="full_name" required>

        <label for="student_no">Student ID</label>
        <input type="text" id="student_no" name="student_no" required>

        <label for="class_instance_id">Class</label>
        <select id="class_instance_id" name="class_instance_id" required onchange="updateSessions()">
            <option value="">-- Select class --</option>
            <?php foreach ($classes as $class): ?>
                <option value="<?= $class['id'] ?>" <?= $selectedClassInstanceId === (int) $class['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars(($class['batch_name'] ?: 'รุ่น ' . $class['batch_no']) . ' - ' . $class['level_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="session_id">Session</label>
        <select id="session_id" name="session_id" required>
            <option value="">-- Select class first --</option>
        </select>

        <button type="submit">Check-in</button>
    </form>

    <?php if ($attendance): ?>
        <div class="card">
            <p class="progress">
                Progress: <?= $attendance['completed_count'] ?> / <?= $attendance['conducted_count'] ?>
                (<?= $attendance['percent'] ?>%)
            </p>
            <div class="session-grid">
                <?php foreach ($attendance['sessions'] as $session): ?>
                    <div class="session-box <?= $session['checked_in'] ? 'checked' : 'missing' ?>"
                         title="<?= htmlspecialchars($session['session_date']) ?>">
                        <?= $session['session_number'] ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <script>
        const SESSIONS_BY_CLASS = <?= json_encode($sessionsByClass, JSON_UNESCAPED_UNICODE) ?>;
        const preselectedClass = <?= json_encode($selectedClassInstanceId) ?>;

        function updateSessions() {
            const classSelect = document.getElementById('class_instance_id');
            const sessionSelect = document.getElementById('session_id');
            const sessions = SESSIONS_BY_CLASS[classSelect.value] || [];

            sessionSelect.innerHTML = '<option value="">-- Select session --</option>';
            sessions.forEach(function (session) {
                const option = document.createElement('option');
                option.value = session.id;
                option.textContent = 'Session ' + session.session_number + ' (' + session.session_date + ')';
                sessionSelect.appendChild(option);
            });
        }

        if (preselectedClass) {
            updateSessions();
        }
    </script>
</body>
</html>
