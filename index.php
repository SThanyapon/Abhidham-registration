<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/student_form.php';

// Values from a failed submission (set by register.php), shown once to refill the form.
ensureSessionStarted();
$old = $_SESSION['register_old'] ?? [];
$registerError = $_SESSION['register_error'] ?? null;
unset($_SESSION['register_old'], $_SESSION['register_error']);

$oldPrefix = $old['prefix'] ?? '';

$mysqli = getDbConnection();
$batch = $mysqli->query(
    'SELECT id, batch_no, name FROM batches WHERE registration_open = 1 ORDER BY id DESC LIMIT 1'
)->fetch_assoc();
$showForm = isset($_GET['register']) || $registerError !== null;

// While registration is open, the poster pops up on a visitor's first page view of the session
// (never over the form or the success page).
$showLanding = $batch && !isset($_SESSION['landing_seen']) && !$showForm && !isset($_GET['success']);
if ($showLanding) {
    $_SESSION['landing_seen'] = true;
}

function oldValue(array $old, string $key): string
{
    return htmlspecialchars((string) ($old[$key] ?? ''));
}

// Choice question refilled from $old; captions match the Google Form.
function choiceField(array $old, string $field, string $caption, bool $required = true): string
{
    return studentChoiceField($field, $caption, (string) ($old[$field] ?? ''), (string) ($old[$field . '_other'] ?? ''), $required);
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

    <?php if ($showLanding): ?>
        <dialog id="landing" class="landing-dialog">
            <a href="index.php?register=1">
                <img src="assets/images/landingpage.jpg" width="737" height="926"
                     alt="มูลนิธิพระอภิธรรมวัดศรีสุดาราม เปิดรับสมัครนักศึกษารุ่นใหม่ ชั้นจูฬตรี พระอภิธรรมออนไลน์ เรียนฟรี ไม่มีค่าใช้จ่าย รับตำราเรียนตลอดหลักสูตร">
            </a>
            <div class="action-row">
                <form class="inline-form" action="index.php" method="get">
                    <input type="hidden" name="register" value="1">
                    <button type="submit">ลงทะเบียนเลย</button>
                </form>
                <button type="button" class="secondary" onclick="this.closest('dialog').close()">ปิด</button>
            </div>
        </dialog>
        <script>
            (function () {
                const dialog = document.getElementById('landing');
                // A click on the backdrop lands on the dialog element itself; close on it.
                dialog.addEventListener('click', function (e) {
                    if (e.target === dialog) {
                        dialog.close();
                    }
                });
                dialog.showModal();
            })();
        </script>
    <?php endif; ?>

    <?php if (isset($_GET['success'])): ?>
        <p class="success">ส่งใบลงทะเบียนเรียบร้อยแล้ว กรุณารอการอนุมัติจากเจ้าหน้าที่</p>
        <div class="card classroom-qr">
            <p>ถ้ายังไม่ได้เข้าห้องเรียน ให้ บันทึก QR Code นี้ เก็บไว้เพื่อสแกนเข้าห้องเรียน หลังจากส่งใบสมัครแล้ว</p>
            <img src="assets/images/qr-code7.jpg" alt="QR Code สำหรับสแกนเข้าห้องเรียน" width="230" height="230">
        </div>
        <img class="bottom-banner" src="assets/images/bottom_banner.jpg"
             alt="มูลนิธิพระอภิธรรมวัดศรีสุดาราม สำนักงานเลขที่ 83 วัดศรีสุดารามวรวิหาร โทร. 086 750 8338">
    <?php endif; ?>

    <?php if ($registerError !== null): ?>
        <p class="error"><?= htmlspecialchars($registerError) ?></p>
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
                <?php foreach (STUDENT_PREFIXES as $p): ?>
                    <option value="<?= $p ?>" <?= $oldPrefix === $p ? 'selected' : '' ?>><?= $p === 'อื่นๆ' ? 'อื่นๆ (ระบุ)' : $p ?></option>
                <?php endforeach; ?>
            </select>

            <div id="prefix_other_wrap" class="field-group" <?= $oldPrefix === 'อื่นๆ' ? '' : 'hidden' ?>>
                <label for="prefix_other">โปรดระบุคำนำหน้า<span class="required-mark">*</span></label>
                <input type="text" id="prefix_other" name="prefix_other" value="<?= oldValue($old, 'prefix_other') ?>"
                       <?= $oldPrefix === 'อื่นๆ' ? 'required' : '' ?>>
            </div>

            <label for="full_name">ชื่อ-สกุล (ภาษาไทย) ไม่ต้องใส่คำนำหน้า<span class="required-mark">*</span></label>
            <input type="text" id="full_name" name="full_name" required value="<?= oldValue($old, 'full_name') ?>"
                   pattern="[ก-ฺเ-๎๐-๙A-Za-z0-9 \-‐-–]+"
                   title="ใช้ได้เฉพาะอักษรไทย อักษรอังกฤษ ตัวเลข ขีด (-) และช่องว่าง">

            <label for="age">อายุ (โดยประมาณ-ใส่แต่ตัวเลข)<span class="required-mark">*</span></label>
            <input type="number" id="age" name="age" min="1" max="120" required value="<?= oldValue($old, 'age') ?>">

            <label for="address">ที่อยู่ในการส่งเอกสาร (กรอกให้ครบถ้วน ยกเว้น รหัสจังหวัด และ ไปรษณีย์ ให้กรอกในข้อถัดไป)<span class="required-mark">*</span></label>
            <textarea id="address" name="address" rows="3" required><?= oldValue($old, 'address') ?></textarea>

            <?= provinceFields((string) ($old['province'] ?? DEFAULT_PROVINCE), (string) ($old['postal_code'] ?? ''), true) ?>

            <label for="phone">เบอร์มือถือ<span class="required-mark">*</span></label>
            <input type="tel" id="phone" name="phone" inputmode="tel" required
                   pattern="\+?[0-9 \-]{9,20}" title="ตัวเลข 9-15 หลัก เช่น 081 234 5678"
                   value="<?= oldValue($old, 'phone') ?>">

            <label for="line_name">ชื่อไลน์ ของท่าน<span class="required-mark">*</span></label>
            <input type="text" id="line_name" name="line_name" required value="<?= oldValue($old, 'line_name') ?>">

            <label for="line_id">LINE ID หรือ เบอร์มือถือของท่าน ที่ลงทะเบียนไว้กับทาง LINE (*สำคัญ)<span class="required-mark">*</span></label>
            <p class="form-note">เพื่อแอดท่านเป็นเพื่อน และให้ท่านทักมาหา เจ้าหน้าที่</p>
            <input type="text" id="line_id" name="line_id" required value="<?= oldValue($old, 'line_id') ?>">

            <?= choiceField($old, 'heard_from', 'ทราบข่าวการสมัครจากช่องทางใด?') ?>

            <?= choiceField($old, 'student_type', 'นักศึกษาเก่าหรือใหม่?') ?>

            <?php $returning = isReturningStudent((string) ($old['student_type'] ?? '')); ?>
            <div id="previous_student_no_wrap" class="field-group" <?= $returning ? '' : 'hidden' ?>>
                <label for="previous_student_no">โปรดระบุรหัสนักศึกษาเดิมของท่าน</label>
                <input type="text" id="previous_student_no" name="previous_student_no" inputmode="numeric"
                       pattern="[0-9]{4,10}" title="ตัวเลข 4-10 หลัก"
                       value="<?= oldValue($old, 'previous_student_no') ?>" <?= $returning ? '' : 'disabled' ?>>
            </div>

            <label for="reference_person">โปรดระบุ ชื่อนามสกุล เบอร์โทร ของเพื่อนที่แนะนำมา</label>
            <input type="text" id="reference_person" name="reference_person" value="<?= oldValue($old, 'reference_person') ?>">

            <label for="study_reason">เหตุผลที่มาเรียนพระอภิธรรม?<span class="required-mark">*</span></label>
            <textarea id="study_reason" name="study_reason" rows="3" required><?= oldValue($old, 'study_reason') ?></textarea>

            <?= choiceField($old, 'zoom_skill', 'ท่านใช้ ZOOM เป็นหรือไม่?') ?>

            <?= choiceField($old, 'joined_classroom', 'ได้กดเข้าห้องเรียนแล้วหรือยัง?') ?>

            <button type="submit">ลงทะเบียน</button>
        </form>

        <?= studentChoiceScript() ?>
        <?= provinceScript() ?>
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
