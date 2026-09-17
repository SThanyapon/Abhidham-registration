<?php

require_once __DIR__ . '/../includes/db.php';

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

[, $username, $email, $password, $featuresArg] = array_pad($argv, 5, null);

if (!$username || !$email || !$password) {
    die(
        "Usage: php scripts/create_admin.php <username> <email> <password> [feature_numbers_comma_separated]\n"
        . "Example: php scripts/create_admin.php admin admin@example.com \"S3cret!\" 1,2,3,4,5\n"
    );
}

$mysqli = getDbConnection();

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $mysqli->prepare('INSERT INTO admin_users (username, email, password_hash) VALUES (?, ?, ?)');
$stmt->bind_param('sss', $username, $email, $hash);
$stmt->execute();
$adminId = $stmt->insert_id;
$stmt->close();

$featureList = $featuresArg ? array_map('intval', explode(',', $featuresArg)) : [1, 2, 3, 4, 5];

foreach ($featureList as $feature) {
    $permStmt = $mysqli->prepare('INSERT INTO admin_permissions (admin_user_id, feature) VALUES (?, ?)');
    $permStmt->bind_param('ii', $adminId, $feature);
    $permStmt->execute();
    $permStmt->close();
}

echo "Created admin user '$username' (id=$adminId) with access to features: " . implode(',', $featureList) . "\n";
