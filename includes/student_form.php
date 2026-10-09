<?php

require_once __DIR__ . '/student_validation.php';

/**
 * HTML for one STUDENT_CHOICE_FIELDS question, using its control type: radio buttons, a single-choice
 * dropdown, or a dropdown of checkboxes ('multi', posted as {field}[]). Followed by the {field}_other
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
        // <details> acts as the dropdown; the summary lists what's ticked (filled in by the script).
        $html = '<label for="' . $field . '_dropdown">' . htmlspecialchars($caption) . $mark . '</label>'
            . '<details class="multi-select" id="' . $field . '_dropdown" data-field="' . $field . '"'
            . ($required ? ' data-required="1"' : '') . '>'
            . '<summary class="' . ($picked === [] ? 'placeholder' : '') . '">'
            . ($picked === [] ? '-- เลือก (เลือกได้หลายข้อ) --' : htmlspecialchars(implode(STUDENT_MULTI_SEPARATOR, $picked)))
            . '</summary><div class="multi-select-options">';
        foreach ($options as $option) {
            $html .= '<label class="checkbox-label"><input type="checkbox" name="' . $field . '[]" value="'
                . htmlspecialchars($option) . '"' . (in_array($option, $picked, true) ? ' checked' : '') . $onchange
                . '> ' . $label($option) . '</label>';
        }
        $html .= '</div></details>';
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
 * Attributes for the previous_student_no input: disabled unless $studentType is the returning
 * option (studentChoiceScript() keeps it in sync when the dropdown changes).
 */
function previousStudentNoState(string $studentType): string
{
    return $studentType === STUDENT_TYPE_RETURNING ? '' : 'disabled';
}

/**
 * Script for studentChoiceField(): toggles each อื่นๆ text box, keeps a multi-select's summary
 * text up to date, closes an open multi-select on an outside click, requires at least one ticked
 * box in a required multi-select on submit, and enables previous_student_no only for a returning
 * student.
 */
function studentChoiceScript(): string
{
    $returning = json_encode(STUDENT_TYPE_RETURNING, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

    return <<<HTML
        <script>
            function choiceValues(field) {
                return Array.from(document.querySelectorAll(
                    'input[name="' + field + '"]:checked, input[name="' + field + '[]"]:checked, select[name="' + field + '"]'
                )).map(function (el) { return el.value; }).filter(function (v) { return v !== ''; });
            }

            function toggleChoiceOther(field) {
                const values = choiceValues(field);
                const isOther = values.indexOf('อื่นๆ') !== -1;
                const other = document.getElementById(field + '_other');
                other.hidden = !isOther;
                other.required = isOther;

                const dropdown = document.getElementById(field + '_dropdown');
                if (dropdown) {
                    const summary = dropdown.querySelector('summary');
                    summary.textContent = values.length ? values.join(', ') : '-- เลือก (เลือกได้หลายข้อ) --';
                    summary.classList.toggle('placeholder', values.length === 0);
                    dropdown.querySelector('input').setCustomValidity('');
                }

                if (field === 'student_type') {
                    syncPreviousStudentNo();
                }
            }

            function syncPreviousStudentNo() {
                const input = document.getElementById('previous_student_no');
                if (!input) {
                    return;
                }
                const returning = choiceValues('student_type').indexOf($returning) !== -1;
                input.disabled = !returning;
                if (!returning) {
                    input.value = '';
                }
            }

            document.addEventListener('click', function (event) {
                document.querySelectorAll('details.multi-select[open]').forEach(function (dropdown) {
                    if (!dropdown.contains(event.target)) {
                        dropdown.open = false;
                    }
                });
            });

            document.querySelectorAll('details.multi-select[data-required]').forEach(function (dropdown) {
                const form = dropdown.closest('form');
                form.addEventListener('submit', function (event) {
                    const first = dropdown.querySelector('input');
                    if (choiceValues(dropdown.dataset.field).length === 0) {
                        event.preventDefault();
                        dropdown.open = true;
                        first.setCustomValidity('กรุณาเลือกอย่างน้อย 1 ข้อ');
                        first.reportValidity();
                    }
                });
            });
        </script>
        HTML;
}
