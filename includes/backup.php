<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

/**
 * Backup email recipients: the admin-editable list in app_settings, falling back to
 * config.local.php['backup']['recipient_email'] until one has been saved. Comma-separated.
 */
function getBackupRecipients(): array
{
    $row = getDbConnection()
        ->query("SELECT setting_value FROM app_settings WHERE setting_key = 'backup_recipient_email'")
        ->fetch_assoc();
    $value = $row ? $row['setting_value'] : (string) (getConfig()['backup']['recipient_email'] ?? '');

    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($e) => $e !== ''));
}

function saveBackupRecipients(array $emails): void
{
    $value = implode(', ', $emails);
    $stmt = getDbConnection()->prepare(
        "INSERT INTO app_settings (setting_key, setting_value) VALUES ('backup_recipient_email', ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->bind_param('s', $value);
    $stmt->execute();
    $stmt->close();
}

/**
 * Pure-PHP database dump (structure + data) so this doesn't depend on the
 * mysqldump binary being on PATH. Writes a gzip-compressed .sql.gz file, emails it as an
 * attachment to every backup recipient, and logs the run.
 */
function runBackup(string $triggeredBy): array
{
    $config = getConfig();
    $dir = rtrim($config['backup']['directory'], '/\\');

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $mysqli = getDbConnection();
    $dbName = $config['db_name'];

    $sql = "-- Backup of {$dbName} generated at " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = $mysqli->query('SHOW TABLES');
    while ($tableRow = $tables->fetch_row()) {
        $table = $tableRow[0];

        $createRow = $mysqli->query("SHOW CREATE TABLE `$table`")->fetch_row();
        $sql .= "DROP TABLE IF EXISTS `$table`;\n{$createRow[1]};\n\n";

        $dataResult = $mysqli->query("SELECT * FROM `$table`");
        while ($row = $dataResult->fetch_assoc()) {
            $columns = array_map(static fn ($col) => "`$col`", array_keys($row));
            $values = array_map(function ($value) use ($mysqli) {
                return $value === null ? 'NULL' : "'" . $mysqli->real_escape_string((string) $value) . "'";
            }, array_values($row));

            $sql .= 'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', $values) . ");\n";
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $filename = 'backup_' . date('Ymd_His') . '.sql.gz';
    $filePath = $dir . DIRECTORY_SEPARATOR . $filename;
    $compressed = gzencode($sql, 9);
    file_put_contents($filePath, $compressed);

    $recipients = getBackupRecipients();
    $body = "ระบบได้สำรองฐานข้อมูลแล้ว ไฟล์สำรองข้อมูล (บีบอัดแบบ gzip) แนบมากับอีเมลนี้\n"
        . "และบันทึกไว้บนเซิร์ฟเวอร์ที่:\n$filePath\n\n"
        . "วิธีกู้คืน: gunzip -c $filename | mysql -u <user> -p {$dbName}";
    $attachment = ['filename' => $filename, 'content' => $compressed, 'mime' => 'application/gzip'];

    // Emailed only when every recipient's send succeeded (and there was at least one recipient).
    $emailed = $recipients !== [];
    foreach ($recipients as $recipient) {
        if (!sendEmail($recipient, "สำรองฐานข้อมูล - $filename", $body, [$attachment])) {
            $emailed = false;
        }
    }

    $emailedTo = mb_substr(implode(', ', $recipients), 0, 255);
    $stmt = $mysqli->prepare(
        'INSERT INTO backup_runs (file_path, triggered_by, emailed_to) VALUES (?, ?, ?)'
    );
    $stmt->bind_param('sss', $filePath, $triggeredBy, $emailedTo);
    $stmt->execute();
    $stmt->close();

    return ['file_path' => $filePath, 'emailed' => $emailed];
}
