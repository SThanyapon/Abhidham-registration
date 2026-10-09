<?php

require_once __DIR__ . '/student_validation.php';

/**
 * HTML for one STUDENT_CHOICE_FIELDS question as a radio group, followed by the {field}_other text box
 * that is shown (and required) only while อื่นๆ is picked. Shared by index.php and admin/students.php;
 * the page must also output studentChoiceScript().
 */
function studentChoiceField(string $field, string $caption, string $value, string $other, bool $required): string
{
    $isOther = $value === 'อื่นๆ';
    $html = '<fieldset class="choice-group"><legend>' . htmlspecialchars($caption)
        . ($required ? '<span class="required-mark">*</span>' : '') . '</legend>';

    foreach (STUDENT_CHOICE_FIELDS[$field][1] as $option) {
        $html .= '<label class="checkbox-label"><input type="radio" name="' . $field . '" value="'
            . htmlspecialchars($option) . '"' . ($value === $option ? ' checked' : '') . ($required ? ' required' : '')
            . ' onchange="toggleChoiceOther(\'' . $field . '\')"> '
            . htmlspecialchars($option === 'อื่นๆ' ? 'อื่นๆ (ระบุ)' : $option) . '</label>';
    }

    $html .= '<input type="text" id="' . $field . '_other" name="' . $field . '_other" aria-label="โปรดระบุ"'
        . ' placeholder="โปรดระบุ" value="' . htmlspecialchars($other) . '"' . ($isOther ? ' required' : ' hidden') . '>';

    return $html . '</fieldset>';
}

// Shows/requires a choice question's อื่นๆ text box when that option is picked.
function studentChoiceScript(): string
{
    return <<<'HTML'
        <script>
            function toggleChoiceOther(field) {
                const checked = document.querySelector('input[name="' + field + '"]:checked');
                const isOther = checked !== null && checked.value === 'อื่นๆ';
                const other = document.getElementById(field + '_other');
                other.hidden = !isOther;
                other.required = isOther;
            }
        </script>
        HTML;
}
