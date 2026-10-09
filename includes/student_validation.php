<?php

require_once __DIR__ . '/input.php';

const STUDENT_PREFIXES = ['พระ', 'สิกขมานา', 'สามเณร', 'สามเณรี', 'แม่ชี', 'นาย', 'นาง', 'นางสาว', 'อื่นๆ'];

// The province dropdown on the registration form: Thailand's 77 provinces (in province-code order,
// from the provinces.json list the course supplied), then PROVINCE_ABROAD.
const THAI_PROVINCES = [
    'กรุงเทพมหานคร', 'สมุทรปราการ', 'นนทบุรี', 'ปทุมธานี', 'พระนครศรีอยุธยา', 'อ่างทอง', 'ลพบุรี',
    'สิงห์บุรี', 'ชัยนาท', 'สระบุรี', 'ชลบุรี', 'ระยอง', 'จันทบุรี', 'ตราด', 'ฉะเชิงเทรา',
    'ปราจีนบุรี', 'นครนายก', 'สระแก้ว', 'นครราชสีมา', 'บุรีรัมย์', 'สุรินทร์', 'ศรีสะเกษ',
    'อุบลราชธานี', 'ยโสธร', 'ชัยภูมิ', 'อำนาจเจริญ', 'หนองบัวลำภู', 'ขอนแก่น', 'อุดรธานี', 'เลย',
    'หนองคาย', 'มหาสารคาม', 'ร้อยเอ็ด', 'กาฬสินธุ์', 'สกลนคร', 'นครพนม', 'มุกดาหาร', 'เชียงใหม่',
    'ลำพูน', 'ลำปาง', 'อุตรดิตถ์', 'แพร่', 'น่าน', 'พะเยา', 'เชียงราย', 'แม่ฮ่องสอน', 'นครสวรรค์',
    'อุทัยธานี', 'กำแพงเพชร', 'ตาก', 'สุโขทัย', 'พิษณุโลก', 'พิจิตร', 'เพชรบูรณ์', 'ราชบุรี',
    'กาญจนบุรี', 'สุพรรณบุรี', 'นครปฐม', 'สมุทรสาคร', 'สมุทรสงคราม', 'เพชรบุรี', 'ประจวบคีรีขันธ์',
    'นครศรีธรรมราช', 'กระบี่', 'พังงา', 'ภูเก็ต', 'สุราษฎร์ธานี', 'ระนอง', 'ชุมพร', 'สงขลา', 'สตูล',
    'ตรัง', 'พัทลุง', 'ปัตตานี', 'ยะลา', 'นราธิวาส', 'บึงกาฬ',
];

// Province answer for students living outside Thailand: no Thai postal code applies.
const PROVINCE_ABROAD = 'อยู่ต่างประเทศ';

// Pre-selected in the registration form's province dropdown.
const DEFAULT_PROVINCE = 'กรุงเทพมหานคร';

// The student_type answer that enables previous_student_no (returning student).
const STUDENT_TYPE_RETURNING = 'เก่า (เคยเรียนที่วัดศรีสุดาฯ แต่ จะมาเรียนใหม่อีกรอบ)';

// Separator for a 'multi' answer stored in one column (options contain no commas).
const STUDENT_MULTI_SEPARATOR = ', ';

// Multiple-choice questions from the registration form: field => [label for messages, options,
// control]. control is 'radio', 'select' (single-choice dropdown) or 'multi' (dropdown of
// checkboxes, stored as the picked options joined with STUDENT_MULTI_SEPARATOR). Each ends with
// อื่นๆ, whose free text is stored in the matching {field}_other column.
const STUDENT_CHOICE_FIELDS = [
    'heard_from' => ['ช่องทางที่ทราบข่าว', ['เฟสบุ๊ค', 'กลุ่มไลน์', 'Tiktok (ติ๊กต๊อก)', 'เพื่อนแนะนำ', 'อื่นๆ'], 'multi'],
    'student_type' => ['นักศึกษาเก่าหรือใหม่', ['ใหม่ (ไม่เคยเรียนกับที่นี่)', STUDENT_TYPE_RETURNING, 'อื่นๆ'], 'select'],
    'zoom_skill' => ['การใช้ ZOOM', ['ใช้เป็น', 'ไม่เป็น', 'อื่นๆ'], 'radio'],
    'joined_classroom' => ['การเข้าห้องเรียน', ['เข้าแล้ว', 'ยังไม่เข้า', 'อื่นๆ'], 'radio'],
];

// The students columns validateStudentFields() produces, in table order. register.php,
// admin/import_students.php and admin/students.php build their INSERT/UPDATE from this list.
const STUDENT_DETAIL_COLUMNS = [
    'prefix', 'prefix_other', 'full_name', 'age', 'address', 'province', 'postal_code', 'phone',
    'line_name', 'line_id', 'heard_from', 'heard_from_other', 'student_type', 'student_type_other',
    'previous_student_no', 'reference_person', 'study_reason', 'zoom_skill', 'zoom_skill_other',
    'joined_classroom', 'joined_classroom_other',
];

