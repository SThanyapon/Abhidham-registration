<?php

// Zero-width space/joiners and BOM, which Thai input methods often insert invisibly.
const INPUT_ZERO_WIDTH_PATTERN = '/[\x{200B}-\x{200D}\x{FEFF}]/u';

// Any whitespace run, including non-breaking (U+00A0) and ideographic (U+3000) spaces.
const INPUT_WHITESPACE_PATTERN = '/[\s\x{00A0}\x{3000}]+/u';

/**
 * Single-line free text: trims both ends and collapses internal whitespace to one space,
 * so "  สมชาย   ใจดี " is stored and matched as "สมชาย ใจดี".
 */
function cleanText(string $value): string
{
    $cleaned = preg_replace([INPUT_ZERO_WIDTH_PATTERN, INPUT_WHITESPACE_PATTERN], ['', ' '], $value);

    // preg_replace returns null on invalid UTF-8; fall back to a plain trim.
    return trim($cleaned ?? $value);
}

/**
 * Multi-line free text (e.g. address): cleans each line like cleanText() and drops blank lines,
 * keeping intentional line breaks.
 */
function cleanMultilineText(string $value): string
{
    $lines = array_map('cleanText', preg_split('/\R/u', $value) ?: [$value]);

    return implode("\n", array_filter($lines, fn(string $line): bool => $line !== ''));
}

/**
 * Person names: Thai letters/vowels/tone marks/digits (excluding Thai symbols such as ฿ and ๏),
 * English letters, digits, spaces, and dashes ('-' plus the Unicode hyphens/en dash U+2010-U+2013
 * that phones and pasted text produce). Rejects '/', '*', '&', '.', etc.
 */
function isValidPersonName(string $name): bool
{
    return preg_match('/^[\x{0E01}-\x{0E3A}\x{0E40}-\x{0E4E}\x{0E50}-\x{0E59}A-Za-z0-9 \-\x{2010}-\x{2013}]+$/u', $name) === 1;
}

/**
 * Identifiers where spaces are never valid (student ID, OTP code): removes all whitespace,
 * so a pasted "123 456" becomes "123456".
 */
function cleanCode(string $value): string
{
    $cleaned = preg_replace([INPUT_ZERO_WIDTH_PATTERN, INPUT_WHITESPACE_PATTERN], ['', ''], $value);

    return $cleaned ?? trim($value);
}

/**
 * CSV export rows: a cell starting with = + - @ (or tab/CR) is run as a formula by Excel, so applicant
 * free text such as =HYPERLINK(...) is prefixed with ' to keep it plain text. Numbers and the lone '-'
 * placeholder pass unchanged.
 */
function csvSafe(array $row): array
{
    return array_map(static function ($value) {
        if (is_string($value) && strlen($value) > 1 && strpbrk($value[0], "=+-@\t\r") !== false) {
            return "'" . $value;
        }
        return $value;
    }, $row);
}
