<?php

require_once __DIR__ . '/student_validation.php';

/**
 * HTML for one STUDENT_CHOICE_FIELDS question, using its control type: radio buttons, a single-choice
 * dropdown, or the browser's multi-select ('multi', posted as {field}[]). Followed by the {field}_other
 * text box, which is shown (and required) only while อื่นๆ is picked. $value is the stored answer
 * ('multi' answers joined with STUDENT_MULTI_SEPARATOR). Shared by index.php and admin/students.php;
 * the page must also output studentChoiceScript().
 */
function studentChoiceField(string $field, string $caption, string $value, string $other, bool $required): string
{
    [, $options, $control] = STUDENT_CHOICE_FIELDS[$field];
    $picked = choiceValues($value);
    $mark = $required ? '<span class="required-mark">*</span>' : '';
    $label = fn (string $option): string => htmlspecialchars($option === 'อื่นๆ' ? 'อื่นๆ (ระบุ)' : $option);
    $onchange = ' onchange="toggleChoiceOther(\'' . $field . '\')"';

    if ($control === 'select') {
        $html = '<label for="' . $field . '">' . htmlspecialchars($caption) . $mark . '</label>'
            . '<select id="' . $field . '" name="' . $field . '"' . ($required ? ' required' : '') . $onchange . '>'
            . '<option value="">-- เลือก --</option>';
        foreach ($options as $option) {
            $html .= '<option value="' . htmlspecialchars($option) . '"' . (in_array($option, $picked, true) ? ' selected' : '')
                . '>' . $label($option) . '</option>';
        }
        $html .= '</select>';
    } elseif ($control === 'multi') {
        // The browser's own multi-select, tall enough to show every option.
        $html = '<label for="' . $field . '">' . htmlspecialchars($caption) . $mark . '</label>'
            . '<select id="' . $field . '" name="' . $field . '[]" multiple size="' . count($options) . '"'
            . ($required ? ' required' : '') . $onchange . '>';
        foreach ($options as $option) {
            $html .= '<option value="' . htmlspecialchars($option) . '"' . (in_array($option, $picked, true) ? ' selected' : '')
                . '>' . $label($option) . '</option>';
        }
        $html .= '</select><p class="form-note">เลือกได้หลายข้อ (คอมพิวเตอร์: กด Ctrl หรือ ⌘ ค้างไว้แล้วคลิก)</p>';
    } else {
        $html = '<label>' . htmlspecialchars($caption) . $mark . '</label>';
        foreach ($options as $option) {
            $html .= '<label class="checkbox-label"><input type="radio" name="' . $field . '" value="'
                . htmlspecialchars($option) . '"' . (in_array($option, $picked, true) ? ' checked' : '')
                . ($required ? ' required' : '') . $onchange . '> ' . $label($option) . '</label>';
        }
    }

    $isOther = in_array('อื่นๆ', $picked, true);
    $html .= '<input type="text" id="' . $field . '_other" name="' . $field . '_other" aria-label="โปรดระบุ"'
        . ' placeholder="โปรดระบุ" value="' . htmlspecialchars($other) . '"' . ($isOther ? ' required' : ' hidden') . '>';

    return '<div class="field-group choice-field">' . $html . '</div>';
}

/**
 * HTML for the จังหวัด dropdown (THAI_PROVINCES + PROVINCE_ABROAD) and the รหัสไปรษณีย์ box, which is
 * hidden and disabled while PROVINCE_ABROAD is picked (provinceScript() keeps that in sync). A
 * stored province that isn't in the list (an older free-text answer) is kept as an extra option so
 * the admin edit page doesn't lose it. Shared by index.php and admin/students.php.
 */
function provinceFields(string $province, string $postalCode, bool $required): string
{
    $mark = $required ? '<span class="required-mark">*</span>' : '';
    $choices = [...THAI_PROVINCES, PROVINCE_ABROAD];
    if ($province !== '' && !in_array($province, $choices, true)) {
        array_unshift($choices, $province);
    }

    $html = '<label for="province">จังหวัด' . $mark . '</label>'
        . '<select id="province" name="province"' . ($required ? ' required' : '') . ' onchange="syncPostalCode()">'
        . '<option value="">-- เลือกจังหวัด --</option>';
    foreach ($choices as $choice) {
        $html .= '<option value="' . htmlspecialchars($choice) . '"' . ($choice === $province ? ' selected' : '')
            . '>' . htmlspecialchars($choice) . '</option>';
    }
    $html .= '</select>';

    $abroad = $province === PROVINCE_ABROAD;

    return $html
        . '<div id="postal_code_wrap" class="field-group"' . ($abroad ? ' hidden' : '') . '>'
        . '<label for="postal_code">รหัสไปรษณีย์' . $mark . '</label>'
        . '<input type="text" id="postal_code" name="postal_code" inputmode="numeric"' . ($required ? ' required' : '')
        . ' pattern="[0-9]{5}" maxlength="5" title="ตัวเลข 5 หลัก" value="' . htmlspecialchars($postalCode) . '"'
        . ($abroad ? ' disabled' : '') . '>'
        . '</div>';
}

// Script for provinceFields(): hides and disables the postal code box while อยู่ต่างประเทศ is picked.
function provinceScript(): string
{
    $abroad = json_encode(PROVINCE_ABROAD, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

    return <<<HTML
        <script>
            function syncPostalCode() {
                const abroad = document.getElementById('province').value === $abroad;
                const input = document.getElementById('postal_code');
                document.getElementById('postal_code_wrap').hidden = abroad;
                input.disabled = abroad;
                if (abroad) {
                    input.value = '';
                }
            }
        </script>
        HTML;
}

/**
 * Whether the previous_student_no field (#previous_student_no_wrap) should be shown and enabled:
 * only for a returning student. studentChoiceScript() keeps it in sync when the dropdown changes.
 */
function isReturningStudent(string $studentType): bool
{
    return $studentType === STUDENT_TYPE_RETURNING;
}

/**
 * Script for studentChoiceField(): toggles each อื่นๆ text box, and shows/enables
 * previous_student_no only for a returning student.
 */
function studentChoiceScript(): string
{
    $returning = json_encode(STUDENT_TYPE_RETURNING, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

    return <<<HTML
        <script>
            function choiceValues(field) {
                return Array.from(document.querySelectorAll(
                    'input[name="' + field + '"]:checked, select[name="' + field + '"] option:checked, '
                        + 'select[name="' + field + '[]"] option:checked'
                )).map(function (el) { return el.value; }).filter(function (v) { return v !== ''; });
            }

            function toggleChoiceOther(field) {
                const isOther = choiceValues(field).indexOf('อื่นๆ') !== -1;
                const other = document.getElementById(field + '_other');
                other.hidden = !isOther;
                other.required = isOther;

                if (field === 'student_type') {
                    syncPreviousStudentNo();
                }
            }

            function syncPreviousStudentNo() {
                const wrap = document.getElementById('previous_student_no_wrap');
                const input = document.getElementById('previous_student_no');
                if (!wrap || !input) {
                    return;
                }
                const returning = choiceValues('student_type').indexOf($returning) !== -1;
                wrap.hidden = !returning;
                input.disabled = !returning;
                if (!returning) {
                    input.value = '';
                }
            }
        </script>
        HTML;
}
