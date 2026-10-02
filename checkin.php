<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/input.php';
require_once __DIR__ . '/includes/attendance.php';
require_once __DIR__ . '/includes/date_helpers.php';
require_once __DIR__ . '/includes/student_helpers.php';

$mysqli = getDbConnection();
$error = null;
$notice = null;
$success = null;
$student = null;
$attendance = null;
$fullName = '';
$studentNo = '';
$selectedClassInstanceId = null;
// Sessions dated after today can't be checked in to yet.
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $ip = getClientIp();

    if (!checkRateLimit('checkin', $ip)) {
        $error = 'มีการลองหลายครั้งเกินไป กรุณาลองใหม่ภายหลัง';
    } else {
        recordRateLimitHit('checkin', $ip);

        $fullName = cleanText($_POST['full_name'] ?? '');
        $studentNo = cleanCode($_POST['student_no'] ?? '');
        $classInstanceId = (int) ($_POST['class_instance_id'] ?? 0);
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $selectedClassInstanceId = $classInstanceId > 0 ? $classInstanceId : null;

        $missing = [];
        if ($studentNo === '') {
            $missing[] = 'รหัสนักศึกษา';
        }
        if ($fullName === '') {
            $missing[] = 'ชื่อ-นามสกุล';
        }

        if ($missing !== []) {
            $error = 'กรุณากรอก: ' . implode(', ', $missing);
        } else {
            $stmt = $mysqli->prepare(
                'SELECT id, prefix, prefix_other, full_name, student_no
                 FROM students
                 WHERE student_no = ? AND full_name = ? AND status = "approved"'
            );
            $stmt->bind_param('ss', $studentNo, $fullName);
            $stmt->execute();
            $student = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$student) {
                $error = 'รหัสนักศึกษา หรือชื่อ-นามสกุลไม่ตรงกับข้อมูลในระบบ';
            } else {
                $currentClassId = studentClassInstanceId($mysqli, (int) $student['id']);

                if ($currentClassId === null) {
                    $notice = 'ยังไม่มีตารางเรียนสำหรับชั้นเรียนปัจจุบันของท่าน';
                } elseif ($classInstanceId > 0 && $sessionId > 0) {
                    // Check-in mode: both class and session chosen.
                    if ($classInstanceId !== $currentClassId) {
                        $error = 'ชั้นเรียนที่เลือกไม่ตรงกับชั้นเรียนปัจจุบันของท่าน';
                    } else {
                        $sessionStmt = $mysqli->prepare(
                            'SELECT id FROM sessions
                             WHERE id = ? AND class_instance_id = ? AND is_cancelled = 0 AND session_date <= ?'
                        );
                        $sessionStmt->bind_param('iis', $sessionId, $classInstanceId, $today);
                        $sessionStmt->execute();
                        $session = $sessionStmt->get_result()->fetch_assoc();
                        $sessionStmt->close();

                        if (!$session) {
                            $error = 'ครั้งที่เรียนที่เลือกไม่ถูกต้อง';
                        } else {
                            $dupStmt = $mysqli->prepare(
                                'SELECT id FROM checkins WHERE student_id = ? AND session_id = ?'
                            );
                            $dupStmt->bind_param('ii', $student['id'], $sessionId);
                            $dupStmt->execute();
                            $already = $dupStmt->get_result()->fetch_assoc();
                            $dupStmt->close();

                            if ($already) {
                                $error = 'คุณได้ลงชื่อเข้าเรียนครั้งนี้แล้ว';
                            } else {
                                $insert = $mysqli->prepare(
                                    'INSERT INTO checkins (student_id, session_id) VALUES (?, ?)'
                                );
                                $insert->bind_param('ii', $student['id'], $sessionId);
                                $insert->execute();
                                $insert->close();
                                $success = 'ลงชื่อเข้าเรียนเรียบร้อยแล้ว';
                            }
                        }
                    }
                } else {
                    // View-only mode: class or session not chosen, so nothing is written.
                    $notice = 'แสดงความก้าวหน้าการเข้าเรียน (ยังไม่ได้บันทึกการลงชื่อเข้าเรียน — '
                        . 'หากต้องการลงชื่อ กรุณาเลือกชั้นเรียนและครั้งที่)';
                }

                if ($currentClassId !== null) {
                    $attendance = getAttendanceSummary($mysqli, $currentClassId, (int) $student['id']);
                }
            }
        }
    }
}

// Only each batch's highest class level (its current class) is offered for check-in.
$classes = $mysqli->query(
    "SELECT ci.id, cl.name AS level_name, b.batch_no, b.name AS batch_name
     FROM class_instances ci
     JOIN class_levels cl ON cl.id = ci.class_level_id
     JOIN batches b ON b.id = ci.batch_id
     WHERE cl.sort_order = (
         SELECT MAX(cl2.sort_order)
         FROM class_instances ci2
         JOIN class_levels cl2 ON cl2.id = ci2.class_level_id
         WHERE ci2.batch_id = ci.batch_id
     )
     ORDER BY b.batch_no DESC"
)->fetch_all(MYSQLI_ASSOC);

