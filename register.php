<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/student_validation.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

verifyCsrf();

$ip = getClientIp();

if (!checkRateLimit('register', $ip)) {
    header('Location: index.php?error=' . urlencode('มีการลองหลายครั้งเกินไป กรุณาลองใหม่ภายหลัง'));
    exit;
}

recordRateLimitHit('register', $ip);

// Redirects back to the form with an error, keeping what the user typed (read once by index.php).
function failRegistration(string $message, array $old): void
{
    $_SESSION['register_old'] = $old;
    header('Location: index.php?error=' . urlencode($message));
    exit;
}

[$student, $validationError] = validateStudentFields($_POST);

if ($validationError !== null) {
    failRegistration($validationError, $student);
}

$mysqli = getDbConnection();

$batch = $mysqli->query(
    'SELECT id FROM batches WHERE registration_open = 1 ORDER BY id DESC LIMIT 1'
)->fetch_assoc();

if (!$batch) {
    header('Location: index.php?error=' . urlencode('ขณะนี้ปิดรับลงทะเบียน'));
    exit;
}

$stmt = $mysqli->prepare(
    'INSERT INTO students (prefix, prefix_other, full_name, age, address, phone, line_id, reference_person, batch_id, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")'
);
$stmt->bind_param(
    'sssissssi',
    $student['prefix'],
    $student['prefix_other'],
    $student['full_name'],
    $student['age'],
    $student['address'],
    $student['phone'],
    $student['line_id'],
    $student['reference_person'],
    $batch['id']
);
$stmt->execute();
$stmt->close();

header('Location: index.php?success=1');
exit;
