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
 * HTML for the mailing-address block: the residence radio (อยู่ในประเทศไทย, the default, or
 * อยู่ต่างประเทศ), then that residence's panel, each with its own street-address box and field
 * order. Captions are Thai only.
 * - Thailand: รหัสไปรษณีย์ first, then จังหวัด / อำเภอ / ตำบล dropdowns that addressScript() fills
 *   with only that postcode's entries from THAI_POSTCODES_FILE, then the street address. The stored
 *   values are rendered as the selected options so a refill or a legacy row shows before (or
 *   without) the script.
 * - Abroad: free-text street address, city, state, postcode and country, stored in the same columns.
 * The hidden panel is a disabled <fieldset>, so its same-named controls are neither posted nor
 * validated. $values holds the stored or refilled student fields (studentResidence() picks the
 * panel). Shared by index.php and admin/students.php; the page must also output addressScript().
 */
function addressFields(array $values, bool $required): string
{
    $abroad = studentResidence($values) === RESIDENCE_ABROAD;
    $mark = $required ? '<span class="required-mark">*</span>' : '';
    $req = $required ? ' required' : '';
    $value = fn (string $field): string => htmlspecialchars((string) ($values[$field] ?? ''));
    // Each panel only shows the values that belong to it.
    $th = fn (string $field): string => $abroad ? '' : $value($field);
    $away = fn (string $field): string => $abroad ? $value($field) : '';

    $select = function (string $field, string $caption) use ($th, $mark, $req): string {
        $current = $th($field);
        return '<label for="' . $field . '_th">' . $caption . $mark . '</label>'
            . '<select id="' . $field . '_th" name="' . $field . '"' . $req . ' onchange="syncThaiAddress(false)">'
            . '<option value="">-- เลือก --</option>'
            . ($current !== '' ? '<option value="' . $current . '" selected>' . $current . '</option>' : '')
            . '</select>';
    };

    // The street address is always required (validateStudentFields() checks it on every page).
    $street = fn (string $id, string $caption, string $text): string =>
        '<label for="' . $id . '">' . $caption . '<span class="required-mark">*</span></label>'
        . '<textarea id="' . $id . '" name="address" rows="3" required autocomplete="street-address">'
        . $text . '</textarea>';

    $radio = fn (string $value, string $label, bool $checked): string =>
        '<label class="checkbox-label"><input type="radio" name="residence" value="' . $value . '"'
        . ($checked ? ' checked' : '') . ' onchange="syncResidence()"> ' . $label . '</label>';

    return '<div class="field-group choice-field">'
        . '<label>ที่อยู่ในการส่งเอกสาร: ท่านพักอาศัยอยู่ที่ใด?' . $mark . '</label>'
        . $radio(RESIDENCE_THAILAND, 'อยู่ในประเทศไทย', !$abroad)
        . $radio(RESIDENCE_ABROAD, 'อยู่ต่างประเทศ', $abroad)
        . '</div>'

        . '<fieldset id="address_th" class="field-group address-panel"' . ($abroad ? ' hidden disabled' : '') . '>'
        . '<label for="postal_code_th">รหัสไปรษณีย์' . $mark . '</label>'
        . '<p class="form-note">กรอกรหัสไปรษณีย์ก่อน แล้วเลือก จังหวัด อำเภอ ตำบล จากรายการ</p>'
        . '<input type="text" id="postal_code_th" name="postal_code" inputmode="numeric" maxlength="5"'
        . ' pattern="[0-9]{5}" title="ตัวเลข 5 หลัก" autocomplete="postal-code"' . $req
        . ' value="' . $th('postal_code') . '" oninput="syncThaiAddress(false)">'
        . '<p id="postal_code_unknown" class="error" hidden>ไม่พบรหัสไปรษณีย์นี้ กรุณาตรวจสอบอีกครั้ง</p>'
        . $select('province', 'จังหวัด')
        . $select('district', 'อำเภอ/เขต')
        . $select('sub_district', 'ตำบล/แขวง')
        . $street('street_address_th', 'ที่อยู่ (บ้านเลขที่ หมู่บ้าน ซอย ถนน)', $th('address'))
        . '</fieldset>'

        . '<fieldset id="address_abroad" class="field-group address-panel"' . ($abroad ? '' : ' hidden disabled') . '>'
        . $street('street_address_abroad', 'ที่อยู่', $away('address'))
        . '<label for="district_abroad">เมือง' . $mark . '</label>'
        . '<input type="text" id="district_abroad" name="district" autocomplete="address-level2"' . $req
        . ' value="' . $away('district') . '">'
        . '<label for="province_abroad">รัฐ</label>'
        . '<input type="text" id="province_abroad" name="province" autocomplete="address-level1"'
        . ' value="' . $away('province') . '">'
        . '<label for="postal_code_abroad">รหัสไปรษณีย์</label>'
        . '<input type="text" id="postal_code_abroad" name="postal_code" maxlength="20" autocomplete="postal-code"'
        . ' pattern="[A-Za-z0-9 \-]{1,20}" title="ตัวอักษรอังกฤษ ตัวเลข ขีด และช่องว่าง ไม่เกิน 20 ตัว"'
        . ' value="' . $away('postal_code') . '">'
        . '<label for="country_abroad">ประเทศ' . $mark . '</label>'
        . '<input type="text" id="country_abroad" name="country" autocomplete="country-name"' . $req
        . ' value="' . $away('country') . '">'
        . '</fieldset>';
}

