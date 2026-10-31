<?php

declare(strict_types=1);

namespace Vitalcare\Certificates;

/**
 * Draws one certificate as a single-page A4 landscape PDF 1.4, with no dependencies (D-018 item 6).
 *
 * Only the standard PDF fonts Helvetica and Helvetica-Bold are used (no embedding, no images), with
 * WinAnsi encoding: characters outside Windows-1252 print as "?". The output depends only on the stored
 * snapshot, so the same certificate always gives the same bytes. Wording, logo and signature are
 * PLACEHOLDERS until the owner approves them (docs/AUDIT.md, owner actions).
 */
final class CertificatePdf
{
    private const W = 842;
    private const H = 595;
    private const NAVY = '0.106 0.180 0.420';
    private const GOLD = '0.831 0.659 0.263';
    private const INK = '0.059 0.090 0.165';
    private const ISSUER = 'Vitalcare Training Hub Ltd';
    private const ISSUER_LINE = 'Company number 15718997, registered in England and Wales';

    // Adobe AFM widths for character codes 32 to 126 (1/1000 em). Anything above 126 uses 556.
    private const HELVETICA = '278 278 355 556 556 889 667 191 333 333 389 584 278 333 278 278 556 556 556 556 556 556 556 556 556 556 278 278 584 584 584 556 1015 667 667 722 722 667 611 778 722 278 500 667 556 833 722 778 667 778 722 667 611 722 667 944 667 667 611 278 278 278 469 556 333 556 556 500 556 556 278 556 556 222 222 500 222 833 556 556 556 556 333 500 278 556 500 722 500 500 500 334 260 334 584';
    private const HELVETICA_BOLD = '278 333 474 556 556 889 722 238 333 333 389 584 278 333 278 278 556 556 556 556 556 556 556 556 556 556 333 333 584 584 584 611 975 722 722 722 722 667 611 778 722 278 556 722 611 833 722 778 667 778 722 667 611 722 667 944 667 667 611 333 278 333 584 556 333 556 611 556 611 556 333 611 611 278 278 556 278 889 611 611 611 611 389 556 333 611 556 778 556 556 500 389 280 389 584';

    /** @var array<string,list<int>> */
    private static array $widths = [];

