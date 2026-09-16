<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Abhidham Registration</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <h1>Abhidham Course Registration</h1>

    <?php if (isset($_GET['success'])): ?>
        <p class="success">Registration submitted successfully.</p>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <p class="error"><?= htmlspecialchars($_GET['error']) ?></p>
    <?php endif; ?>

    <form action="register.php" method="post">
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" required>

        <label for="email">Email</label>
        <input type="email" id="email" name="email" required>

        <label for="phone">Phone</label>
        <input type="text" id="phone" name="phone" required>

        <label for="notes">Notes</label>
        <textarea id="notes" name="notes" rows="4"></textarea>

        <button type="submit">Register</button>
    </form>

    <p><a href="admin.php">View registrations</a></p>
</body>
</html>