/**
 * Script for addressFields(): switches the residence panels, and fills the Thai จังหวัด → อำเภอ →
 * ตำบล dropdowns from the postcode. $dataUrl is THAI_POSTCODES_FILE's URL relative to the page
 * (fetched once, on first use). A value already selected that isn't in the postcode's list (a
 * legacy row) is kept on page load but dropped once the user edits the postcode.
 */
function addressScript(string $dataUrl): string
{
    $url = json_encode($dataUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

    return <<<HTML
        <script>
            let thaiPostcodesRequest = null;

            function loadThaiPostcodes() {
                if (!thaiPostcodesRequest) {
                    thaiPostcodesRequest = fetch($url)
                        .then(function (r) { return r.ok ? r.json() : null; })
                        .catch(function () { return null; });
                }
                return thaiPostcodesRequest;
            }

            function syncResidence() {
                const abroad = document.querySelector('input[name="residence"]:checked').value === 'abroad';
                [['address_th', abroad], ['address_abroad', !abroad]].forEach(function (pair) {
                    const panel = document.getElementById(pair[0]);
                    panel.hidden = pair[1];
                    panel.disabled = pair[1];
                });
            }

            // Replaces a dropdown's options, keeping its selection when still offered (or always,
            // with keepUnknown); a single option is picked automatically. Returns the new value.
            function fillAddressSelect(select, options, keepUnknown) {
                const current = select.value;
                const list = options.slice();
                if (keepUnknown && current !== '' && list.indexOf(current) === -1) {
                    list.unshift(current);
                }
                select.replaceChildren(new Option('-- เลือก --', ''));
                list.forEach(function (option) { select.add(new Option(option, option)); });
                select.value = list.indexOf(current) !== -1 ? current : (options.length === 1 ? options[0] : '');
                return select.value;
            }

            async function syncThaiAddress(keepUnknown) {
                const input = document.getElementById('postal_code_th');
                const code = input.value.trim();
                const valid = /^[0-9]{5}$/.test(code);
                const data = valid ? await loadThaiPostcodes() : null;
                if (valid && !data) {
                    return; // lookup unavailable: leave the dropdowns as they are
                }
                if (code !== input.value.trim()) {
                    return; // the user kept typing while the file loaded; that call takes over
                }
                const provinces = data && data[code] ? data[code] : null;
                document.getElementById('postal_code_unknown').hidden = !(valid && !provinces);

                const province = fillAddressSelect(document.getElementById('province_th'),
                    provinces ? Object.keys(provinces) : [], keepUnknown);
                const districts = provinces && provinces[province] ? provinces[province] : null;
                const district = fillAddressSelect(document.getElementById('district_th'),
                    districts ? Object.keys(districts) : [], keepUnknown);
                fillAddressSelect(document.getElementById('sub_district_th'),
                    districts && districts[district] ? districts[district] : [], keepUnknown);
            }

            if (document.getElementById('postal_code_th').value.trim() !== '') {
                syncThaiAddress(true);
            }
        </script>
        HTML;
}

/**
 * Whether the previous_student_no field (#previous_student_no_wrap) should be shown and enabled:
 * only for a returning student. studentChoiceScript() keeps it in sync when the answer changes.
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
