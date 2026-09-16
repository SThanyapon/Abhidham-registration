<?php

require_once __DIR__ . '/../includes/backup.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script is intended to be run from the command line (Task Scheduler / cron).');
}

$result = runBackup('scheduled');
echo "Backup written to {$result['file_path']}\n";
echo $result['emailed'] ? "Notification emailed.\n" : "Email notification failed.\n";
