<?php

function formatDateBE(string $isoDate): string
{
    $date = new DateTime($isoDate);
    $buddhistYear = (int) $date->format('Y') + 543;

    return $date->format('d/m/') . $buddhistYear;
}

function formatDateBEShort(string $isoDate): string
{
    $thaiMonthsShort = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
    ];

    $date = new DateTime($isoDate);
    $day = (int) $date->format('j');
    $month = $thaiMonthsShort[(int) $date->format('n')];
    $shortBuddhistYear = ((int) $date->format('Y') + 543) % 100;

    return sprintf('%d %s %02d', $day, $month, $shortBuddhistYear);
}
