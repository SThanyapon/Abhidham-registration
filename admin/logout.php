<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

// POST + CSRF only, so another site can't log an admin out with a plain link.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

verifyCsrf();
logoutAdmin();
header('Location: login.php');
exit;
