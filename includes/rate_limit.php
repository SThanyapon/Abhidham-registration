<?php

require_once __DIR__ . '/db.php';

function getClientIp(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function checkRateLimit(string $formKey, string $identifier): bool
{
    $limits = getConfig()['rate_limit'][$formKey] ?? null;

    if ($limits === null) {
        return true;
    }

    $mysqli = getDbConnection();
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS hits FROM rate_limit_hits
         WHERE form_key = ? AND identifier = ? AND hit_at > DATE_SUB(NOW(), INTERVAL ? SECOND)'
    );
    $stmt->bind_param('ssi', $formKey, $identifier, $limits['window_seconds']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) $row['hits'] < $limits['max_attempts'];
}

function recordRateLimitHit(string $formKey, string $identifier): void
{
    $mysqli = getDbConnection();
    $stmt = $mysqli->prepare('INSERT INTO rate_limit_hits (form_key, identifier) VALUES (?, ?)');
    $stmt->bind_param('ss', $formKey, $identifier);
    $stmt->execute();
    $stmt->close();
}
