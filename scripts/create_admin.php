<?php

require_once __DIR__ . '/../includes/db.php';

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

[, $username, $email, $password, $featuresArg] = array_pad($argv, 5, null);

if (!$username || !$email || !$password) {
    die(
        "Usage: php scripts/create_admin.php <username> <email> <password> [feature_numbers_comma_separated]\n"
        . "Example: php scripts/create_admin.php admin admin@example.com \"S3cret!\" 0,1,2,3,4,6,7,9\n"
        . "Features: 0=admins, 1=classes, 2=approvals, 3=reports, 4=promotions, 6=import, 7=edit students, 9=backup\n"
    );
}

$mysqli = getDbConnection();

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $mysqli->prepare('INSERT INTO admin_users (username, email, password_hash) VALUES (?, ?, ?)');
$stmt->bind_param('sss', $username, $email, $hash);
$stmt->execute();
$adminId = $stmt->insert_id;
$stmt->close();

$featureList = ($featuresArg !== null && $featuresArg !== '') ? array_map('intval', explode(',', $featuresArg)) : [0, 1, 2, 3, 4, 6, 7, 9];

foreach ($featureList as $feature) {
    $permStmt = $mysqli->prepare('INSERT INTO admin_permissions (admin_user_id, feature) VALUES (?, ?)');
    $permStmt->bind_param('ii', $adminId, $feature);
    $permStmt->execute();
    $permStmt->close();
}

echo "Created admin user '$username' (id=$adminId) with access to features: " . implode(',', $featureList) . "\n";
