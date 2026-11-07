<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/input.php';
require_once __DIR__ . '/includes/date_helpers.php';
require_once __DIR__ . '/includes/student_helpers.php';
require_once __DIR__ . '/includes/public_nav.php';
require_once __DIR__ . '/includes/student_form.php';

// ยืนยันการรับหนังสือ: a student of a ห้องเรียน whose book confirmation is open (admin/classes.php)
// identifies with student ID + name, sees the registration address, and confirms a shipping address
// once per ห้องเรียน (UNIQUE key on book_confirmations). The verified student and class are kept in
// $_SESSION['book_confirm'] between the two steps, so the confirm POST never trusts form fields for them.

ensureSessionStarted();
$mysqli = getDbConnection();

$error = null;
$fullName = '';
$studentNo = '';
$student = null;      // registration row of the identified student (form step)
$class = null;        // the student's class with confirmation open
$confirmation = null; // an existing (or just saved) confirmation, shown read-only
$shipping = [];       // values for the shipping form (refill after an error)
$panelOpen = false;   // show the shipping form without clicking the button first

const BOOK_STUDENT_COLUMNS = 's.id, s.student_no, s.prefix, s.prefix_other, s.full_name, s.phone, s.address,
    s.country, s.province, s.district, s.sub_district, s.postal_code';

