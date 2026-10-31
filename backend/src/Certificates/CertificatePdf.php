<?php

declare(strict_types=1);

namespace Vitalcare\Certificates;

/**
 * Draws one certificate as a single-page A4 landscape PDF 1.4, with no dependencies (D-018 item 6).
 *
 * Text is set in Noto Sans Regular and Bold (SIL Open Font License 1.1, backend/assets/fonts), embedded as
 * subsetted CID fonts (Type0 / CIDFontType2, Identity-H) with a ToUnicode map, so names in Latin, Latin
 * Extended, Vietnamese, Yoruba and Igbo, Greek and Cyrillic print correctly and can be copied and searched.
 * Names are normalised to NFC first. A combining mark the font has no precomposed letter for is placed over
 * (or under) the letter before it by a simple box-centring rule; there is no OpenType shaping, so Arabic,
 * Hebrew, CJK and Indic scripts are NOT supported: a character the font lacks prints as a visible empty box
 * (the font's .notdef glyph), never as "?", and still copies as the right character. See missing().
 *
 * The output depends only on the stored snapshot and the font files, so the same certificate always gives
 * the same bytes. Wording, logo and signature are PLACEHOLDERS until the owner approves them
 * (docs/AUDIT.md, owner actions).
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
    /** Font resource name => file under backend/assets/fonts, and a stem width for the descriptor. */
    private const FONTS = ['F1' => ['NotoSans-Regular.ttf', 80], 'F2' => ['NotoSans-Bold.ttf', 140]];

    /** @var array<string,TrueTypeFont> loaded once per process */
    private static array $loaded = [];

    /** @var array<string,array<int,int>> per font: code point => CID (CIDs are given out from 1 in order of first use) */
    private array $cids = ['F1' => [], 'F2' => []];

    /**
     * @param array{code:string,learnerName:string,courseTitle:string,hours:?float,completedOn:string,issuedOn:?string,expiresOn:?string} $c
     */
    public static function render(array $c): string
    {
        return (new self())->draw($c);
    }

    /** Folder holding the font files and their licence. */
    public static function fontDir(): string
    {
        return dirname(__DIR__, 2) . '/assets/fonts';
    }

    public static function font(string $font): TrueTypeFont
    {
        if (!isset(self::FONTS[$font])) {
            throw new \InvalidArgumentException("Unknown certificate font $font");
        }
        return self::$loaded[$font] ??= new TrueTypeFont(self::fontDir() . '/' . self::FONTS[$font][0]);
    }

    /** The text as it will be drawn: control characters become spaces, then Unicode NFC. */
    public static function normalise(string $text): string
    {
        $clean = (string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', $text);
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($clean, \Normalizer::FORM_C);
            if (is_string($n)) {
                return $n;
            }
        }
        return $clean;
    }

    /**
     * Characters of the text that the certificate font cannot draw (they print as an empty box).
     *
     * @return list<string>
     */
    public static function missing(string $text): array
    {
        $f1 = self::font('F1');
        $f2 = self::font('F2');
        $out = [];
        foreach (self::codepoints($text) as $cp) {
            $ch = mb_chr($cp, 'UTF-8');
            if (($f1->glyphId($cp) === 0 || $f2->glyphId($cp) === 0) && !in_array($ch, $out, true)) {
                $out[] = $ch;
            }
        }
        return $out;
    }

    /** Text width in points for a font ('F1' regular, 'F2' bold) at a size. */
    public static function width(string $text, string $font, float $size): float
    {
        $f = self::font($font);
        $sum = 0;
        foreach (self::codepoints($text) as $cp) {
            $sum += $f->advance($f->glyphId($cp));
        }
        return $sum * $size / $f->unitsPerEm;
    }

    /** @param array{code:string,learnerName:string,courseTitle:string,hours:?float,completedOn:string,issuedOn:?string,expiresOn:?string} $c */
    private function draw(array $c): string
    {
        $ops = [];
        // Frame: navy outer border, gold inner rule.
        $ops[] = self::NAVY . ' RG 3 w 24 24 ' . (self::W - 48) . ' ' . (self::H - 48) . ' re S';
        $ops[] = self::GOLD . ' RG 1 w 34 34 ' . (self::W - 68) . ' ' . (self::H - 68) . ' re S';

        $ops[] = $this->centre('VITALCARE TRAINING HUB', 'F2', 13, 515, self::NAVY);
        $ops[] = $this->centre('Certificate', 'F2', 40, 452, self::NAVY);
        $ops[] = $this->centre('This certifies that', 'F1', 14, 408, self::INK);
        foreach (self::fit($c['learnerName'], 'F2', 30, 700, 14) as [$line, $size, $dy]) {
            $ops[] = $this->centre($line, 'F2', $size, 364 - $dy, self::NAVY);
        }
        $ops[] = $this->centre('completed the course', 'F1', 14, 322, self::INK);
        foreach (self::fit($c['courseTitle'], 'F2', 22, 700, 12) as [$line, $size, $dy]) {
            $ops[] = $this->centre($line, 'F2', $size, 288 - $dy, self::NAVY);
        }
        $on = 'on ' . self::date($c['completedOn']);
        if ($c['hours'] !== null) {
            $h = rtrim(rtrim(number_format($c['hours'], 2, '.', ''), '0'), '.');
            $on .= ' (' . $h . ($h === '1' ? ' hour' : ' hours') . ')';
        }
        $ops[] = $this->centre($on, 'F1', 14, 246, self::INK);
        $ops[] = $this->centre(
            $c['expiresOn'] !== null ? 'Valid until ' . self::date($c['expiresOn']) : 'No expiry date is recorded for this course',
            'F1', 12, 222, self::INK,
        );
        $ops[] = self::GOLD . ' RG 2 w 341 196 m 501 196 l S';

        $display = CertificateCode::display($c['code']);
        $ops[] = $this->text('Certificate code ' . $display, 'F2', 12, 70, 124, self::NAVY);
        if ($c['issuedOn'] !== null) {
            $ops[] = $this->text('Issued on ' . self::date($c['issuedOn']), 'F1', 10, 70, 107, self::INK);
        }
        $ops[] = $this->text('Check it at vitalcare.uk/verify/' . $c['code'], 'F1', 10, 70, 91, self::INK);
        $ops[] = $this->text(self::ISSUER, 'F2', 12, self::W - 70 - self::width(self::ISSUER, 'F2', 12), 124, self::NAVY);
        $ops[] = $this->text(self::ISSUER_LINE, 'F1', 9, self::W - 70 - self::width(self::ISSUER_LINE, 'F1', 9), 108, self::INK);

        $stream = implode("\n", $ops);
        $created = 'D:' . str_replace('-', '', $c['issuedOn'] ?? $c['completedOn']) . '000000Z';
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>',
            4 => self::stream($stream),
            7 => '<< /Title (Certificate ' . $display . ') /Producer (' . self::ISSUER . ') /CreationDate (' . $created . ') >>',
        ];
        $objects += $this->fontObjects('F1', 5, 8) + $this->fontObjects('F2', 6, 13);
        ksort($objects);

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

    /**
     * The objects behind one font: the Type0 font (at $type0), and from $first on the CIDFontType2, its
     * descriptor, the embedded subset, the ToUnicode map and the CID-to-glyph map.
     *
     * @return array<int,string>
     */
    private function fontObjects(string $font, int $type0, int $first): array
    {
        $f = self::font($font);
        [$cidFont, $desc, $file, $toUni, $cidMap] = [$first, $first + 1, $first + 2, $first + 3, $first + 4];
        $gids = [0];
        $widths = [];
        $uni = [];
        foreach ($this->cids[$font] as $cp => $cid) {
            $g = $f->glyphId($cp);
            $gids[$cid] = $g;
            $widths[] = (int) round($f->advance($g) * 1000 / $f->unitsPerEm);
            $uni[] = sprintf('<%04X> <%s>', $cid, strtoupper(bin2hex((string) mb_convert_encoding(mb_chr($cp, 'UTF-8'), 'UTF-16BE', 'UTF-8'))));
        }
        ksort($gids);
        $map = '';
        foreach ($gids as $g) {
            $map .= pack('n', $g);
        }
        $used = array_values(array_unique($gids));
        sort($used);
        // A subset is named with six capital letters; derive them from the glyphs so they never vary at random.
        $tag = '';
        foreach (str_split(substr(hash('sha256', $font . ':' . implode(',', $used)), 0, 6)) as $h) {
            $tag .= chr(65 + (int) hexdec($h));
        }
        $name = '/' . $tag . '+' . $f->postScriptName;
        $em = static fn (int $v): int => (int) round($v * 1000 / $f->unitsPerEm);
        $subset = $f->subset($used);

        $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
        foreach (array_chunk($uni, 100) as $chunk) {
            $cmap .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        }
        $cmap .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
        $w = $widths === [] ? '' : ' /W [1 [' . implode(' ', $widths) . ']]';

        return [
            $type0 => "<< /Type /Font /Subtype /Type0 /BaseFont $name /Encoding /Identity-H /DescendantFonts [$cidFont 0 R] /ToUnicode $toUni 0 R >>",
            $cidFont => "<< /Type /Font /Subtype /CIDFontType2 /BaseFont $name /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>"
                . " /FontDescriptor $desc 0 R /DW " . $em($f->advance(0)) . $w . " /CIDToGIDMap $cidMap 0 R >>",
            $desc => "<< /Type /FontDescriptor /FontName $name /Flags 4 /FontBBox [" . implode(' ', array_map($em, $f->bbox)) . ']'
                . ' /ItalicAngle 0 /Ascent ' . $em($f->ascent) . ' /Descent ' . $em($f->descent)
                . ' /CapHeight ' . $em($f->capHeight) . ' /XHeight ' . $em($f->xHeight) . ' /StemV ' . self::FONTS[$font][1]
                . " /FontFile2 $file 0 R >>",
            $file => self::stream($subset, true, ' /Length1 ' . strlen($subset)),
            $toUni => self::stream($cmap),
            $cidMap => self::stream($map, true),
        ];
    }

    private static function stream(string $data, bool $deflate = false, string $extra = ''): string
    {
        if ($deflate) {
            $data = (string) gzcompress($data, 9);
            $extra = ' /Filter /FlateDecode' . $extra;
        }
        return '<< /Length ' . strlen($data) . $extra . " >>\nstream\n" . $data . "\nendstream";
    }

    /** @return list<int> */
    private static function codepoints(string $text): array
    {
        $out = [];
        foreach (mb_str_split(self::normalise($text), 1, 'UTF-8') as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp !== false) {
                $out[] = $cp;
            }
        }
        return $out;
    }

    private function cid(string $font, int $cp): int
    {
        return $this->cids[$font][$cp] ??= count($this->cids[$font]) + 1;
    }

    /**
     * The text-showing operators for one line. Ordinary glyphs advance as the font says. A combining mark
     * (zero advance, Unicode category Mn) is centred over the box of the letter before it, and raised above a
     * tall letter or lowered under a descending one; stacked marks go one beyond the other.
     */
    private function show(string $text, string $font, float $size): string
    {
        $f = self::font($font);
        $k = 1000 / $f->unitsPerEm;
        $gap = (int) round($f->unitsPerEm * 0.04);
        $segments = [];   // list of [rise in points as text, list of hex CID strings or TJ numbers]
        $base = null;     // [box, advance] of the last letter
        $up = 0;
        $down = 0;
        $add = static function (string $rise, string|float $item) use (&$segments): void {
            $last = array_key_last($segments);
            if ($last === null || $segments[$last][0] !== $rise) {
                $segments[] = [$rise, []];
                $last = array_key_last($segments);
            }
            $segments[$last][1][] = $item;
        };
        foreach (self::codepoints($text) as $cp) {
            $g = $f->glyphId($cp);
            $hex = sprintf('%04X', $this->cid($font, $cp));
            $isMark = $g !== 0 && $base !== null && $f->advance($g) === 0 && preg_match('/^\p{Mn}$/u', mb_chr($cp, 'UTF-8')) === 1;
            if (!$isMark) {
                $add('0', $hex);
                $base = [$f->glyphBox($g), $f->advance($g)];
                $up = 0;
                $down = 0;
                continue;
            }
            [$bb, $adv] = $base;
            $mb = $f->glyphBox($g);
            $baseCentre = $bb[2] > $bb[0] ? ($bb[0] + $bb[2]) / 2 : $adv / 2;
            $shift = $baseCentre - ($adv + ($mb[0] + $mb[2]) / 2);
            $rise = 0;
            if ($mb[1] >= $f->xHeight / 2) {
                // Marks above are drawn for x-height letters: lift them over a taller letter or a mark already there.
                $rise = max(0, $bb[3] - $f->xHeight) + $up;
                $up += ($mb[3] - $mb[1]) + $gap;
            } elseif ($mb[3] <= $f->xHeight / 4) {
                $rise = min(0, $bb[1]) - $down;
                $down += ($mb[3] - $mb[1]) + $gap;
            }
            $add('0', -$shift * $k);
            $add(self::num($rise / $f->unitsPerEm * $size), $hex);
            $add('0', $shift * $k);
        }
        $ops = [];
        $current = '0';
        foreach ($segments as [$rise, $items]) {
            if ($rise !== $current) {
                $ops[] = "$rise Ts";
                $current = $rise;
            }
            $parts = [];
            $run = '';
            foreach ($items as $it) {
                if (is_string($it)) {
                    $run .= $it;
                    continue;
                }
                if ($run !== '') {
                    $parts[] = "<$run>";
                    $run = '';
                }
                if (self::num($it) !== '0') {
                    $parts[] = self::num($it);
                }
            }
            if ($run !== '') {
                $parts[] = "<$run>";
            }
            if ($parts !== []) {
                $ops[] = '[' . implode(' ', $parts) . '] TJ';
            }
        }
        if ($current !== '0') {
            $ops[] = '0 Ts';
        }
        return implode(' ', $ops);
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
        foreach (preg_split('/\s+/u', trim(self::normalise($text))) ?: [] as $word) {
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

    private function centre(string $text, string $font, float $size, float $y, string $rgb): string
    {
        return $this->text($text, $font, $size, (self::W - self::width($text, $font, $size)) / 2, $y, $rgb);
    }

    private function text(string $text, string $font, float $size, float $x, float $y, string $rgb): string
    {
        return sprintf('BT %s rg /%s %s Tf %s %s Td %s ET', $rgb, $font, self::num($size), self::num($x), self::num($y), $this->show($text, $font, $size));
    }

    private static function num(float $n): string
    {
        $s = rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    private static function date(string $iso): string
    {
        return (new \DateTimeImmutable($iso . ' 12:00:00', new \DateTimeZone('UTC')))->format('j F Y');
    }
}
