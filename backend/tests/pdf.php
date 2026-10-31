<?php

declare(strict_types=1);

// A tiny reader for the PDFs CertificatePdf writes, so tests can check structure and the text a reader
// would copy out (through each font's ToUnicode map). Not a general PDF parser.

/** @return array<int,string> object number => body (between "N 0 obj\n" and "\nendobj"), found through the xref table */
function pdf_objects(string $pdf): array
{
    if (preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $m) !== 1 || substr($pdf, (int) $m[1], 5) !== "xref\n") {
        return [];
    }
    $at = (int) $m[1] + 5;
    if (preg_match('/\G0 (\d+)\n/', $pdf, $c, 0, $at) !== 1) {
        return [];
    }
    $count = (int) $c[1];
    $at += strlen($c[0]);
    $offsets = [];
    for ($i = 1; $i < $count; $i++) {
        if (preg_match('/^(\d{10}) 00000 n \n$/', substr($pdf, $at + $i * 20, 20), $e) !== 1) {
            return [];
        }
        $offsets[$i] = (int) $e[1];
    }
    // Each object runs up to the next one (or the xref table), so binary stream data cannot confuse the split.
    $ends = array_values($offsets);
    sort($ends);
    $ends[] = (int) $m[1];
    $out = [];
    foreach ($offsets as $i => $off) {
        $head = "$i 0 obj\n";
        $end = $ends[array_search($off, $ends, true) + 1];
        $chunk = substr($pdf, $off, $end - $off);
        if (!str_starts_with($chunk, $head) || !str_ends_with($chunk, "\nendobj\n")) {
            return [];
        }
        $out[$i] = substr($chunk, strlen($head), -strlen("\nendobj\n"));
    }
    return $out;
}

/** True when the xref table, every offset in it, the trailer and every stream /Length are exact. */
function pdf_structure_ok(string $pdf): bool
{
    $objs = pdf_objects($pdf);
    if ($objs === [] || !str_starts_with($pdf, "%PDF-1.4\n") || !str_ends_with($pdf, "%%EOF\n")) {
        return false;
    }
    if (preg_match('/trailer\n<< \/Size (\d+) \/Root 1 0 R \/Info \d+ 0 R >>/', $pdf, $t) !== 1 || (int) $t[1] !== count($objs) + 1) {
        return false;
    }
    foreach ($objs as $body) {
        if (str_contains($body, "stream\n") && pdf_stream($body) === null) {
            return false;
        }
    }
    return true;
}

/** The decoded data of a stream object, or null if its /Length is wrong or it does not inflate. */
function pdf_stream(string $body): ?string
{
    if (preg_match('/^(<<.*?>>)\nstream\n/s', $body, $m) !== 1 || preg_match('/\/Length (\d+)/', $m[1], $l) !== 1) {
        return null;
    }
    $data = substr($body, strlen($m[0]));
    if (strlen($data) !== (int) $l[1] + strlen("\nendstream") || !str_ends_with($data, "\nendstream")) {
        return null;
    }
    $data = substr($data, 0, (int) $l[1]);
    if (str_contains($m[1], '/FlateDecode')) {
        $d = @gzuncompress($data);
        return $d === false ? null : $d;
    }
    return $data;
}

/** @return array<string,array<int,string>> font resource (F1, F2) => CID => text, from each ToUnicode map */
function pdf_tounicode(string $pdf): array
{
    $objs = pdf_objects($pdf);
    preg_match('/\/Font << ((?:\/F\d+ \d+ 0 R ?)+)>>/', $objs[3] ?? '', $m);
    preg_match_all('/\/(F\d+) (\d+) 0 R/', $m[1] ?? '', $fonts, PREG_SET_ORDER);
    $out = [];
    foreach ($fonts as [, $res, $n]) {
        preg_match('/\/ToUnicode (\d+) 0 R/', $objs[(int) $n] ?? '', $tu);
        $cmap = pdf_stream($objs[(int) ($tu[1] ?? 0)] ?? '') ?? '';
        preg_match_all('/<([0-9A-F]{4})> <([0-9A-F]+)>/', $cmap, $pairs, PREG_SET_ORDER);
        foreach ($pairs as [, $cid, $u]) {
            $out[$res][hexdec($cid)] = (string) mb_convert_encoding((string) hex2bin($u), 'UTF-8', 'UTF-16BE');
        }
    }
    return $out;
}

/** @return list<string> the text of each BT..ET block, decoded through the ToUnicode maps; unknown CIDs show as "\u{FFFD}" */
function pdf_text(string $pdf): array
{
    $objs = pdf_objects($pdf);
    $content = pdf_stream($objs[4] ?? '') ?? '';
    $maps = pdf_tounicode($pdf);
    preg_match_all('/BT (.*?) ET/s', $content, $blocks);
    $lines = [];
    foreach ($blocks[1] as $b) {
        preg_match('/\/(F\d+) [\d.]+ Tf/', $b, $f);
        $map = $maps[$f[1] ?? ''] ?? [];
        preg_match_all('/<([0-9A-F]+)>/', $b, $hex);
        $s = '';
        foreach ($hex[1] as $h) {
            foreach (str_split($h, 4) as $cid) {
                $s .= $map[hexdec($cid)] ?? "\u{FFFD}";
            }
        }
        $lines[] = $s;
    }
    return $lines;
}

/** @return array<string,array{cidFont:string,descriptor:string,file:string,cidToGid:string}> per font resource */
function pdf_fonts(string $pdf): array
{
    $objs = pdf_objects($pdf);
    preg_match_all('/\/(F\d+) (\d+) 0 R/', $objs[3] ?? '', $fonts, PREG_SET_ORDER);
    $out = [];
    foreach ($fonts as [, $res, $n]) {
        $t0 = $objs[(int) $n] ?? '';
        preg_match('/\/DescendantFonts \[(\d+) 0 R\]/', $t0, $d);
        $cid = $objs[(int) ($d[1] ?? 0)] ?? '';
        preg_match('/\/FontDescriptor (\d+) 0 R/', $cid, $fd);
        $desc = $objs[(int) ($fd[1] ?? 0)] ?? '';
        preg_match('/\/FontFile2 (\d+) 0 R/', $desc, $ff);
        preg_match('/\/CIDToGIDMap (\d+) 0 R/', $cid, $cg);
        $out[$res] = [
            'type0' => $t0,
            'cidFont' => $cid,
            'descriptor' => $desc,
            'file' => pdf_stream($objs[(int) ($ff[1] ?? 0)] ?? '') ?? '',
            'cidToGid' => pdf_stream($objs[(int) ($cg[1] ?? 0)] ?? '') ?? '',
        ];
    }
    return $out;
}