function bookClass(mysqli $mysqli, int $classInstanceId): ?array
{
    $stmt = $mysqli->prepare(
        'SELECT ci.id, ci.book_confirmation_open, cl.name AS level_name, b.batch_no, b.name AS batch_name
         FROM class_instances ci
         JOIN class_levels cl ON cl.id = ci.class_level_id
         JOIN batches b ON b.id = ci.batch_id
         WHERE ci.id = ?'
    );
    $stmt->bind_param('i', $classInstanceId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function bookConfirmation(mysqli $mysqli, int $studentId, int $classInstanceId): ?array
{
    $stmt = $mysqli->prepare('SELECT * FROM book_confirmations WHERE student_id = ? AND class_instance_id = ?');
    $stmt->bind_param('ii', $studentId, $classInstanceId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function bookStudent(mysqli $mysqli, string $where, string $types, ...$params): ?array
{
    $stmt = $mysqli->prepare('SELECT ' . BOOK_STUDENT_COLUMNS . " FROM students s WHERE s.status = 'approved' AND $where");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

// The address columns, normalised the way validateAddressFields() stores them (blank country = Thailand).
function bookAddressKey(array $row): array
{
    $abroad = studentResidence($row) === RESIDENCE_ABROAD;
    $key = [];
    foreach (['address', 'province', 'district', 'sub_district', 'postal_code'] as $field) {
        $key[$field] = cleanMultilineText((string) ($row[$field] ?? ''));
    }
    $key['country'] = $abroad ? cleanText((string) $row['country']) : COUNTRY_THAILAND;

    return $key;
}

// The recipient name prefilled from registration: with the prefix when it passes the name rule.
function defaultRecipientName(array $student): string
{
    $withPrefix = trim(studentPrefix($student) . ' ' . $student['full_name']);

    return isValidPersonName($withPrefix) ? $withPrefix : $student['full_name'];
}

$classLabel = fn (array $c): string => ($c['batch_name'] ?: 'รุ่น ' . $c['batch_no']) . ' - ' . $c['level_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'identify') {
        unset($_SESSION['book_confirm']);
        $ip = getClientIp();
        $fullName = cleanText($_POST['full_name'] ?? '');
        $studentNo = cleanCode($_POST['student_no'] ?? '');

        if (!checkRateLimit('book_confirm', $ip)) {
            $error = 'มีการลองหลายครั้งเกินไป กรุณาลองใหม่ภายหลัง';
        } elseif ($studentNo === '' || $fullName === '') {
            $error = 'กรุณากรอกรหัสนักศึกษาและชื่อ-นามสกุล';
        } else {
            recordRateLimitHit('book_confirm', $ip);
            $student = bookStudent($mysqli, 's.student_no = ? AND s.full_name = ?', 'ss', $studentNo, $fullName);
            $classId = $student ? studentClassInstanceId($mysqli, (int) $student['id']) : null;
            $class = $classId !== null ? bookClass($mysqli, $classId) : null;

            if (!$student) {
                $error = 'รหัสนักศึกษา หรือชื่อ-นามสกุลไม่ตรงกับข้อมูลในระบบ';
            } elseif (!$class || !$class['book_confirmation_open']) {
                $error = 'ขณะนี้ยังไม่เปิดให้ยืนยันการรับหนังสือสำหรับชั้นเรียนของท่าน';
                $student = null;
            } else {
                $confirmation = bookConfirmation($mysqli, (int) $student['id'], (int) $class['id']);
                if (!$confirmation) {
                    $_SESSION['book_confirm'] = ['student_id' => (int) $student['id'], 'class_instance_id' => (int) $class['id']];
                    $shipping = ['recipient_name' => defaultRecipientName($student), 'phone' => $student['phone']];
                }
            }
        }
    } elseif ($action === 'confirm') {
        $pending = $_SESSION['book_confirm'] ?? null;
        $student = $pending ? bookStudent($mysqli, 's.id = ?', 'i', $pending['student_id']) : null;
        // Still the student's current class, and still open (the admin may have closed it meanwhile).
        $class = $student && studentClassInstanceId($mysqli, (int) $student['id']) === $pending['class_instance_id']
            ? bookClass($mysqli, $pending['class_instance_id'])
            : null;

        if (!$student) {
            $error = 'กรุณากรอกรหัสนักศึกษาและชื่อ-นามสกุลอีกครั้ง';
        } elseif (!$class || !$class['book_confirmation_open']) {
            $error = 'ปิดการยืนยันการรับหนังสือสำหรับชั้นเรียนของท่านแล้ว';
            $student = null;
            unset($_SESSION['book_confirm']);
        } elseif ($confirmation = bookConfirmation($mysqli, (int) $student['id'], (int) $class['id'])) {
            unset($_SESSION['book_confirm']);
        } else {
            [$shipping, $formError] = validateBookShipping($_POST);
            if ($formError !== null) {
                $error = $formError;
                $panelOpen = true;
            } else {
                $sameAsRegistration = bookAddressKey($shipping) === bookAddressKey($student) ? 1 : 0;
                try {
                    $stmt = $mysqli->prepare(
                        'INSERT INTO book_confirmations
                            (student_id, class_instance_id, recipient_name, phone, address, country, province,
                             district, sub_district, postal_code, same_as_registration)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->bind_param(
                        'iissssssssi',
                        $student['id'], $class['id'], $shipping['recipient_name'], $shipping['phone'],
                        $shipping['address'], $shipping['country'], $shipping['province'], $shipping['district'],
                        $shipping['sub_district'], $shipping['postal_code'], $sameAsRegistration
                    );
                    $stmt->execute();
                    $stmt->close();
                } catch (mysqli_sql_exception $e) {
                    if ($e->getCode() !== 1062) {
                        throw $e;
                    }
                    // A second submit raced this one; the first one stands.
                }
                unset($_SESSION['book_confirm']);
                $_SESSION['book_confirm_done'] = ['student_id' => (int) $student['id'], 'class_instance_id' => (int) $class['id']];
                header('Location: books.php?done=1');
                exit;
            }
        }
    }
} else {
    unset($_SESSION['book_confirm']);
    // After a successful confirm (Post/Redirect/Get): show what was saved, once.
    $done = isset($_GET['done']) ? ($_SESSION['book_confirm_done'] ?? null) : null;
    unset($_SESSION['book_confirm_done']);
    if ($done) {
        $student = bookStudent($mysqli, 's.id = ?', 'i', $done['student_id']);
        $class = bookClass($mysqli, $done['class_instance_id']);
        $confirmation = $student && $class ? bookConfirmation($mysqli, $done['student_id'], $done['class_instance_id']) : null;
    }
}

$justConfirmed = $_SERVER['REQUEST_METHOD'] === 'GET' && $confirmation !== null;
$showForm = $student && $class && !$confirmation;
$anyOpen = bookConfirmationOpen($mysqli);

$registrationValues = $student ? [
    'residence' => studentResidence($student),
    'address' => (string) $student['address'],
    'country' => (string) $student['country'],
    'province' => (string) $student['province'],
    'district' => (string) $student['district'],
    'sub_district' => (string) $student['sub_district'],
    'postal_code' => (string) $student['postal_code'],
] : [];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap">
    <title>ยืนยันการรับหนังสือ - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <h1>ยืนยันการรับหนังสือ</h1>
    <?= publicNav($mysqli) ?>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if ($confirmation && $student && $class): ?>
        <?php if ($justConfirmed): ?>
            <p class="success">ยืนยันการรับหนังสือเรียบร้อยแล้ว เจ้าหน้าที่จะจัดส่งหนังสือไปตามที่อยู่ด้านล่าง</p>
        <?php else: ?>
            <p class="notice">ท่านได้ยืนยันการรับหนังสือของชั้นเรียนนี้แล้ว เมื่อ
                <?= htmlspecialchars(formatDateBEShort(substr((string) $confirmation['confirmed_at'], 0, 10))) ?>
                (ยืนยันได้ครั้งเดียว หากต้องการแก้ไขที่อยู่ กรุณาติดต่อเจ้าหน้าที่)</p>
        <?php endif; ?>
        <div class="card">
            <p>
                รหัสนักศึกษา: <strong><?= htmlspecialchars($student['student_no']) ?></strong><br>
                ชั้นเรียน: <strong><?= htmlspecialchars($classLabel($class)) ?></strong>
            </p>
            <h2>ที่อยู่จัดส่งหนังสือ</h2>
            <p>
                <strong><?= htmlspecialchars($confirmation['recipient_name']) ?></strong><br>
                โทร. <?= htmlspecialchars($confirmation['phone']) ?><br>
                <?= implode('<br>', array_map('htmlspecialchars', formatAddressLines($confirmation))) ?>
            </p>
        </div>
        <p><a href="books.php">กลับ</a></p>

    <?php elseif ($showForm): ?>
        <p>ชั้นเรียน: <strong><?= htmlspecialchars($classLabel($class)) ?></strong> ·
            <a href="books.php">ไม่ใช่ท่าน? เริ่มใหม่</a></p>

        <div class="book-columns">
            <div class="card">
                <h2>ที่อยู่ที่ลงทะเบียนไว้</h2>
                <p>
                    รหัสนักศึกษา: <strong><?= htmlspecialchars($student['student_no']) ?></strong><br>
                    <strong><?= htmlspecialchars(studentPrefix($student) . ' ' . $student['full_name']) ?></strong><br>
                    โทร. <?= htmlspecialchars($student['phone']) ?><br>
                    <?= implode('<br>', array_map('htmlspecialchars', formatAddressLines($student))) ?>
                </p>
                <button type="button" id="open_shipping" class="cta-button" onclick="openShipping()"<?= $panelOpen ? ' hidden' : '' ?>>ยืนยันการรับหนังสือ</button>
            </div>

            <div id="shipping_panel"<?= $panelOpen ? '' : ' hidden' ?>>
                <form action="books.php" method="post"
                      onsubmit="return confirm('ยืนยันที่อยู่จัดส่งหนังสือ? ยืนยันได้ครั้งเดียว แก้ไขภายหลังไม่ได้');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="confirm">
                    <h2>ที่อยู่จัดส่งหนังสือ</h2>
                    <p class="form-note"><span class="required-mark">*</span> จำเป็นต้องกรอก</p>

                    <label class="checkbox-label">
                        <input type="checkbox" id="same_as_registration" onchange="copyRegistrationAddress(this.checked)">
                        ใช้ที่อยู่เดียวกับที่ลงทะเบียน
                    </label>

                    <label for="recipient_name">ชื่อ-นามสกุลผู้รับ<span class="required-mark">*</span></label>
                    <input type="text" id="recipient_name" name="recipient_name" required
                           value="<?= htmlspecialchars((string) ($shipping['recipient_name'] ?? '')) ?>">

                    <label for="phone">เบอร์มือถือผู้รับ<span class="required-mark">*</span></label>
                    <input type="tel" id="phone" name="phone" inputmode="tel" required
                           pattern="\+?[0-9 \-]{9,20}" title="ตัวเลข 9-15 หลัก เช่น 081 234 5678"
                           value="<?= htmlspecialchars((string) ($shipping['phone'] ?? '')) ?>">

                    <?= addressFields($shipping, true) ?>

                    <button type="submit">ยืนยันที่อยู่จัดส่งหนังสือ</button>
                </form>
            </div>
        </div>

        <?= addressScript('assets/data/thai_postcodes.json') ?>
        <script>
            const REGISTRATION_ADDRESS = <?= json_encode($registrationValues, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

            function openShipping() {
                document.getElementById('shipping_panel').hidden = false;
                document.getElementById('open_shipping').hidden = true;
                document.getElementById('shipping_panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            // Copies the registration address into the shipping fields (they stay editable).
            function copyRegistrationAddress(checked) {
                if (!checked) {
                    return;
                }
                const a = REGISTRATION_ADDRESS;
                const abroad = a.residence === 'abroad';
                document.querySelector('input[name="residence"][value="' + (abroad ? 'abroad' : 'th') + '"]').checked = true;
                syncResidence();

                if (abroad) {
                    document.getElementById('street_address_abroad').value = a.address;
                    document.getElementById('district_abroad').value = a.district;
                    document.getElementById('province_abroad').value = a.province;
                    document.getElementById('postal_code_abroad').value = a.postal_code;
                    document.getElementById('country_abroad').value = a.country;
                    return;
                }
                document.getElementById('street_address_th').value = a.address;
                document.getElementById('postal_code_th').value = a.postal_code;
                // Preselect the stored values, then let the postcode lookup fill the lists around them.
                ['province', 'district', 'sub_district'].forEach(function (field) {
                    const select = document.getElementById(field + '_th');
                    select.replaceChildren(new Option('-- เลือก --', ''));
                    if (a[field]) {
                        select.add(new Option(a[field], a[field], true, true));
                    }
                });
                syncThaiAddress(true);
            }
        </script>

    <?php elseif (!$anyOpen): ?>
        <p class="notice">ขณะนี้ยังไม่เปิดให้ยืนยันการรับหนังสือ</p>

    <?php else: ?>
        <p>กรอกรหัสนักศึกษาและชื่อ-นามสกุล เพื่อยืนยันที่อยู่สำหรับจัดส่งหนังสือเรียน (ยืนยันได้ครั้งเดียวต่อชั้นเรียน)</p>
        <form action="books.php" method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="identify">

            <label for="student_no">รหัสนักศึกษา<span class="required-mark">*</span></label>
            <input type="text" id="student_no" name="student_no" required value="<?= htmlspecialchars($studentNo) ?>">

            <label for="full_name">ชื่อ-นามสกุล (ภาษาไทย) กรอกเฉพาะชื่อและนามสกุล ไม่ต้องกรอกคำนำหน้า<span class="required-mark">*</span></label>
            <input type="text" id="full_name" name="full_name" required value="<?= htmlspecialchars($fullName) ?>">

            <button type="submit">ถัดไป</button>
        </form>
    <?php endif; ?>
</body>
</html>
