<?php

declare(strict_types=1);

namespace Vitalcare\Certificates;

/**
 * Reads the parts of a TrueType (glyf-outline) font that a PDF needs, and writes a subset of it.
 *
 * Only what the certificate generator uses: Unicode to glyph lookup (cmap formats 4 and 12), advance
 * widths, glyph boxes and the font-wide metrics, and a subset that keeps the original glyph ids (unused
 * glyphs become empty), so the PDF can point at glyphs by their ids. The subset keeps only the tables a
 * PDF viewer needs to draw (no cmap, names, kerning or shaping tables). No dependencies; the same input
 * always gives the same bytes.
 */
final class TrueTypeFont
{
    public readonly int $unitsPerEm;
    public readonly int $ascent;
    public readonly int $descent;
    public readonly int $capHeight;
    public readonly int $xHeight;
    public readonly int $fsType;
    /** @var array{0:int,1:int,2:int,3:int} */
    public readonly array $bbox;
    public readonly string $postScriptName;

    private string $data;
    /** @var array<string,array{0:int,1:int}> tag => [offset, length] */
    private array $tables = [];
    private int $numGlyphs;
    private int $numHMetrics;
    private bool $longLoca;
    /** @var array<int,int> */
    private array $cmapCache = [];
    /** @var array{kind:int,offset:int} kind 0 when the font has no Unicode cmap (as in a subset this class wrote) */
    private array $cmap;