    /**
     * @param array{code:string,learnerName:string,courseTitle:string,hours:?float,completedOn:string,issuedOn:?string,expiresOn:?string} $c
     */
    public static function render(array $c): string
    {
        $ops = [];
        // Frame: navy outer border, gold inner rule.
        $ops[] = self::NAVY . ' RG 3 w 24 24 ' . (self::W - 48) . ' ' . (self::H - 48) . ' re S';
        $ops[] = self::GOLD . ' RG 1 w 34 34 ' . (self::W - 68) . ' ' . (self::H - 68) . ' re S';

        $ops[] = self::centre('VITALCARE TRAINING HUB', 'F2', 13, 515, self::NAVY);
        $ops[] = self::centre('Certificate', 'F2', 40, 452, self::NAVY);
        $ops[] = self::centre('This certifies that', 'F1', 14, 408, self::INK);
        foreach (self::fit($c['learnerName'], 'F2', 30, 700, 14) as [$line, $size, $dy]) {
            $ops[] = self::centre($line, 'F2', $size, 364 - $dy, self::NAVY);
        }
        $ops[] = self::centre('completed the course', 'F1', 14, 322, self::INK);
        foreach (self::fit($c['courseTitle'], 'F2', 22, 700, 12) as [$line, $size, $dy]) {
            $ops[] = self::centre($line, 'F2', $size, 288 - $dy, self::NAVY);
        }
        $on = 'on ' . self::date($c['completedOn']);
        if ($c['hours'] !== null) {
            $h = rtrim(rtrim(number_format($c['hours'], 2, '.', ''), '0'), '.');
            $on .= ' (' . $h . ($h === '1' ? ' hour' : ' hours') . ')';
        }
        $ops[] = self::centre($on, 'F1', 14, 246, self::INK);
        $ops[] = self::centre(
            $c['expiresOn'] !== null ? 'Valid until ' . self::date($c['expiresOn']) : 'No expiry date is recorded for this course',
            'F1', 12, 222, self::INK,
        );
        $ops[] = self::GOLD . ' RG 2 w 341 196 m 501 196 l S';

        $display = CertificateCode::display($c['code']);
        $ops[] = self::text('Certificate code ' . $display, 'F2', 12, 70, 124, self::NAVY);
        if ($c['issuedOn'] !== null) {
            $ops[] = self::text('Issued on ' . self::date($c['issuedOn']), 'F1', 10, 70, 107, self::INK);
        }
        $ops[] = self::text('Check it at vitalcare.uk/verify/' . $c['code'], 'F1', 10, 70, 91, self::INK);
        $ops[] = self::text(self::ISSUER, 'F2', 12, self::W - 70 - self::width(self::ISSUER, 'F2', 12), 124, self::NAVY);
        $ops[] = self::text(self::ISSUER_LINE, 'F1', 9, self::W - 70 - self::width(self::ISSUER_LINE, 'F1', 9), 108, self::INK);

        $stream = implode("\n", $ops);
        $created = 'D:' . str_replace('-', '', $c['issuedOn'] ?? $c['completedOn']) . '000000Z';
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>',
            4 => '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            6 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            7 => '<< /Title (Certificate ' . $display . ') /Producer (' . self::ISSUER . ') /CreationDate (' . $created . ') >>',
        ];
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($out);
            $out .= "$n 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }
        $out .= 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R /Info 7 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $out;
    }

    /** Text width in points for a font ('F1' Helvetica, 'F2' Helvetica-Bold) at a size. */
    public static function width(string $text, string $font, float $size): float
    {
        $w = self::widths($font);
        $sum = 0;
        foreach (str_split(self::encode($text)) as $ch) {
            $o = ord($ch);
            $sum += ($o >= 32 && $o <= 126) ? $w[$o - 32] : 556;
        }
        return $sum * $size / 1000;
    }

    /** UTF-8 to Windows-1252, then PDF string escaping. */
    public static function escape(string $text): string
    {
        return strtr(self::encode($text), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    /** @return list<int> */
    public static function widths(string $font): array
    {
        if (!isset(self::$widths[$font])) {
            $w = array_map('intval', explode(' ', $font === 'F2' ? self::HELVETICA_BOLD : self::HELVETICA));
            if (count($w) !== 95) {
                throw new \LogicException('Font width table must hold 95 entries');
            }
            self::$widths[$font] = $w;
        }
        return self::$widths[$font];
    }

    private static function encode(string $text): string
    {
        $clean = (string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', $text);
        $out = '';
        foreach (mb_str_split($clean, 1, 'UTF-8') as $ch) {
            $c = @mb_convert_encoding($ch, 'Windows-1252', 'UTF-8');
            $out .= ($c === '' || $c === false || ($c === '?' && $ch !== '?')) ? '?' : $c;
        }
        return $out;
    }

    /**
     * Shrinks the font until the text fits; if it still does not fit at the smallest size, wraps it.
     *
     * @return list<array{0:string,1:float,2:float}> lines with their size and downward offset
     */
    private static function fit(string $text, string $font, float $size, float $max, float $min): array
    {
        for ($s = $size; $s >= $min; $s -= 2) {
            if (self::width($text, $font, $s) <= $max) {
                return [[$text, $s, 0.0]];
            }
        }
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            $try = $line === '' ? $word : "$line $word";
            if ($line !== '' && self::width($try, $font, $min) > $max) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        $lines[] = $line;
        $out = [];
        foreach (array_slice($lines, 0, 3) as $i => $l) {
            $out[] = [$l, $min, $i * ($min + 3)];
        }
        return $out;
    }

    private static function centre(string $text, string $font, float $size, float $y, string $rgb): string
    {
        return self::text($text, $font, $size, (self::W - self::width($text, $font, $size)) / 2, $y, $rgb);
    }

    private static function text(string $text, string $font, float $size, float $x, float $y, string $rgb): string
    {
        return sprintf('BT %s rg /%s %s Tf %s %s Td (%s) Tj ET', $rgb, $font, self::num($size), self::num($x), self::num($y), self::escape($text));
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    private static function date(string $iso): string
    {
        return (new \DateTimeImmutable($iso . ' 12:00:00', new \DateTimeZone('UTC')))->format('j F Y');
    }
}
