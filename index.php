<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/public_nav.php';

// Home page: announces what students can do right now (registration open, book confirmation open)
// and links to that page. With nothing to announce it sends the visitor straight to checkin.php.

// Links from before the registration form moved to registration-form.php (index.php?register=1,
// ?success=1) keep working.
if (isset($_GET['register'])) {
    header('Location: registration-form.php');
    exit;
}
if (isset($_GET['success'])) {
    header('Location: registration-form.php?success=1');
    exit;
}

$mysqli = getDbConnection();
$batch = $mysqli->query(
    'SELECT id, batch_no, name FROM batches WHERE registration_open = 1 ORDER BY id DESC LIMIT 1'
)->fetch_assoc();
// The ห้องเรียน with ยืนยันการรับหนังสือ open (admin/classes.php).
$bookClasses = $mysqli->query(
    'SELECT cl.name AS level_name, b.batch_no, b.name AS batch_name
     FROM class_instances ci
     JOIN class_levels cl ON cl.id = ci.class_level_id
     JOIN batches b ON b.id = ci.batch_id
     WHERE ci.book_confirmation_open = 1
     ORDER BY b.batch_no DESC, cl.sort_order'
)->fetch_all(MYSQLI_ASSOC);

if (!$batch && $bookClasses === []) {
    header('Location: checkin.php');
    exit;
}

// While registration is open, the poster pops up on a visitor's first view of the session.
ensureSessionStarted();
$showLanding = $batch && !isset($_SESSION['landing_seen']);
if ($showLanding) {
    $_SESSION['landing_seen'] = true;
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
    <title>ประกาศ - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <h1>ระบบลงทะเบียนพระอภิธรรม</h1>
    <?= publicNav($mysqli) ?>

    <?php if ($showLanding): ?>
        <dialog id="landing" class="landing-dialog">
            <a href="registration-form.php">
                <img src="assets/images/landingpage.jpg" width="737" height="926"
                     alt="มูลนิธิพระอภิธรรมวัดศรีสุดาราม เปิดรับสมัครนักศึกษารุ่นใหม่ ชั้นจูฬตรี พระอภิธรรมออนไลน์ เรียนฟรี ไม่มีค่าใช้จ่าย รับตำราเรียนตลอดหลักสูตร">
            </a>
            <div class="action-row">
                <a class="cta-button" href="registration-form.php">ลงทะเบียนเลย</a>
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

    <?php if ($batch): ?>
        <div class="card announcement">
            <h2>เปิดรับสมัครนักศึกษาใหม่</h2>
            <p><?= htmlspecialchars($batch['name'] ?: ('รุ่น ' . $batch['batch_no'])) ?> ชั้นจูฬตรี
                เรียนฟรี ไม่มีค่าใช้จ่าย</p>
            <p><a class="cta-button" href="registration-form.php">ลงทะเบียนเลย</a></p>
        </div>
    <?php endif; ?>

    <?php if ($bookClasses !== []): ?>
        <div class="card announcement">
            <h2>ยืนยันการรับหนังสือ</h2>
            <p>นักศึกษาปัจจุบัน: เปิดให้ยืนยันที่อยู่สำหรับจัดส่งหนังสือเรียนแล้ว สำหรับชั้นเรียน</p>
            <ul>
                <?php foreach ($bookClasses as $class): ?>
                    <li><?= htmlspecialchars(($class['batch_name'] ?: 'รุ่น ' . $class['batch_no']) . ' - ' . $class['level_name']) ?></li>
                <?php endforeach; ?>
            </ul>
            <p><a class="cta-button" href="books.php">ยืนยันการรับหนังสือ</a></p>
        </div>
    <?php endif; ?>
</body>
</html>
