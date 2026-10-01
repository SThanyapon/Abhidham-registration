<?php

require_once __DIR__ . '/input.php';

const STUDENT_PREFIXES = ['พระ', 'สิกขามานา', 'สามเณร', 'สามเณรี', 'แม่ชี', 'นาย', 'นาง', 'นางสาว', 'อื่นๆ'];

/**
 * Cleans and validates the registration-form fields (prefix, prefix_other, full_name, age, address,
 * phone, line_id, reference_person). Shared by register.php, admin/import_students.php and
 * admin/students.php so all three apply the same rules.
 *
 * Returns [$clean, $error]: $clean holds the cleaned values (always, so a form can be refilled), with
 * prefix_other set to null unless prefix is อื่นๆ and age as an int when valid; $error is null when
 * valid, otherwise the first Thai error message.
 */
function validateStudentFields(array $raw): array
{
    $clean = [
        'prefix' => trim((string) ($raw['prefix'] ?? '')),
        'prefix_other' => cleanText((string) ($raw['prefix_other'] ?? '')),
        'full_name' => cleanText((string) ($raw['full_name'] ?? '')),
        'age' => trim((string) ($raw['age'] ?? '')),
        'address' => cleanMultilineText((string) ($raw['address'] ?? '')),
        'phone' => cleanText((string) ($raw['phone'] ?? '')),
        'line_id' => cleanText((string) ($raw['line_id'] ?? '')),
        'reference_person' => cleanText((string) ($raw['reference_person'] ?? '')),
    ];

    $missing = [];
    if (!in_array($clean['prefix'], STUDENT_PREFIXES, true)) {
        $missing[] = 'คำนำหน้า';
    } elseif ($clean['prefix'] === 'อื่นๆ' && $clean['prefix_other'] === '') {
        $missing[] = 'ระบุคำนำหน้า';
    }
    if ($clean['full_name'] === '') {
        $missing[] = 'ชื่อ-นามสกุล';
    }
    if ($clean['age'] === '') {
        $missing[] = 'อายุ';
    }
    if ($clean['address'] === '') {
        $missing[] = 'ที่อยู่';
    }
    if ($clean['phone'] === '') {
        $missing[] = 'เบอร์โทรศัพท์';
    }

    if ($missing !== []) {
        return [$clean, 'กรุณากรอกข้อมูลให้ครบถ้วน: ' . implode(', ', $missing)];
    }

    if (!isValidPersonName($clean['full_name'])) {
        return [$clean, 'ชื่อ-นามสกุล ใช้ได้เฉพาะอักษรไทย อักษรอังกฤษ ตัวเลข ขีด (-) และช่องว่างเท่านั้น'];
    }

    if (!ctype_digit($clean['age']) || (int) $clean['age'] < 1 || (int) $clean['age'] > 120) {
        return [$clean, 'กรุณากรอกอายุเป็นตัวเลข 1-120'];
    }

    // Digits, spaces, '-' and '+' only, with 9-15 digits (Thai landline/mobile, or +66...).
    $phoneDigits = preg_replace('/\D/', '', $clean['phone']);
    if (!preg_match('/^\+?[0-9 \-]+$/', $clean['phone']) || strlen($phoneDigits) < 9 || strlen($phoneDigits) > 15) {
        return [$clean, 'กรุณากรอกเบอร์โทรศัพท์ให้ถูกต้อง'];
    }

    $clean['age'] = (int) $clean['age'];
    if ($clean['prefix'] !== 'อื่นๆ') {
        $clean['prefix_other'] = null;
    }

    return [$clean, null];
}
