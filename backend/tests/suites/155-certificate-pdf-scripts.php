<?php

declare(strict_types=1);

// Certificate PDF: names in every covered script print with the embedded Noto Sans (no "?"), copy back out
// through ToUnicode, give the same bytes every time, and pass a structural (and, when installed, qpdf) check.
// Scripts the font does not cover fall back to a visible box and are reported by CertificatePdf::missing().
use Vitalcare\Certificates\CertificatePdf;
use Vitalcare\Certificates\TrueTypeFont;

require_once __DIR__ . '/../pdf.php';

$cert = static fn (string $name, string $course = 'Moving and Handling'): array => [
    'code' => 'ABCDEFGHJKMN', 'learnerName' => $name, 'courseTitle' => $course, 'hours' => 3.0,
    'completedOn' => '2026-10-01', 'issuedOn' => '2026-10-02', 'expiresOn' => '2027-10-01',
];
$intl = class_exists(Normalizer::class);
$nfc = static fn (string $s): string => $intl ? (string) Normalizer::normalize($s, Normalizer::FORM_C) : $s;
if (!$intl) {
    echo "  (the intl extension is missing: names are drawn as stored, without NFC; the NFC check is skipped)\n";
}
$qpdf = trim((string) shell_exec('command -v qpdf 2>/dev/null'));
$tmp = sys_get_temp_dir() . '/vc-pdf-' . getmypid();
@mkdir($tmp, 0700, true);

// ---- the font and its licence ----
$dir = CertificatePdf::fontDir();
$ofl = (string) @file_get_contents("$dir/OFL.txt");
check('font: the SIL Open Font License text ships beside the fonts', str_contains($ofl, 'SIL OPEN FONT LICENSE Version 1.1') && str_contains($ofl, 'The Noto Project Authors'));
foreach (['F1' => 'NotoSans-Regular', 'F2' => 'NotoSans-Bold'] as $res => $ps) {
    $f = CertificatePdf::font($res);
    check("font $res: is $ps", $f->postScriptName === $ps);
    check("font $res: licensed for installable embedding (OS/2 fsType 0)", $f->fsType === 0);
}

// ---- one name per covered script ----
$names = [
    'Latin (Polish, Romanian, German, French)' => 'Łukasz Ştefan Ţăran Żółć-Müller François',
    'Latin Extended (Czech, Turkish, Icelandic, Maltese)' => 'Dvořák Ğülşen Þórdís Ħabib Ǆemal',
    'Vietnamese' => 'Nguyễn Thị Hồng Phượng Đặng',
    'Yoruba (dot below and tone marks)' => 'Ọlá Adéṣínà Ẹ̀kọ́ Ọ̀ṣun Ìbàdàn',
    'Igbo (dots and n with dot above)' => 'Ngozi Ụzọ Ịfẹ́ Ọ̀kọ̀ ṅ',
    'Greek' => 'Αλέξανδρος Παπαδόπουλος-Ψυχάρης',
    'Cyrillic (Russian, Ukrainian, Serbian)' => 'Владимир Ёжиков-Щукин Їжак Ђорђе',
];
foreach ($names as $script => $name) {
    $pdf = CertificatePdf::render($cert($name, "Safeguarding — $name"));
    $want = $nfc($name);
    $lines = pdf_text($pdf);
    check("$script: every character has a glyph in both fonts", CertificatePdf::missing($name) === [], implode(' ', CertificatePdf::missing($name)));
    check("$script: the name copies back out exactly (ToUnicode round trip)", in_array($want, $lines, true), json_encode($lines, JSON_UNESCAPED_UNICODE));
    check("$script: the course line copies back out too", in_array("Safeguarding — $want", $lines, true));
    check("$script: no \"?\" or replacement character anywhere in the text", !str_contains(implode('', $lines), '?') && !str_contains(implode('', $lines), "\u{FFFD}"));
    check("$script: the same certificate gives identical bytes", CertificatePdf::render($cert($name, "Safeguarding — $name")) === $pdf);
    check("$script: xref offsets, trailer and stream lengths are exact", pdf_structure_ok($pdf));
    check("$script: a reasonable size (under 60 KB)", strlen($pdf) < 60000, (string) strlen($pdf));
    $fonts = pdf_fonts($pdf);
    $okFonts = count($fonts) === 2;
    foreach ($fonts as $res => $fo) {
        $okFonts = $okFonts
            && str_contains($fo['type0'], '/Subtype /Type0') && str_contains($fo['type0'], '/Encoding /Identity-H') && str_contains($fo['type0'], '/ToUnicode')
            && str_contains($fo['cidFont'], '/Subtype /CIDFontType2') && str_contains($fo['cidFont'], '/W [1 [')
            && preg_match('/\/FontName \/[A-Z]{6}\+NotoSans-(Regular|Bold) /', $fo['descriptor']) === 1
            && str_starts_with($fo['file'], "\x00\x01\x00\x00");
        // The embedded subset is a real TrueType font, and every CID points at a glyph that has an outline.
        file_put_contents("$tmp/$res.ttf", $fo['file']);
        $sub = new TrueTypeFont("$tmp/$res.ttf");
        $gids = array_values(unpack('n*', $fo['cidToGid']) ?: []);
        $space = CertificatePdf::font($res)->glyphId(32);
        foreach (array_slice($gids, 1) as $g) {
            $okFonts = $okFonts && $g !== 0 && ($g === $space || $sub->glyphBox($g) !== [0, 0, 0, 0]);
        }
        $okFonts = $okFonts && strlen($fo['file']) < strlen((string) file_get_contents($dir . '/' . ($res === 'F1' ? 'NotoSans-Regular.ttf' : 'NotoSans-Bold.ttf'))) / 4;
    }
    check("$script: both fonts embedded as subsetted CID fonts with outlines for every glyph used", $okFonts);
    if ($qpdf !== '') {
        file_put_contents("$tmp/check.pdf", $pdf);
        exec(escapeshellarg($qpdf) . ' --check ' . escapeshellarg("$tmp/check.pdf") . ' 2>&1', $out, $rc);
        check("$script: qpdf --check passes", $rc === 0, implode(' ', array_slice($out, -2)));
        $out = [];
    }
}
if ($qpdf === '') {
    echo "  (qpdf is not installed: the structural xref check above stands in for it)\n";
}

