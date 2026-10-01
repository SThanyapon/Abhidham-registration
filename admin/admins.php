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
$editId = (int) ($_GET['edit'] ?? 0);

// Shared by create and reset: returns a Thai error message, or null if the password is acceptable.
// Passwords are never cleaned.
function passwordError(string $password, string $confirm): ?string
{
    if (strlen($password) < 8) {
        return 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    }
    if ($password !== $confirm) {
        return 'รหัสผ่านและการยืนยันรหัสผ่านไม่ตรงกัน';
    }

    return null;
}

function findAdmin(mysqli $mysqli, int $id): ?array
{
    $stmt = $mysqli->prepare('SELECT id, username, email, is_active FROM admin_users WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = cleanText($_POST['username'] ?? '');
        $email = cleanCode($_POST['email'] ?? '');
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
        } elseif (($error = passwordError($password, $passwordConfirm)) !== null) {
            // $error already set
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
    } elseif (in_array($action, ['reset_password', 'toggle_active', 'delete'], true)) {
        $targetId = (int) ($_POST['target_id'] ?? 0);
        $target = findAdmin($mysqli, $targetId);
        $editId = $targetId;

        if (!$target) {
            $error = 'ไม่พบบัญชีผู้ดูแลระบบ';
            $editId = 0;
        } elseif ($action !== 'reset_password' && $targetId === $adminId) {
            // The acting admin is active and holds feature 0, so refusing self-disable/delete
            // guarantees at least one active admin who can manage accounts always remains.
            $error = 'ไม่สามารถปิดการใช้งานหรือลบบัญชีของตนเองได้';
        } elseif ($action === 'reset_password') {
            $password = (string) ($_POST['password'] ?? '');
            $error = passwordError($password, (string) ($_POST['password_confirm'] ?? ''));

            if ($error === null) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
                $stmt->bind_param('si', $hash, $targetId);
                $stmt->execute();
                $stmt->close();

                // Any OTP issued under the old password becomes useless.
                $stmt = $mysqli->prepare('DELETE FROM otp_codes WHERE admin_user_id = ?');
                $stmt->bind_param('i', $targetId);
                $stmt->execute();
                $stmt->close();

                $notice = "รีเซ็ตรหัสผ่านของ '{$target['username']}' เรียบร้อยแล้ว";
            }
        } elseif ($action === 'toggle_active') {
            $stmt = $mysqli->prepare('UPDATE admin_users SET is_active = NOT is_active WHERE id = ?');
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $stmt->close();

            $notice = $target['is_active']
                ? "ปิดการใช้งานบัญชี '{$target['username']}' แล้ว"
                : "เปิดการใช้งานบัญชี '{$target['username']}' แล้ว";
        } else {
            // promotions.promoted_by is a NOT NULL audit trail; keep it intact.
            $stmt = $mysqli->prepare('SELECT COUNT(*) FROM promotions WHERE promoted_by = ?');
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $promotionCount = (int) $stmt->get_result()->fetch_row()[0];
            $stmt->close();

            if ($promotionCount > 0) {
                $error = 'บัญชีนี้มีประวัติการเลื่อนชั้นนักศึกษา จึงลบไม่ได้ กรุณาใช้ปิดการใช้งานแทน';
            } else {
                $mysqli->begin_transaction();
                try {
                    foreach (['otp_codes', 'admin_permissions'] as $table) {
                        $stmt = $mysqli->prepare("DELETE FROM $table WHERE admin_user_id = ?");
                        $stmt->bind_param('i', $targetId);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $stmt = $mysqli->prepare('DELETE FROM admin_users WHERE id = ?');
                    $stmt->bind_param('i', $targetId);
                    $stmt->execute();
                    $stmt->close();

                    $mysqli->commit();
                    $notice = "ลบบัญชี '{$target['username']}' เรียบร้อยแล้ว";
                    $editId = 0;
                } catch (mysqli_sql_exception $e) {
                    $mysqli->rollback();
                    $error = 'ไม่สามารถลบบัญชีได้ กรุณาใช้ปิดการใช้งานแทน';
                }
            }
        }
    }
}

