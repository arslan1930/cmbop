<?php

namespace App\Support;

class Csv
{
    /**
     * Neutralize spreadsheet formula injection in CSV cells.
     */
    public static function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text)) {
            return "'".$text;
        }

        return $text;
    }
}
