<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';

function loginAdmin(int $adminUserId): void
{
    ensureSessionStarted();
    session_regenerate_id(true);
    $_SESSION['admin_user_id'] = $adminUserId;
    unset($_SESSION['otp_pending_admin_id']);
}

function logoutAdmin(): void
{
    ensureSessionStarted();
    $_SESSION = [];
    session_destroy();
}

function currentAdminId(): ?int
{
    ensureSessionStarted();
    return isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
}

function requireAdminLogin(): int
{
    $id = currentAdminId();

    if ($id === null) {
        header('Location: login.php');
        exit;
    }

    return $id;
}

function adminHasFeature(int $adminUserId, int $feature): bool
{
    $mysqli = getDbConnection();
    $stmt = $mysqli->prepare('SELECT 1 FROM admin_permissions WHERE admin_user_id = ? AND feature = ?');
    $stmt->bind_param('ii', $adminUserId, $feature);
    $stmt->execute();
    $stmt->store_result();
    $has = $stmt->num_rows > 0;
    $stmt->close();

    return $has;
}

function requireFeature(int $adminUserId, int $feature): void
{
    if (!adminHasFeature($adminUserId, $feature)) {
        http_response_code(403);
        die('You do not have permission to access this feature.');
    }
}