$editing = $editId > 0 ? findAdmin($mysqli, $editId) : null;

$admins = $mysqli->query(
    'SELECT u.id, u.username, u.email, u.is_active, u.created_at,
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
        <input type="hidden" name="action" value="create">
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

    <?php if ($editing): ?>
        <?php $isSelf = (int) $editing['id'] === $adminId; ?>
        <h2>จัดการบัญชี: <?= htmlspecialchars($editing['username']) ?></h2>
        <div class="card">
            <p>
                อีเมล: <?= htmlspecialchars($editing['email']) ?><br>
                สถานะ: <strong><?= $editing['is_active'] ? 'ใช้งาน' : 'ปิดใช้งาน' ?></strong>
            </p>
        </div>

        <h3>รีเซ็ตรหัสผ่าน</h3>
        <form action="admins.php?edit=<?= (int) $editing['id'] ?>" method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="target_id" value="<?= (int) $editing['id'] ?>">
            <label for="reset_password">รหัสผ่านใหม่ (อย่างน้อย 8 ตัวอักษร)</label>
            <input type="password" id="reset_password" name="password" required minlength="8" autocomplete="new-password">
            <label for="reset_password_confirm">ยืนยันรหัสผ่านใหม่</label>
            <input type="password" id="reset_password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
            <button type="submit">รีเซ็ตรหัสผ่าน</button>
        </form>

        <?php if ($isSelf): ?>
            <p class="form-note">ไม่สามารถปิดการใช้งานหรือลบบัญชีของตนเองได้</p>
        <?php else: ?>
            <h3>เปิด/ปิดการใช้งาน</h3>
            <div class="action-row">
                <form action="admins.php?edit=<?= (int) $editing['id'] ?>" method="post" class="inline-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="target_id" value="<?= (int) $editing['id'] ?>">
                    <?php if ($editing['is_active']): ?>
                        <button type="submit" class="danger">ปิดการใช้งาน</button>
                    <?php else: ?>
                        <button type="submit">เปิดการใช้งาน</button>
                    <?php endif; ?>
                </form>
            </div>

            <h3>ลบบัญชี</h3>
            <p class="form-note">บัญชีที่มีประวัติการเลื่อนชั้นนักศึกษาจะลบไม่ได้ ให้ใช้ปิดการใช้งานแทน</p>
            <div class="action-row">
                <form action="admins.php" method="post" class="inline-form"
                      onsubmit="return confirm(<?= htmlspecialchars(json_encode('ยืนยันการลบบัญชี ' . $editing['username'] . ' ?', JSON_UNESCAPED_UNICODE)) ?>)">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="target_id" value="<?= (int) $editing['id'] ?>">
                    <button type="submit" class="danger">ลบบัญชี</button>
                </form>
            </div>
        <?php endif; ?>

        <p><a href="admins.php">ปิดการจัดการบัญชีนี้</a></p>
    <?php endif; ?>

    <h2>ผู้ดูแลระบบทั้งหมด</h2>
    <div class="table-wrap"><table>
        <thead>
            <tr><th>ชื่อผู้ใช้</th><th>อีเมล</th><th>สถานะ</th><th>สิทธิ์</th><th>วันที่สร้าง</th><th>จัดการ</th></tr>
        </thead>
        <tbody>
            <?php foreach ($admins as $admin): ?>
                <tr>
                    <td><?= htmlspecialchars($admin['username']) ?></td>
                    <td><?= htmlspecialchars($admin['email']) ?></td>
                    <td><?= $admin['is_active'] ? 'ใช้งาน' : 'ปิดใช้งาน' ?></td>
                    <td><?= htmlspecialchars($admin['features'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($admin['created_at']) ?></td>
                    <td><a href="admins.php?edit=<?= (int) $admin['id'] ?>">จัดการ</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</body>
</html>
