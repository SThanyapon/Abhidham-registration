<?php

function formatDateBE(string $isoDate): string
{
    $date = new DateTime($isoDate);
    $buddhistYear = (int) $date->format('Y') + 543;

    return $date->format('d/m/') . $buddhistYear;
}
