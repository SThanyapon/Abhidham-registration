<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/input.php';

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

$prefix = trim($_POST['prefix'] ?? '');
$prefixOther = cleanText($_POST['prefix_other'] ?? '');
$fullName = cleanText($_POST['full_name'] ?? '');
$age = trim($_POST['age'] ?? '');
$address = cleanMultilineText($_POST['address'] ?? '');
$phone = cleanText($_POST['phone'] ?? '');
$lineId = cleanText($_POST['line_id'] ?? '');
$referencePerson = cleanText($_POST['reference_person'] ?? '');

$allowedPrefixes = ['พระ', 'สิกขามานา', 'สามเณร', 'สามเณรี', 'แม่ชี', 'นาย', 'นาง', 'นางสาว', 'อื่นๆ'];

// Redirects back to the form with an error, keeping what the user typed (read once by index.php).
function failRegistration(string $message, array $old): void
{
    $_SESSION['register_old'] = $old;
    header('Location: index.php?error=' . urlencode($message));
    exit;
}

$old = [
    'prefix' => $prefix,
    'prefix_other' => $prefixOther,
    'full_name' => $fullName,
    'age' => $age,
    'address' => $address,
    'phone' => $phone,
    'line_id' => $lineId,
    'reference_person' => $referencePerson,
];

$missing = [];
if (!in_array($prefix, $allowedPrefixes, true)) {
    $missing[] = 'คำนำหน้า';
} elseif ($prefix === 'อื่นๆ' && $prefixOther === '') {
    $missing[] = 'ระบุคำนำหน้า';
}
if ($fullName === '') {
    $missing[] = 'ชื่อ-นามสกุล';
}
if ($age === '') {
    $missing[] = 'อายุ';
}
if ($address === '') {
    $missing[] = 'ที่อยู่';
}
if ($phone === '') {
    $missing[] = 'เบอร์โทรศัพท์';
}

if ($missing !== []) {
    failRegistration('กรุณากรอกข้อมูลให้ครบถ้วน: ' . implode(', ', $missing), $old);
}

if (!isValidPersonName($fullName)) {
    failRegistration('ชื่อ-นามสกุล ใช้ได้เฉพาะอักษรไทย อักษรอังกฤษ ตัวเลข ขีด (-) และช่องว่างเท่านั้น', $old);
}

if (!ctype_digit($age) || (int) $age < 1 || (int) $age > 120) {
    failRegistration('กรุณากรอกอายุเป็นตัวเลข 1-120', $old);
}

// Digits, spaces, '-' and '+' only, with 9-15 digits (Thai landline/mobile, or +66...).
$phoneDigits = preg_replace('/\D/', '', $phone);
if (!preg_match('/^\+?[0-9 \-]+$/', $phone) || strlen($phoneDigits) < 9 || strlen($phoneDigits) > 15) {
    failRegistration('กรุณากรอกเบอร์โทรศัพท์ให้ถูกต้อง', $old);
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
