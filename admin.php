<?php require_once __DIR__ . '/config.php';

$mysqli = getDbConnection();
$result = $mysqli->query('SELECT id, full_name, email, phone, notes, created_at FROM registrations ORDER BY created_at DESC');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Registrations - Abhidham</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <h1>Registrations</h1>
    <p><a href="index.php">Back to form</a></p>

    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Notes</th>
                <th>Submitted</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['full_name']) ?></td>
                    <td><?= htmlspecialchars($row['email']) ?></td>
                    <td><?= htmlspecialchars($row['phone']) ?></td>
                    <td><?= htmlspecialchars($row['notes']) ?></td>
                    <td><?= htmlspecialchars($row['created_at']) ?></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</body>
</html>
<?php $mysqli->close(); ?>
