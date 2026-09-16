<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

verifyCsrf();

$ip = getClientIp();

if (!checkRateLimit('register', $ip)) {
    header('Location: index.php?error=' . urlencode('Too many attempts. Please try again later.'));
    exit;
}

recordRateLimitHit('register', $ip);

$prefix = trim($_POST['prefix'] ?? '');
$prefixOther = trim($_POST['prefix_other'] ?? '');
$fullName = trim($_POST['full_name'] ?? '');
$age = trim($_POST['age'] ?? '');
$address = trim($_POST['address'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$lineId = trim($_POST['line_id'] ?? '');
$referencePerson = trim($_POST['reference_person'] ?? '');

$allowedPrefixes = ['พระ', 'สิกขามานา', 'สามเณร', 'สามเณรี', 'แม่ชี', 'นาย', 'นาง', 'นางสาว', 'อื่นๆ'];

if (!in_array($prefix, $allowedPrefixes, true) || $fullName === '' || $phone === '') {
    header('Location: index.php?error=' . urlencode('Please fill in all required fields.'));
    exit;
}

if ($prefix === 'อื่นๆ' && $prefixOther === '') {
    header('Location: index.php?error=' . urlencode('Please specify the prefix.'));
    exit;
}

$mysqli = getDbConnection();

$batch = $mysqli->query(
    'SELECT id FROM batches WHERE registration_open = 1 ORDER BY id DESC LIMIT 1'
)->fetch_assoc();

if (!$batch) {
    header('Location: index.php?error=' . urlencode('Registration is currently closed.'));
    exit;
}

$stmt = $mysqli->prepare(
    'INSERT INTO students (prefix, prefix_other, full_name, age, address, phone, line_id, reference_person, batch_id, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")'
);
$ageValue = $age === '' ? null : (int) $age;
$prefixOtherValue = $prefix === 'อื่นๆ' ? $prefixOther : null;
$stmt->bind_param(
    'sssissssi',
    $prefix,
    $prefixOtherValue,
    $fullName,
    $ageValue,
    $address,
    $phone,
    $lineId,
    $referencePerson,
    $batch['id']
);
$stmt->execute();
$stmt->close();

header('Location: index.php?success=1');
exit;