// Optional columns stored as NULL rather than '' when left empty.
const STUDENT_NULLABLE_FIELDS = [
    'province', 'postal_code', 'line_name', 'previous_student_no', 'study_reason',
    'heard_from', 'student_type', 'zoom_skill', 'joined_classroom',
];

/**
 * Cleans and validates the registration-form fields (prefix, name, age, address, province, postal
 * code, phone, LINE name/ID, the STUDENT_CHOICE_FIELDS questions, previous student ID, referrer,
 * study reason). Shared by register.php, admin/import_students.php and admin/students.php so all
 * three apply the same rules.
 *
 * $requireRegistrationExtras makes the questions copied from the Google Form (province, postal code,
 * LINE name/ID, the choices, study reason) mandatory. Only register.php sets it: the CSV import and
 * the admin edit page must keep working for students stored before those fields existed, so there
 * they're optional but still format-checked when filled.
 *
 * A choice value that isn't one of its options is treated as อื่นๆ with that value as the free text
 * (so a CSV can carry the answer in one column); a 'multi' answer may be an array (form checkboxes)
 * or a comma-separated string (CSV). previous_student_no is dropped unless student_type is
 * STUDENT_TYPE_RETURNING.
 *
 * Returns [$clean, $error]: $clean holds the cleaned values (always, so a form can be refilled). When
 * valid, prefix_other / {choice}_other are null unless อื่นๆ was chosen, empty optional fields are
 * null and age is an int; $error is null when valid, otherwise the first Thai error message.
 */
