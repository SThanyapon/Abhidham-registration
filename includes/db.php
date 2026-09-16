<?php

$GLOBALS['__config'] = null;
$GLOBALS['__db'] = null;

function getConfig(): array
{
    if ($GLOBALS['__config'] === null) {
        $localConfigPath = __DIR__ . '/../config.local.php';

        if (!file_exists($localConfigPath)) {
            die('Missing config.local.php. Copy config.local.php.example to config.local.php and fill in your settings.');
        }

        $GLOBALS['__config'] = require $localConfigPath;
    }

    return $GLOBALS['__config'];
}

function getDbConnection(): mysqli
{
    if ($GLOBALS['__db'] === null) {
        $config = getConfig();

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = mysqli_connect(
            $config['db_host'],
            $config['db_user'],
            $config['db_pass'],
            $config['db_name']
        );

        $mysqli->set_charset('utf8mb4');
        $GLOBALS['__db'] = $mysqli;
    }

    return $GLOBALS['__db'];
}