$sessionsByClass = [];
foreach ($classes as $class) {
    $sessionStmt = $mysqli->prepare(
        'SELECT id, session_number, session_date FROM sessions
         WHERE class_instance_id = ? AND is_cancelled = 0 AND session_date <= ? ORDER BY session_number'
    );
    $sessionStmt->bind_param('is', $class['id'], $today);
    $sessionStmt->execute();
    $sessions = $sessionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sessionStmt->close();

    foreach ($sessions as &$session) {
        $session['session_date_be'] = formatDateBEShort($session['session_date']);
    }
    unset($session);

    $sessionsByClass[$class['id']] = $sessions;
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
    <title>ลงชื่อ/ตรวจสอบการเข้าเรียน - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <h1>ลงชื่อ/ตรวจสอบการเข้าเรียน</h1>
    <nav>
        <a href="index.php">ลงทะเบียน</a>
        <a href="checkin.php">ลงชื่อ/ตรวจสอบการเข้าเรียน</a>
        <a href="lookup.php">ค้นหารหัสนักศึกษา</a>
        <a class="nav-admin" href="admin/login.php">ผู้ดูแลระบบ</a>
    </nav>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <?php if ($success): ?>
        <p class="success"><?= htmlspecialchars($success) ?></p>
    <?php endif; ?>
    <?php if ($notice): ?>
        <p class="notice"><?= htmlspecialchars($notice) ?></p>
    <?php endif; ?>

    <form action="checkin.php" method="post">
        <?= csrfField() ?>

        <p class="form-note"><span class="required-mark">*</span> จำเป็นต้องกรอก ·
            หากไม่เลือกชั้นเรียนและครั้งที่ ระบบจะแสดงเฉพาะความก้าวหน้าการเข้าเรียน</p>

        <label for="student_no">รหัสนักศึกษา<span class="required-mark">*</span></label>
        <input type="text" id="student_no" name="student_no" required value="<?= htmlspecialchars($studentNo) ?>">

        <label for="full_name">ชื่อ-นามสกุล (ภาษาไทย)<span class="required-mark">*</span></label>
        <input type="text" id="full_name" name="full_name" required value="<?= htmlspecialchars($fullName) ?>">

        <label for="class_instance_id">ชั้นเรียน</label>
        <select id="class_instance_id" name="class_instance_id" onchange="updateSessions()">
            <option value="">-- เลือกชั้นเรียน --</option>
            <?php foreach ($classes as $class): ?>
                <option value="<?= $class['id'] ?>" <?= $selectedClassInstanceId === (int) $class['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars(($class['batch_name'] ?: 'รุ่น ' . $class['batch_no']) . ' - ' . $class['level_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="session_id">ครั้งที่</label>
        <select id="session_id" name="session_id">
            <option value="">-- เลือกชั้นเรียนก่อน --</option>
        </select>

        <button type="submit">ลงชื่อ/ตรวจสอบการเข้าเรียน</button>
    </form>

    <?php if ($student && $attendance): ?>
        <div class="card">
            <p>
                รหัสนักศึกษา: <strong><?= htmlspecialchars($student['student_no']) ?></strong><br>
                คำนำหน้า: <strong><?= htmlspecialchars(studentPrefix($student)) ?></strong><br>
                ชื่อ-นามสกุล: <strong><?= htmlspecialchars($student['full_name']) ?></strong>
            </p>
            <p class="progress">
                ความก้าวหน้า: <?= $attendance['completed_count'] ?> / <?= $attendance['conducted_count'] ?>
                (<?= $attendance['percent'] ?>%)
            </p>
            <div class="session-grid">
                <?php foreach ($attendance['sessions'] as $session): ?>
                    <div class="session-box <?= $session['checked_in'] ? 'checked' : 'missing' ?>"
                         title="<?= htmlspecialchars(formatDateBEShort($session['session_date'])) ?>">
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

            sessionSelect.innerHTML = classSelect.value
                ? '<option value="">-- เลือกครั้งที่ --</option>'
                : '<option value="">-- เลือกชั้นเรียนก่อน --</option>';
            sessions.forEach(function (session) {
                const option = document.createElement('option');
                option.value = session.id;
                option.textContent = 'ครั้งที่ ' + session.session_number + ' (' + session.session_date_be + ')';
                sessionSelect.appendChild(option);
            });
        }

        if (preselectedClass) {
            updateSessions();
        }
    </script>
</body>
</html>