function validateStudentFields(array $raw, bool $requireRegistrationExtras = false): array
{
    $clean = [
        'prefix' => trim((string) ($raw['prefix'] ?? '')),
        'prefix_other' => cleanText((string) ($raw['prefix_other'] ?? '')),
        'full_name' => cleanText((string) ($raw['full_name'] ?? '')),
        'age' => trim((string) ($raw['age'] ?? '')),
        'address' => cleanMultilineText((string) ($raw['address'] ?? '')),
        'province' => cleanText((string) ($raw['province'] ?? '')),
        'postal_code' => cleanCode((string) ($raw['postal_code'] ?? '')),
        'phone' => cleanText((string) ($raw['phone'] ?? '')),
        'line_name' => cleanText((string) ($raw['line_name'] ?? '')),
        'line_id' => cleanText((string) ($raw['line_id'] ?? '')),
        'previous_student_no' => cleanCode((string) ($raw['previous_student_no'] ?? '')),
        'reference_person' => cleanText((string) ($raw['reference_person'] ?? '')),
        'study_reason' => cleanMultilineText((string) ($raw['study_reason'] ?? '')),
    ];

    foreach (STUDENT_CHOICE_FIELDS as $field => [, $options, $control]) {
        $clean[$field . '_other'] = cleanText((string) ($raw[$field . '_other'] ?? ''));

        if ($control === 'multi') {
            // An array from the form's checkboxes, or a comma-separated string from a CSV.
            $items = $raw[$field] ?? [];
            if (!is_array($items)) {
                $items = explode(',', (string) $items);
            }
            $picked = [];
            $unknown = [];
            foreach ($items as $item) {
                $item = cleanText((string) $item);
                if ($item === '') {
                    continue;
                }
                if (in_array($item, $options, true)) {
                    $picked[$item] = true;
                } else {
                    $picked['อื่นๆ'] = true;
                    $unknown[] = $item;
                }
            }
            if ($unknown !== []) {
                $clean[$field . '_other'] = implode(STUDENT_MULTI_SEPARATOR, array_filter(
                    [$clean[$field . '_other'], ...$unknown],
                    fn (string $text): bool => $text !== ''
                ));
            }
            // Stored in the options' order, whatever order they were ticked in.
            $clean[$field] = implode(STUDENT_MULTI_SEPARATOR, array_filter(
                $options,
                fn (string $option): bool => isset($picked[$option])
            ));
            continue;
        }

        $clean[$field] = cleanText((string) ($raw[$field] ?? ''));
        if ($clean[$field] !== '' && !in_array($clean[$field], $options, true)) {
            $clean[$field . '_other'] = $clean[$field];
            $clean[$field] = 'อื่นๆ';
        }
    }

    // A Thai postal code doesn't apply abroad (the form hides the box); the address holds it all.
    $abroad = $clean['province'] === PROVINCE_ABROAD;
    if ($abroad) {
        $clean['postal_code'] = '';
    }

    // Only a returning student has a previous ID (the form disables the box otherwise).
    if ($clean['student_type'] !== STUDENT_TYPE_RETURNING) {
        $clean['previous_student_no'] = '';
    }

    $missing = [];
    if (!in_array($clean['prefix'], STUDENT_PREFIXES, true)) {
        $missing[] = 'คำนำหน้า';
    } elseif ($clean['prefix'] === 'อื่นๆ' && $clean['prefix_other'] === '') {
        $missing[] = 'ระบุคำนำหน้า';
    }
    if ($clean['full_name'] === '') {
        $missing[] = 'ชื่อ-สกุล';
    }
    if ($clean['age'] === '') {
        $missing[] = 'อายุ';
    }
    if ($clean['address'] === '') {
        $missing[] = 'ที่อยู่';
    }
    if ($requireRegistrationExtras && $clean['province'] === '') {
        $missing[] = 'จังหวัด';
    }
    if ($requireRegistrationExtras && !$abroad && $clean['postal_code'] === '') {
        $missing[] = 'รหัสไปรษณีย์';
    }
    if ($clean['phone'] === '') {
        $missing[] = 'เบอร์มือถือ';
    }
    if ($requireRegistrationExtras && $clean['line_name'] === '') {
        $missing[] = 'ชื่อไลน์';
    }
    if ($requireRegistrationExtras && $clean['line_id'] === '') {
        $missing[] = 'LINE ID';
    }
    foreach (STUDENT_CHOICE_FIELDS as $field => [$label]) {
        if ($clean[$field] === '' && $requireRegistrationExtras) {
            $missing[] = $label;
        } elseif (choiceIncludesOther($clean[$field]) && $clean[$field . '_other'] === '') {
            $missing[] = 'ระบุ' . $label;
        }
    }
    if ($requireRegistrationExtras && $clean['study_reason'] === '') {
        $missing[] = 'เหตุผลที่มาเรียน';
    }

    if ($missing !== []) {
        return [$clean, 'กรุณากรอกข้อมูลให้ครบถ้วน: ' . implode(', ', $missing)];
    }

    if (!isValidPersonName($clean['full_name'])) {
        return [$clean, 'ชื่อ-สกุล ใช้ได้เฉพาะอักษรไทย อักษรอังกฤษ ตัวเลข ขีด (-) และช่องว่างเท่านั้น'];
    }

    if (!ctype_digit($clean['age']) || (int) $clean['age'] < 1 || (int) $clean['age'] > 120) {
        return [$clean, 'กรุณากรอกอายุเป็นตัวเลข 1-120'];
    }

    // Public registration picks from the dropdown; the CSV import and admin edit keep free text
    // because rows stored before the dropdown existed may hold other spellings.
    if ($requireRegistrationExtras && !in_array($clean['province'], [...THAI_PROVINCES, PROVINCE_ABROAD], true)) {
        return [$clean, 'กรุณาเลือกจังหวัดจากรายการ'];
    }

    if ($clean['postal_code'] !== '' && !preg_match('/^[0-9]{5}$/', $clean['postal_code'])) {
        return [$clean, 'กรุณากรอกรหัสไปรษณีย์เป็นตัวเลข 5 หลัก'];
    }

    // Digits, spaces, '-' and '+' only, with 9-15 digits (Thai landline/mobile, or +66...).
    $phoneDigits = preg_replace('/\D/', '', $clean['phone']);
    if (!preg_match('/^\+?[0-9 \-]+$/', $clean['phone']) || strlen($phoneDigits) < 9 || strlen($phoneDigits) > 15) {
        return [$clean, 'กรุณากรอกเบอร์มือถือให้ถูกต้อง'];
    }

    if ($clean['previous_student_no'] !== '' && !preg_match('/^[0-9]{4,10}$/', $clean['previous_student_no'])) {
        return [$clean, 'รหัสนักศึกษาเดิมต้องเป็นตัวเลข 4-10 หลัก'];
    }

    $clean['age'] = (int) $clean['age'];
    if ($clean['prefix'] !== 'อื่นๆ') {
        $clean['prefix_other'] = null;
    }
    foreach (array_keys(STUDENT_CHOICE_FIELDS) as $field) {
        if (!choiceIncludesOther($clean[$field])) {
            $clean[$field . '_other'] = null;
        }
    }
    foreach (STUDENT_NULLABLE_FIELDS as $field) {
        if ($clean[$field] === '') {
            $clean[$field] = null;
        }
    }

    return [$clean, null];
}

// The options picked in a stored choice answer ('multi' answers hold several).
function choiceValues(?string $value): array
{
    return ($value ?? '') === '' ? [] : explode(STUDENT_MULTI_SEPARATOR, $value);
}

// Whether a stored choice answer includes อื่นๆ (so its {field}_other text applies).
function choiceIncludesOther(?string $value): bool
{
    return in_array('อื่นๆ', choiceValues($value), true);
}

/**
 * bind_param types and values for STUDENT_DETAIL_COLUMNS, taken from validateStudentFields()'s
 * valid $clean result: returns [$types, $values].
 */
function studentDetailParams(array $clean): array
{
    $types = '';
    $values = [];
    foreach (STUDENT_DETAIL_COLUMNS as $column) {
        $types .= $column === 'age' ? 'i' : 's';
        $values[] = $clean[$column];
    }

    return [$types, $values];
}