// ---- normalisation and marks ----
if ($intl) {
    $decomposed = (string) Normalizer::normalize('Nguyễn Ọlá Ştefan', Normalizer::FORM_D);
    check('NFC: a decomposed name gives the same bytes as the composed one', $decomposed !== 'Nguyễn Ọlá Ştefan' && CertificatePdf::render($cert($decomposed)) === CertificatePdf::render($cert('Nguyễn Ọlá Ştefan')));
}
$yo = CertificatePdf::render($cert('Ẹ̀kọ́'));
preg_match_all('/BT .*? ET/s', (string) pdf_stream(pdf_objects($yo)[4]), $bt);
$nameOps = array_values(array_filter($bt[0], fn ($b) => str_contains($b, '/F2 30 Tf')))[0] ?? '';
preg_match_all('/ (-?[\d.]+) Ts /', $nameOps, $rises);
check('marks: a tone mark on a capital is raised (text rise) and the rise is reset', preg_match('/ [1-9][\d.]* Ts /', $nameOps) === 1 && end($rises[1]) === '0', $nameOps);
// "... <letter> 126] TJ 5.04 Ts [<mark>] TJ 0 Ts [-126 <next> ...": moved back over its letter, then forward again.
$moved = preg_match('/ (-?[\d.]+)\] TJ [\d.]+ Ts \[<[0-9A-F]{4}>\] TJ 0 Ts \[(-?[\d.]+)[ \]]/', $nameOps, $mv) === 1;
check('marks: the mark is moved back over its letter and the pen restored (TJ adjustments)', $moved && (float) $mv[1] !== 0.0 && (float) $mv[1] === -(float) $mv[2], $nameOps);
$ga = pdf_text(CertificatePdf::render($cert('A Α А')));
check('look-alikes: Latin A, Greek Alpha and Cyrillic A copy out as themselves', in_array('A Α А', $ga, true));

// ---- scripts that are NOT covered: a visible box, never "?", and still the right text when copied ----
foreach (['Arabic' => 'محمد', 'Hebrew' => 'דוד', 'Chinese' => '王小明', 'Japanese' => 'さくら', 'Korean' => '김민준', 'Devanagari' => 'प्रिया', 'Thai' => 'สมชาย'] as $script => $name) {
    $pdf = CertificatePdf::render($cert("Ana $name"));
    check("$script (not covered): missing() reports it", CertificatePdf::missing($name) !== []);
    $fo = pdf_fonts($pdf)['F2'];
    $gids = array_values(unpack('n*', $fo['cidToGid']) ?: []);
    check("$script (not covered): drawn with the font's visible .notdef box, not \"?\"", in_array(0, array_slice($gids, 1), true) && !in_array('Ana ?', pdf_text($pdf), true));
    check("$script (not covered): still copies out as the real characters", in_array('Ana ' . $nfc($name), pdf_text($pdf), true));
    check("$script (not covered): the PDF is still well formed and deterministic", pdf_structure_ok($pdf) && CertificatePdf::render($cert("Ana $name")) === $pdf);
}
check('missing(): a fully covered name reports nothing', CertificatePdf::missing('Zoë Łukasz Nguyễn Ọlá Ψ Ж') === []);
check('missing(): lists each uncovered character once', CertificatePdf::missing('محمد محمد') === ['م', 'ح', 'د']);
check('control characters become spaces', in_array('Pat Jones', pdf_text(CertificatePdf::render($cert("Pat\tJones"))), true));

array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);
