<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/csrf.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap">
    <title>ลงทะเบียนเรียน - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <h1>ลงทะเบียนเรียนพระอภิธรรม</h1>
    <nav>
        <a href="index.php?register=1">ลงทะเบียน</a>
        <a href="checkin.php">ลงชื่อเข้าเรียน</a>
        <a href="lookup.php">ค้นหารหัสนักศึกษา</a>
        <a href="admin/login.php">ผู้ดูแลระบบ</a>
    </nav>

    <?php
    $mysqli = getDbConnection();
    $batch = $mysqli->query(
        'SELECT id, batch_no, name FROM batches WHERE registration_open = 1 ORDER BY id DESC LIMIT 1'
    )->fetch_assoc();
    $showForm = isset($_GET['register']) || isset($_GET['error']);
    ?>

    <?php if (isset($_GET['success'])): ?>
        <p class="success">ส่งใบลงทะเบียนเรียบร้อยแล้ว กรุณารอการอนุมัติจากเจ้าหน้าที่</p>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <p class="error"><?= htmlspecialchars($_GET['error']) ?></p>
    <?php endif; ?>

    <?php if (!$showForm): ?>
        <p>ยินดีต้อนรับ กด "ลงทะเบียน" เพื่อเริ่มลงทะเบียนเรียนพระอภิธรรม</p>
        <p><a href="index.php?register=1">ลงทะเบียนเลย</a></p>
    <?php elseif (!$batch): ?>
        <p class="error">ขณะนี้ปิดรับลงทะเบียน กรุณากลับมาใหม่ภายหลัง</p>
    <?php else: ?>
        <p>ลงทะเบียนสำหรับ: <strong><?= htmlspecialchars($batch['name'] ?? ('รุ่น ' . $batch['batch_no'])) ?></strong> ชั้นจูฬตรี</p>

        <form action="register.php" method="post">
            <?= csrfField() ?>

            <label for="prefix">คำนำหน้า</label>
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
                <label for="prefix_other">โปรดระบุคำนำหน้า</label>
                <input type="text" id="prefix_other" name="prefix_other">
            </div>

            <label for="full_name">ชื่อ-นามสกุล (ภาษาไทย)</label>
            <input type="text" id="full_name" name="full_name" required>

            <label for="age">อายุ</label>
            <input type="number" id="age" name="age" min="1" max="120">

            <label for="address">ที่อยู่</label>
            <textarea id="address" name="address" rows="3"></textarea>

            <label for="phone">เบอร์โทรศัพท์</label>
            <input type="text" id="phone" name="phone" required>

            <label for="line_id">Line ID</label>
            <input type="text" id="line_id" name="line_id">

            <label for="reference_person">ผู้แนะนำ (ถ้ามี)</label>
            <input type="text" id="reference_person" name="reference_person">

            <button type="submit">ลงทะเบียน</button>
        </form>
    <?php endif; ?>
</body>
</html>
