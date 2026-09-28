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
 * Identifiers where spaces are never valid (student ID, OTP code): removes all whitespace,
 * so a pasted "123 456" becomes "123456".
 */
function cleanCode(string $value): string
{
    $cleaned = preg_replace([INPUT_ZERO_WIDTH_PATTERN, INPUT_WHITESPACE_PATTERN], ['', ''], $value);

    return $cleaned ?? trim($value);
}
