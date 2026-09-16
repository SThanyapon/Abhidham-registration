<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/csrf.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Abhidham Registration</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <h1>Abhidham Course Registration</h1>
    <nav>
        <a href="index.php">Register</a>
        <a href="checkin.php">Check-in</a>
        <a href="lookup.php">Find my Student ID</a>
        <a href="admin/login.php">Admin</a>
    </nav>

    <?php
    $mysqli = getDbConnection();
    $batch = $mysqli->query(
        'SELECT id, batch_no, name FROM batches WHERE registration_open = 1 ORDER BY id DESC LIMIT 1'
    )->fetch_assoc();
    ?>

    <?php if (isset($_GET['success'])): ?>
        <p class="success">Registration submitted. Please wait for admission team approval.</p>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <p class="error"><?= htmlspecialchars($_GET['error']) ?></p>
    <?php endif; ?>

    <?php if (!$batch): ?>
        <p class="error">Registration is currently closed. Please check back later.</p>
    <?php else: ?>
        <p>Registering for: <strong><?= htmlspecialchars($batch['name'] ?? ('รุ่น ' . $batch['batch_no'])) ?></strong>, class จูฬตรี</p>

        <form action="register.php" method="post">
            <?= csrfField() ?>

            <label for="prefix">Prefix</label>
            <select id="prefix" name="prefix" onchange="document.getElementById('prefix_other_wrap').hidden = (this.value !== 'อื่นๆ')">
                <option value="พระ">พระ</option>
                <option value="สิกขามานา">สิกขามานา</option>
                <option value="สามเณร">สามเณร</option>
                <option value="สามเณรี">สามเณรี</option>
                <option value="แม่ชี">แม่ชี</option>
                <option value="นาย">นาย</option>
                <option value="นาง">นาง</option>
                <option value="นางสาว">นางสาว</option>
                <option value="อื่นๆ">อื่นๆ (ระบุ)</option>
            </select>

            <div id="prefix_other_wrap" hidden>
                <label for="prefix_other">Please specify prefix</label>
                <input type="text" id="prefix_other" name="prefix_other">
            </div>

            <label for="full_name">Name-Surname (Thai)</label>
            <input type="text" id="full_name" name="full_name" required>

            <label for="age">Age</label>
            <input type="number" id="age" name="age" min="1" max="120">

            <label for="address">Address</label>
            <textarea id="address" name="address" rows="3"></textarea>

            <label for="phone">Telephone</label>
            <input type="text" id="phone" name="phone" required>

            <label for="line_id">Line ID</label>
            <input type="text" id="line_id" name="line_id">

            <label for="reference_person">Reference person (optional)</label>
            <input type="text" id="reference_person" name="reference_person">

            <button type="submit">Register</button>
        </form>
    <?php endif; ?>
</body>
</html>
