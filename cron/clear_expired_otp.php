<?php

require_once __DIR__ . '/../includes/db.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script is intended to be run from the command line (Task Scheduler / cron).');
}

$mysqli = getDbConnection();
$stmt = $mysqli->prepare('DELETE FROM otp_codes WHERE expires_at < NOW()');
$stmt->execute();
$deleted = $stmt->affected_rows;
$stmt->close();

echo "Deleted $deleted expired OTP code(s).\n";
