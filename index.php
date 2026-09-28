<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/csrf.php';

// Values from a failed submission (set by register.php), shown once to refill the form.
ensureSessionStarted();
$old = $_SESSION['register_old'] ?? [];
unset($_SESSION['register_old']);

$prefixes = ['พระ', 'สิกขามานา', 'สามเณร', 'สามเณรี', 'แม่ชี', 'นาย', 'นาง', 'นางสาว'];
$oldPrefix = $old['prefix'] ?? '';

function oldValue(array $old, string $key): string
{
    return htmlspecialchars($old[$key] ?? '');
}
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
        <a href="checkin.php">ลงชื่อ/ตรวจสอบการเข้าเรียน</a>
        <a href="lookup.php">ค้นหารหัสนักศึกษา</a>
        <a class="nav-admin" href="admin/login.php">ผู้ดูแลระบบ</a>
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

            <p class="form-note"><span class="required-mark">*</span> จำเป็นต้องกรอก</p>

            <label for="prefix">คำนำหน้า<span class="required-mark">*</span></label>
            <select id="prefix" name="prefix" required onchange="togglePrefixOther()">
                <option value="" <?= $oldPrefix === '' ? 'selected' : '' ?>>-- เลือกคำนำหน้า --</option>
                <?php foreach ($prefixes as $p): ?>
                    <option value="<?= $p ?>" <?= $oldPrefix === $p ? 'selected' : '' ?>><?= $p ?></option>
                <?php endforeach; ?>
                <option value="อื่นๆ" <?= $oldPrefix === 'อื่นๆ' ? 'selected' : '' ?>>อื่นๆ (ระบุ)</option>
            </select>

            <div id="prefix_other_wrap" class="field-group" <?= $oldPrefix === 'อื่นๆ' ? '' : 'hidden' ?>>
                <label for="prefix_other">โปรดระบุคำนำหน้า<span class="required-mark">*</span></label>
                <input type="text" id="prefix_other" name="prefix_other" value="<?= oldValue($old, 'prefix_other') ?>"
                       <?= $oldPrefix === 'อื่นๆ' ? 'required' : '' ?>>
            </div>

            <label for="full_name">ชื่อ-นามสกุล (ภาษาไทย)<span class="required-mark">*</span></label>
            <input type="text" id="full_name" name="full_name" required value="<?= oldValue($old, 'full_name') ?>">

            <label for="age">อายุ<span class="required-mark">*</span></label>
            <input type="number" id="age" name="age" min="1" max="120" required value="<?= oldValue($old, 'age') ?>">

            <label for="address">ที่อยู่<span class="required-mark">*</span></label>
            <textarea id="address" name="address" rows="3" required><?= oldValue($old, 'address') ?></textarea>

            <label for="phone">เบอร์โทรศัพท์<span class="required-mark">*</span></label>
            <input type="tel" id="phone" name="phone" inputmode="tel" required
                   pattern="\+?[0-9 \-]{9,20}" title="ตัวเลข 9-15 หลัก เช่น 081 234 5678"
                   value="<?= oldValue($old, 'phone') ?>">

            <label for="line_id">Line ID</label>
            <input type="text" id="line_id" name="line_id" value="<?= oldValue($old, 'line_id') ?>">

            <label for="reference_person">ผู้แนะนำ (ถ้ามี)</label>
            <input type="text" id="reference_person" name="reference_person" value="<?= oldValue($old, 'reference_person') ?>">

            <button type="submit">ลงทะเบียน</button>
        </form>

        <script>
            function togglePrefixOther() {
                const isOther = document.getElementById('prefix').value === 'อื่นๆ';
                document.getElementById('prefix_other_wrap').hidden = !isOther;
                document.getElementById('prefix_other').required = isOther;
            }
        </script>
    <?php endif; ?>
</body>
</html>
