<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backup.php';

$adminId = requireAdminLogin();
requireFeature($adminId, 4);

$mysqli = getDbConnection();
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (($_POST['action'] ?? '') === 'backup_now') {
        $result = runBackup('manual');
        $notice = 'Backup created at ' . $result['file_path'] . ($result['emailed'] ? ' and emailed.' : ' (email notification failed - check mail settings).');
    }
}

$runs = $mysqli->query('SELECT file_path, triggered_by, emailed_to, created_at FROM backup_runs ORDER BY id DESC LIMIT 20')
    ->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Backup - Abhidham Registration</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <h1>Database Backup</h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="logout.php">Log out</a>
    </nav>

    <?php if ($notice): ?><p class="success"><?= htmlspecialchars($notice) ?></p><?php endif; ?>

    <form action="backup.php" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="backup_now">
        <button type="submit">Backup now</button>
    </form>

    <p>For scheduled backups, point Windows Task Scheduler (or cron) at <code>cron/backup.php</code>
       using the PHP CLI, e.g. <code>php cron/backup.php</code> on whatever interval you need.</p>

    <h2>Recent backups</h2>
    <table>
        <thead>
            <tr><th>File</th><th>Triggered by</th><th>Emailed to</th><th>When</th></tr>
        </thead>
        <tbody>
            <?php foreach ($runs as $run): ?>
                <tr>
                    <td><?= htmlspecialchars($run['file_path']) ?></td>
                    <td><?= htmlspecialchars($run['triggered_by']) ?></td>
                    <td><?= htmlspecialchars($run['emailed_to'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($run['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
