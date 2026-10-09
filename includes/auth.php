<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';

const ADMIN_IDLE_TIMEOUT_SECONDS = 3600;

function loginAdmin(int $adminUserId): void
{
    ensureSessionStarted();
    session_regenerate_id(true);
    $_SESSION['admin_user_id'] = $adminUserId;
    $_SESSION['admin_last_seen'] = time();
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

    // An admin session left idle too long must log in (and pass OTP) again.
    $lastSeen = $_SESSION['admin_last_seen'] ?? time();
    if (time() - $lastSeen > ADMIN_IDLE_TIMEOUT_SECONDS) {
        logoutAdmin();
        header('Location: login.php');
        exit;
    }
    $_SESSION['admin_last_seen'] = time();

    // An account disabled or deleted (admin/admins.php) loses access on its next request, not just at
    // its next login.
    $stmt = getDbConnection()->prepare('SELECT is_active FROM admin_users WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !$row['is_active']) {
        logoutAdmin();
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
