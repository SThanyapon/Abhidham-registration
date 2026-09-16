<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

function generateAndSendOtp(int $adminUserId, string $email): bool
{
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    $ttlSeconds = getConfig()['otp']['ttl_seconds'];

    $mysqli = getDbConnection();
    $stmt = $mysqli->prepare(
        'INSERT INTO otp_codes (admin_user_id, code_hash, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
    );
    $stmt->bind_param('isi', $adminUserId, $codeHash, $ttlSeconds);
    $stmt->execute();
    $stmt->close();

    $minutes = intdiv($ttlSeconds, 60);

    return sendEmail(
        $email,
        'Your login code',
        "Your one-time login code is: $code\nIt expires in $minutes minute(s)."
    );
}

function verifyOtp(int $adminUserId, string $code): bool
{
    $mysqli = getDbConnection();
    $stmt = $mysqli->prepare(
        'SELECT id, code_hash FROM otp_codes
         WHERE admin_user_id = ? AND consumed = 0 AND expires_at > NOW()
         ORDER BY id DESC'
    );
    $stmt->bind_param('i', $adminUserId);
    $stmt->execute();
    $result = $stmt->get_result();

    $matchedId = null;
    while ($row = $result->fetch_assoc()) {
        if (password_verify($code, $row['code_hash'])) {
            $matchedId = $row['id'];
            break;
        }
    }
    $stmt->close();

    if ($matchedId === null) {
        return false;
    }

    $update = $mysqli->prepare('UPDATE otp_codes SET consumed = 1 WHERE id = ?');
    $update->bind_param('i', $matchedId);
    $update->execute();
    $update->close();

    return true;
}
