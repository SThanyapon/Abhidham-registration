<?php require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$fullName = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if ($fullName === '' || $email === '' || $phone === '') {
    header('Location: index.php?error=' . urlencode('Please fill in all required fields.'));
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: index.php?error=' . urlencode('Please enter a valid email address.'));
    exit;
}

$mysqli = getDbConnection();

$stmt = $mysqli->prepare(
    'INSERT INTO registrations (full_name, email, phone, notes) VALUES (?, ?, ?, ?)'
);
$stmt->bind_param('ssss', $fullName, $email, $phone, $notes);
$stmt->execute();
$stmt->close();
$mysqli->close();

header('Location: index.php?success=1');
exit;
