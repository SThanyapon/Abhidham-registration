<?php

require_once __DIR__ . '/db.php';

/**
 * Whether any ห้องเรียน has ยืนยันการรับหนังสือ open (admin/classes.php). Drives the books.php menu
 * link on every public page and the notification on index.php.
 */
function bookConfirmationOpen(mysqli $mysqli): bool
{
    return (bool) $mysqli->query(
        'SELECT 1 FROM class_instances WHERE book_confirmation_open = 1 LIMIT 1'
    )->fetch_row();
}

/**
 * The menu shared by the public (student) pages: index.php, registration-form.php, checkin.php,
 * lookup.php and books.php. The ยืนยันการรับหนังสือ link shows only while bookConfirmationOpen().
 */
function publicNav(mysqli $mysqli): string
{
    $books = bookConfirmationOpen($mysqli)
        ? "\n        <a href=\"books.php\">ยืนยันการรับหนังสือ</a>"
        : '';

    return <<<HTML
<nav>
        <a href="registration-form.php">ลงทะเบียน</a>
        <a href="checkin.php">ลงชื่อ/ตรวจสอบการเข้าเรียน</a>
        <a href="lookup.php">ค้นหารหัสนักศึกษา</a>{$books}
        <a class="nav-admin" href="admin/login.php">ผู้ดูแลระบบ</a>
    </nav>
HTML;
}
