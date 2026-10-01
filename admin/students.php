<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/student_validation.php';

$adminId = requireAdminLogin();
requireFeature($adminId, 7);

$mysqli = getDbConnection();
$notice = null;
$error = null;
$student = null;
$searchNo = '';
$searchName = '';

$statusLabels = ['pending' => 'รอการอนุมัติ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ไม่อนุมัติ'];

function loadStudent(mysqli $mysqli, string $where, string $types, ...$params): ?array
{
    $stmt = $mysqli->prepare(
        "SELECT s.*, b.batch_no, b.name AS batch_name, cl.name AS level_name
         FROM students s
         JOIN batches b ON b.id = s.batch_id
         LEFT JOIN class_levels cl ON cl.id = s.current_class_level_id
         WHERE $where"
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'search') {
        $searchNo = cleanCode($_POST['student_no'] ?? '');
        $searchName = cleanText($_POST['full_name'] ?? '');
        $student = loadStudent($mysqli, 's.student_no = ? AND s.full_name = ?', 'ss', $searchNo, $searchName);
        if (!$student) {
            $error = 'ไม่พบนักศึกษาที่มีรหัสและชื่อ-นามสกุลตรงกัน';
        }
    } elseif ($action === 'update') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $student = loadStudent($mysqli, 's.id = ?', 'i', $studentId);

        if (!$student) {
            $error = 'ไม่พบนักศึกษา';
        } else {
            [$clean, $validationError] = validateStudentFields($_POST);

            // Check-in and lookup match by exact name, so it must stay unique among active students.
            $dup = $mysqli->prepare(
                "SELECT 1 FROM students WHERE full_name = ? AND id <> ? AND status IN ('approved', 'pending')"
            );
            $dup->bind_param('si', $clean['full_name'], $studentId);
            $dup->execute();
            $nameTaken = (bool) $dup->get_result()->fetch_row();
            $dup->close();

            if ($validationError !== null) {
                $error = $validationError;
                $student = array_merge($student, $clean); // keep what the admin typed
            } elseif ($nameTaken) {
                $error = 'ชื่อ-นามสกุลนี้มีนักศึกษาคนอื่นใช้อยู่แล้ว';
                $student = array_merge($student, $clean);
            } else {
                // student_no, batch, level and status are deliberately not editable here.
                $update = $mysqli->prepare(
                    'UPDATE students SET prefix = ?, prefix_other = ?, full_name = ?, age = ?, address = ?,
                                         phone = ?, line_id = ?, reference_person = ?
                     WHERE id = ?'
                );
                $update->bind_param(
                    'sssissssi',
                    $clean['prefix'],
                    $clean['prefix_other'],
                    $clean['full_name'],
                    $clean['age'],
                    $clean['address'],
                    $clean['phone'],
                    $clean['line_id'],
                    $clean['reference_person'],
                    $studentId
                );
                $update->execute();
                $update->close();

                $student = loadStudent($mysqli, 's.id = ?', 'i', $studentId);
                $notice = 'บันทึกข้อมูลนักศึกษาเรียบร้อยแล้ว';
            }
        }
    }
}

$prefix = $student['prefix'] ?? '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap">
    <title>แก้ไขข้อมูลนักศึกษา - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body class="admin">
    <h1>แก้ไขข้อมูลนักศึกษา</h1>
    <nav>
        <a href="dashboard.php">กลับหน้าแผงควบคุม</a>
        <a class="nav-logout" href="logout.php">ออกจากระบบ</a>
    </nav>

    <?php if ($notice): ?><p class="success"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <form action="students.php" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="search">
        <label for="search_student_no">รหัสนักศึกษา</label>
        <input type="text" id="search_student_no" name="student_no" inputmode="numeric" required
               value="<?= htmlspecialchars($searchNo) ?>">
        <label for="search_full_name">ชื่อ-นามสกุล</label>
        <input type="text" id="search_full_name" name="full_name" required
               value="<?= htmlspecialchars($searchName) ?>">
        <button type="submit">ค้นหา</button>
    </form>

    <?php if ($student): ?>
        <div class="card">
            <p>
                รหัสนักศึกษา: <strong><?= htmlspecialchars($student['student_no'] ?? '-') ?></strong><br>
                รุ่น: <?= htmlspecialchars($student['batch_name'] ?: 'รุ่น ' . $student['batch_no']) ?><br>
                ชั้นเรียนปัจจุบัน: <?= htmlspecialchars($student['level_name'] ?? '-') ?><br>
                สถานะ: <?= htmlspecialchars($statusLabels[$student['status']] ?? $student['status']) ?>
            </p>
            <p class="form-note">รหัสนักศึกษา รุ่น ชั้นเรียน และสถานะ ไม่สามารถแก้ไขได้จากหน้านี้</p>
        </div>

        <form action="students.php" method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="student_id" value="<?= (int) $student['id'] ?>">

            <p class="form-note"><span class="required-mark">*</span> จำเป็นต้องกรอก</p>

            <label for="prefix">คำนำหน้า<span class="required-mark">*</span></label>
            <select id="prefix" name="prefix" required onchange="togglePrefixOther()">
                <?php foreach (STUDENT_PREFIXES as $p): ?>
                    <option value="<?= $p ?>" <?= $prefix === $p ? 'selected' : '' ?>><?= $p === 'อื่นๆ' ? 'อื่นๆ (ระบุ)' : $p ?></option>
                <?php endforeach; ?>
            </select>

            <div id="prefix_other_wrap" class="field-group" <?= $prefix === 'อื่นๆ' ? '' : 'hidden' ?>>
                <label for="prefix_other">โปรดระบุคำนำหน้า<span class="required-mark">*</span></label>
                <input type="text" id="prefix_other" name="prefix_other"
                       value="<?= htmlspecialchars((string) ($student['prefix_other'] ?? '')) ?>"
                       <?= $prefix === 'อื่นๆ' ? 'required' : '' ?>>
            </div>

            <label for="full_name">ชื่อ-นามสกุล<span class="required-mark">*</span></label>
            <input type="text" id="full_name" name="full_name" required
                   value="<?= htmlspecialchars($student['full_name']) ?>"
                   pattern="[ก-ฺเ-๎๐-๙A-Za-z0-9 \-‐-–]+"
                   title="ใช้ได้เฉพาะอักษรไทย อักษรอังกฤษ ตัวเลข ขีด (-) และช่องว่าง">

            <label for="age">อายุ<span class="required-mark">*</span></label>
            <input type="number" id="age" name="age" min="1" max="120" required
                   value="<?= htmlspecialchars((string) ($student['age'] ?? '')) ?>">

            <label for="address">ที่อยู่<span class="required-mark">*</span></label>
            <textarea id="address" name="address" rows="3" required><?= htmlspecialchars((string) ($student['address'] ?? '')) ?></textarea>

            <label for="phone">เบอร์โทรศัพท์<span class="required-mark">*</span></label>
            <input type="tel" id="phone" name="phone" inputmode="tel" required
                   pattern="\+?[0-9 \-]{9,20}" title="ตัวเลข 9-15 หลัก เช่น 081 234 5678"
                   value="<?= htmlspecialchars($student['phone']) ?>">

            <label for="line_id">Line ID</label>
            <input type="text" id="line_id" name="line_id" value="<?= htmlspecialchars((string) ($student['line_id'] ?? '')) ?>">

            <label for="reference_person">ผู้แนะนำ</label>
            <input type="text" id="reference_person" name="reference_person"
                   value="<?= htmlspecialchars((string) ($student['reference_person'] ?? '')) ?>">

            <button type="submit">บันทึกการแก้ไข</button>
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