    public function __construct(string $path)
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 12) {
            throw new \RuntimeException('Certificate font is missing or unreadable: ' . basename($path));
        }
        $this->data = $data;
        $version = $this->u32(0);
        if ($version !== 0x00010000 && $version !== 0x74727565) {
            throw new \RuntimeException('Certificate font is not a TrueType-outline font: ' . basename($path));
        }
        $n = $this->u16(4);
        for ($i = 0; $i < $n; $i++) {
            $r = 12 + $i * 16;
            $this->tables[substr($data, $r, 4)] = [$this->u32($r + 8), $this->u32($r + 12)];
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf'] as $t) {
            if (!isset($this->tables[$t])) {
                throw new \RuntimeException("Certificate font has no $t table");
            }
        }
        $head = $this->tables['head'][0];
        $this->unitsPerEm = $this->u16($head + 18);
        $this->bbox = [$this->i16($head + 36), $this->i16($head + 38), $this->i16($head + 40), $this->i16($head + 42)];
        $this->longLoca = $this->i16($head + 50) === 1;
        $hhea = $this->tables['hhea'][0];
        $this->ascent = $this->i16($hhea + 4);
        $this->descent = $this->i16($hhea + 6);
        $this->numHMetrics = $this->u16($hhea + 34);
        $this->numGlyphs = $this->u16($this->tables['maxp'][0] + 4);

        $fsType = 0;
        $cap = (int) round($this->ascent * 0.7);
        $xh = (int) round($this->ascent * 0.5);
        if (isset($this->tables['OS/2'])) {
            $os2 = $this->tables['OS/2'][0];
            $fsType = $this->u16($os2 + 8);
            if ($this->u16($os2) >= 2 && $this->tables['OS/2'][1] >= 90) {
                $xh = $this->i16($os2 + 86);
                $cap = $this->i16($os2 + 88);
            }
        }
        $this->fsType = $fsType;
        $this->capHeight = $cap;
        $this->xHeight = $xh;
        $this->postScriptName = $this->readPostScriptName() ?? pathinfo($path, PATHINFO_FILENAME);
        $this->cmap = $this->pickCmap();
    }

    public function numGlyphs(): int
    {
        return $this->numGlyphs;
    }

    /** Glyph id for a Unicode code point; 0 (.notdef) when the font has none. */
    public function glyphId(int $cp): int
    {
        if (isset($this->cmapCache[$cp])) {
            return $this->cmapCache[$cp];
        }
        $o = $this->cmap['offset'];
        $g = 0;
        if ($this->cmap['kind'] === 0) {
            return 0;
        }
        if ($this->cmap['kind'] === 12) {
            $groups = $this->u32($o + 12);
            $lo = 0;
            $hi = $groups - 1;
            while ($lo <= $hi) {
                $mid = intdiv($lo + $hi, 2);
                $p = $o + 16 + $mid * 12;
                $start = $this->u32($p);
                $end = $this->u32($p + 4);
                if ($cp < $start) {
                    $hi = $mid - 1;
                } elseif ($cp > $end) {
                    $lo = $mid + 1;
                } else {
                    $g = $this->u32($p + 8) + ($cp - $start);
                    break;
                }
            }
        } elseif ($cp <= 0xFFFF) {
            $segX2 = $this->u16($o + 6);
            $ends = $o + 14;
            $starts = $ends + $segX2 + 2;
            $deltas = $starts + $segX2;
            $ranges = $deltas + $segX2;
            for ($i = 0; $i < $segX2; $i += 2) {
                if ($this->u16($ends + $i) < $cp) {
                    continue;
                }
                $start = $this->u16($starts + $i);
                if ($cp >= $start) {
                    $ro = $this->u16($ranges + $i);
                    if ($ro === 0) {
                        $g = ($cp + $this->i16($deltas + $i)) & 0xFFFF;
                    } else {
                        $gi = $this->u16($ranges + $i + $ro + 2 * ($cp - $start));
                        $g = $gi === 0 ? 0 : ($gi + $this->i16($deltas + $i)) & 0xFFFF;
                    }
                }
                break;
            }
        }
        if ($g >= $this->numGlyphs) {
            $g = 0;
        }
        return $this->cmapCache[$cp] = $g;
    }

    /** Advance width in font units. */
    public function advance(int $gid): int
    {
        $i = min($gid, $this->numHMetrics - 1);
        return $this->u16($this->tables['hmtx'][0] + $i * 4);
    }

    /** @return array{0:int,1:int,2:int,3:int} xMin, yMin, xMax, yMax in font units (all 0 for an empty glyph). */
    public function glyphBox(int $gid): array
    {
        [$off, $len] = $this->glyphSpan($gid);
        if ($len < 10) {
            return [0, 0, 0, 0];
        }
        return [$this->i16($off + 2), $this->i16($off + 4), $this->i16($off + 6), $this->i16($off + 8)];
    }

    /**
     * A TrueType font holding the outlines of the given glyphs (and the glyphs they are built from), with
     * every glyph id unchanged. Tables are written in tag order; checksums are recomputed.
     *
     * @param list<int> $gids
     */
    public function subset(array $gids): string
    {
        $keep = [];
        $todo = [0, ...$gids];
        while ($todo !== []) {
            $g = array_pop($todo);
            if ($g < 0 || $g >= $this->numGlyphs || isset($keep[$g])) {
                continue;
            }
            $keep[$g] = true;
            foreach ($this->components($g) as $c) {
                if (!isset($keep[$c])) {
                    $todo[] = $c;
                }
            }
        }
        $glyf = '';
        $loca = '';
        for ($g = 0; $g < $this->numGlyphs; $g++) {
            $loca .= pack('N', strlen($glyf));
            if (isset($keep[$g])) {
                [$off, $len] = $this->glyphSpan($g);
                $glyf .= substr($this->data, $off, $len);
                $glyf .= str_repeat("\0", (4 - strlen($glyf) % 4) % 4);
            }
        }
        $loca .= pack('N', strlen($glyf));

        $head = $this->table('head');
        $head = substr_replace($head, "\0\0\0\0", 8, 4);
        $head = substr_replace($head, pack('n', 1), 50, 2);
        $out = ['head' => $head, 'glyf' => $glyf, 'loca' => $loca];
        foreach (['cvt ', 'fpgm', 'prep', 'hhea', 'hmtx', 'maxp', 'OS/2'] as $t) {
            if (isset($this->tables[$t])) {
                $out[$t] = $this->table($t);
            }
        }
        ksort($out, SORT_STRING);

        $n = count($out);
        $sel = (int) floor(log($n, 2));
        $range = 16 * (1 << $sel);
        $font = pack('Nnnnn', 0x00010000, $n, $range, $sel, $n * 16 - $range);
        $offset = 12 + $n * 16;
        $body = '';
        $headAt = 0;
        foreach ($out as $tag => $t) {
            $font .= $tag . pack('NNN', self::checksum($t), $offset + strlen($body), strlen($t));
            if ($tag === 'head') {
                $headAt = $offset + strlen($body);
            }
            $body .= $t . str_repeat("\0", (4 - strlen($t) % 4) % 4);
        }
        $font .= $body;
        $adjust = (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF;
        return substr_replace($font, pack('N', $adjust), $headAt + 8, 4);
    }

    private static function checksum(string $t): int
    {
        $t .= str_repeat("\0", (4 - strlen($t) % 4) % 4);
        $sum = 0;
        foreach (unpack('N*', $t) ?: [] as $v) {
            $sum = ($sum + $v) & 0xFFFFFFFF;
        }
        return $sum;
    }

    /** @return list<int> glyph ids a composite glyph is built from */
    private function components(int $gid): array
    {
        [$off, $len] = $this->glyphSpan($gid);
        if ($len < 10 || $this->i16($off) >= 0) {
            return [];
        }
        $out = [];
        $p = $off + 10;
        $end = $off + $len;
        do {
            if ($p + 4 > $end) {
                break;
            }
            $flags = $this->u16($p);
            $out[] = $this->u16($p + 2);
            $p += 4 + (($flags & 0x0001) ? 4 : 2);
            if ($flags & 0x0008) {
                $p += 2;
            } elseif ($flags & 0x0040) {
                $p += 4;
            } elseif ($flags & 0x0080) {
                $p += 8;
            }
        } while ($flags & 0x0020);
        return $out;
    }

    /** @return array{0:int,1:int} absolute offset and length of a glyph's data */
    private function glyphSpan(int $gid): array
    {
        if ($gid < 0 || $gid >= $this->numGlyphs) {
            return [0, 0];
        }
        $loca = $this->tables['loca'][0];
        if ($this->longLoca) {
            $a = $this->u32($loca + $gid * 4);
            $b = $this->u32($loca + $gid * 4 + 4);
        } else {
            $a = $this->u16($loca + $gid * 2) * 2;
            $b = $this->u16($loca + $gid * 2 + 2) * 2;
        }
        return [$this->tables['glyf'][0] + $a, max(0, $b - $a)];
    }

    /** @return array{kind:int,offset:int} */
    private function pickCmap(): array
    {
        if (!isset($this->tables['cmap'])) {
            return ['kind' => 0, 'offset' => 0];
        }
        $c = $this->tables['cmap'][0];
        $n = $this->u16($c + 2);
        $found = [];
        for ($i = 0; $i < $n; $i++) {
            $r = $c + 4 + $i * 8;
            $sub = $c + $this->u32($r + 4);
            $found[$this->u16($r) . '.' . $this->u16($r + 2) . '.' . $this->u16($sub)] = $sub;
        }
        foreach (['3.10.12' => 12, '0.4.12' => 12, '0.6.12' => 12, '3.1.4' => 4, '0.3.4' => 4] as $key => $kind) {
            if (isset($found[$key])) {
                return ['kind' => $kind, 'offset' => $found[$key]];
            }
        }
        throw new \RuntimeException('Certificate font has no Unicode cmap');
    }

    private function readPostScriptName(): ?string
    {
        if (!isset($this->tables['name'])) {
            return null;
        }
        $o = $this->tables['name'][0];
        $count = $this->u16($o + 2);
        $strings = $o + $this->u16($o + 4);
        for ($i = 0; $i < $count; $i++) {
            $r = $o + 6 + $i * 12;
            if ($this->u16($r + 6) !== 6) {
                continue;
            }
            $raw = substr($this->data, $strings + $this->u16($r + 10), $this->u16($r + 8));
            $name = $this->u16($r) === 1 ? $raw : (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
            $name = (string) preg_replace('/[^A-Za-z0-9-]/', '', $name);
            if ($name !== '') {
                return $name;
            }
        }
        return null;
    }

    private function table(string $tag): string
    {
        [$o, $l] = $this->tables[$tag];
        return substr($this->data, $o, $l);
    }

    private function u16(int $o): int
    {
        return (ord($this->data[$o]) << 8) | ord($this->data[$o + 1]);
    }

    private function i16(int $o): int
    {
        $v = $this->u16($o);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(int $o): int
    {
        return (ord($this->data[$o]) << 24) | (ord($this->data[$o + 1]) << 16) | (ord($this->data[$o + 2]) << 8) | ord($this->data[$o + 3]);
    }
}
