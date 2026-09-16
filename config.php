<?php

$localConfigPath = __DIR__ . '/config.local.php';

if (!file_exists($localConfigPath)) {
    die('Missing config.local.php. Copy config.local.php.example to config.local.php and fill in your database credentials.');
}

$config = require $localConfigPath;

function getDbConnection(): mysqli
{
    global $config;

    $mysqli = mysqli_connect(
        $config['db_host'],
        $config['db_user'],
        $config['db_pass'],
        $config['db_name']
    );

    if (!$mysqli) {
        die('Database connection failed: ' . mysqli_connect_error());
    }

    return $mysqli;
}
