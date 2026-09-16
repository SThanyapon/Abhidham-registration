<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

/**
 * Pure-PHP database dump (structure + data) so this doesn't depend on the
 * mysqldump binary being on PATH. Writes a .sql file and logs the run.
 * Note: the notification email does not attach the file (the minimal SMTP
 * client in mailer.php doesn't support attachments) - retrieve it from the
 * backup directory on the server.
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

    $filename = 'backup_' . date('Ymd_His') . '.sql';
    $filePath = $dir . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($filePath, $sql);

    $recipient = $config['backup']['recipient_email'];
    $emailed = sendEmail(
        $recipient,
        "Database backup - $filename",
        "A database backup was created and saved on the server at:\n$filePath\n\n"
            . "(This notification does not include the file as an attachment.)"
    );

    $stmt = $mysqli->prepare(
        'INSERT INTO backup_runs (file_path, triggered_by, emailed_to) VALUES (?, ?, ?)'
    );
    $stmt->bind_param('sss', $filePath, $triggeredBy, $recipient);
    $stmt->execute();
    $stmt->close();

    return ['file_path' => $filePath, 'emailed' => $emailed];
}
