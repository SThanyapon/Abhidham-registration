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

// Redirects back to the form with an error, keeping what the user typed (both read once by index.php).
// The message travels in the session, not the URL, so nobody can link to the site showing their own text.
function failRegistration(string $message, array $old = []): void
{
    $_SESSION['register_old'] = $old;
    $_SESSION['register_error'] = $message;
    header('Location: index.php?register=1');
    exit;
}

$ip = getClientIp();

if (!checkRateLimit('register', $ip)) {
    failRegistration('มีการลองหลายครั้งเกินไป กรุณาลองใหม่ภายหลัง');
}

recordRateLimitHit('register', $ip);

[$student, $validationError] = validateStudentFields($_POST, true);

if ($validationError !== null) {
    failRegistration($validationError, $student);
}

$mysqli = getDbConnection();

$batch = $mysqli->query(
    'SELECT id FROM batches WHERE registration_open = 1 ORDER BY id DESC LIMIT 1'
)->fetch_assoc();

if (!$batch) {
    failRegistration('ขณะนี้ปิดรับลงทะเบียน', $student);
}

[$types, $values] = studentDetailParams($student);
$stmt = $mysqli->prepare(
    'INSERT INTO students (' . implode(', ', STUDENT_DETAIL_COLUMNS) . ', batch_id, status)
     VALUES (' . str_repeat('?, ', count(STUDENT_DETAIL_COLUMNS)) . '?, "pending")'
);
$values[] = $batch['id'];
$stmt->bind_param($types . 'i', ...$values);
$stmt->execute();
$stmt->close();

header('Location: index.php?success=1');
exit;
