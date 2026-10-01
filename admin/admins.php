<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/input.php';

$adminId = requireAdminLogin();
requireFeature($adminId, 0);

$featureLabels = [
    0 => 'จัดการผู้ดูแลระบบ',
    1 => 'จัดการชั้นเรียนและตารางเรียน',
    2 => 'อนุมัติการลงทะเบียน',
    3 => 'รายงาน',
    4 => 'การเลื่อนชั้นนักศึกษา',
    6 => 'นำเข้ารายชื่อนักศึกษา (CSV)',
    7 => 'แก้ไขข้อมูลนักศึกษา',
    9 => 'การสำรองข้อมูล',
];

$mysqli = getDbConnection();
$notice = null;
$error = null;
$old = ['username' => '', 'email' => '', 'features' => array_keys($featureLabels)];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $username = cleanText($_POST['username'] ?? '');
    $email = cleanCode($_POST['email'] ?? '');
    // Passwords are never cleaned.
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $features = array_values(array_intersect(
        array_keys($featureLabels),
        array_map('intval', (array) ($_POST['features'] ?? []))
    ));
    $old = ['username' => $username, 'email' => $email, 'features' => $features];

    if ($username === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้';
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = 'กรุณากรอกอีเมลให้ถูกต้อง (ใช้รับรหัส OTP สำหรับเข้าสู่ระบบ)';
    } elseif (strlen($password) < 8) {
        $error = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    } elseif ($password !== $passwordConfirm) {
        $error = 'รหัสผ่านและการยืนยันรหัสผ่านไม่ตรงกัน';
    } elseif ($features === []) {
        $error = 'กรุณาเลือกสิทธิ์การใช้งานอย่างน้อย 1 รายการ';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $mysqli->begin_transaction();
        try {
            $stmt = $mysqli->prepare('INSERT INTO admin_users (username, email, password_hash) VALUES (?, ?, ?)');
            $stmt->bind_param('sss', $username, $email, $hash);
            $stmt->execute();
            $newAdminId = $stmt->insert_id;
            $stmt->close();

            $permStmt = $mysqli->prepare('INSERT INTO admin_permissions (admin_user_id, feature) VALUES (?, ?)');
            foreach ($features as $feature) {
                $permStmt->bind_param('ii', $newAdminId, $feature);
                $permStmt->execute();
            }
            $permStmt->close();

            $mysqli->commit();
            $notice = "เพิ่มผู้ดูแลระบบ '$username' เรียบร้อยแล้ว";
            $old = ['username' => '', 'email' => '', 'features' => array_keys($featureLabels)];
        } catch (mysqli_sql_exception $e) {
            $mysqli->rollback();
            $error = 'ไม่สามารถเพิ่มผู้ดูแลระบบได้ (ชื่อผู้ใช้หรืออีเมลนี้อาจมีอยู่แล้ว)';
        }
    }
}

$admins = $mysqli->query(
    'SELECT u.username, u.email, u.is_active, u.created_at,
            GROUP_CONCAT(p.feature ORDER BY p.feature SEPARATOR ", ") AS features
     FROM admin_users u
     LEFT JOIN admin_permissions p ON p.admin_user_id = u.id
     GROUP BY u.id
     ORDER BY u.id'
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap">
    <title>จัดการผู้ดูแลระบบ - ระบบลงทะเบียนอภิธรรม</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body class="admin">
    <h1>จัดการผู้ดูแลระบบ</h1>
    <nav>
        <a href="dashboard.php">กลับหน้าแผงควบคุม</a>
        <a class="nav-logout" href="logout.php">ออกจากระบบ</a>
    </nav>

    <?php if ($notice): ?><p class="success"><?= htmlspecialchars($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <h2>เพิ่มผู้ดูแลระบบ</h2>
    <form action="admins.php" method="post">
        <?= csrfField() ?>
        <p class="form-note"><span class="required-mark">*</span> จำเป็นต้องกรอก</p>

        <label for="username">ชื่อผู้ใช้<span class="required-mark">*</span></label>
        <input type="text" id="username" name="username" required autocomplete="off"
               value="<?= htmlspecialchars($old['username']) ?>">

        <label for="email">อีเมล (ใช้รับรหัส OTP)<span class="required-mark">*</span></label>
        <input type="email" id="email" name="email" required value="<?= htmlspecialchars($old['email']) ?>">

        <label for="password">รหัสผ่าน (อย่างน้อย 8 ตัวอักษร)<span class="required-mark">*</span></label>
        <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">

        <label for="password_confirm">ยืนยันรหัสผ่าน<span class="required-mark">*</span></label>
        <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">

        <label>สิทธิ์การใช้งาน<span class="required-mark">*</span></label>
        <div>
            <?php foreach ($featureLabels as $number => $label): ?>
                <label class="checkbox-label">
                    <input type="checkbox" name="features[]" value="<?= $number ?>"
                           <?= in_array($number, $old['features'], true) ? 'checked' : '' ?>>
                    <?= $number ?>) <?= htmlspecialchars($label) ?>
                </label>
            <?php endforeach; ?>
        </div>

        <button type="submit">เพิ่มผู้ดูแลระบบ</button>
    </form>

    <h2>ผู้ดูแลระบบทั้งหมด</h2>
    <div class="table-wrap"><table>
        <thead>
            <tr><th>ชื่อผู้ใช้</th><th>อีเมล</th><th>สถานะ</th><th>สิทธิ์</th><th>วันที่สร้าง</th></tr>
        </thead>
        <tbody>
            <?php foreach ($admins as $admin): ?>
                <tr>
                    <td><?= htmlspecialchars($admin['username']) ?></td>
                    <td><?= htmlspecialchars($admin['email']) ?></td>
                    <td><?= $admin['is_active'] ? 'ใช้งาน' : 'ปิดใช้งาน' ?></td>
                    <td><?= htmlspecialchars($admin['features'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($admin['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</body>
</html>
