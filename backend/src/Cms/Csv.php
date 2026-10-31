<?php

declare(strict_types=1);

namespace Vitalcare\Cms;

/**
 * CSV for spreadsheets, safe against formula injection (D-018 item 8). A cell that a spreadsheet could
 * read as a formula (it starts, after any leading spaces, with =, +, -, @, a tab or a carriage return)
 * is prefixed with a single quote. Every cell is quoted, quotes are doubled, lines end CRLF, and the
 * file starts with a UTF-8 byte order mark so Excel reads accented names correctly.
 */
final class Csv
{
    public static function cell(mixed $v): string
    {
        $s = match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'yes' : 'no',
            default => (string) $v,
        };
        if (preg_match('/^[\s]*[=+\-@\t\r]/u', $s) === 1 || preg_match('/^[\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u', $s) === 1) {
            $s = "'" . $s;
        }
        return '"' . str_replace('"', '""', $s) . '"';
    }

    /** @param list<string> $header @param iterable<list<mixed>> $rows */
    public static function build(array $header, iterable $rows): string
    {
        $out = "\xEF\xBB\xBF" . implode(',', array_map(self::cell(...), $header)) . "\r\n";
        foreach ($rows as $r) {
            $out .= implode(',', array_map(self::cell(...), $r)) . "\r\n";
        }
        return $out;
    }
}
