<?php

declare(strict_types=1);

// Certificates in the CMS: expiry maths, issue from attendance or by hand, revoke, verify states, PDF.
use Vitalcare\Certificates\CertificateCode;
use Vitalcare\Certificates\CertificatePdf;
use Vitalcare\Certificates\CertificateState;
use Vitalcare\Certificates\Expiry;
use Vitalcare\Http\RawBody;
use Vitalcare\Support\Db;

/** @var PDO $pdo */
make_user($pdo, 'office@example.com', 'admin');
make_user($pdo, 'owner@example.com', 'super_admin');
$now = new DateTimeImmutable('2026-10-31 10:00:00', new DateTimeZone('UTC'));
$call = cms_api($pdo, function () use (&$now) { return $now; });

// ---- expiry maths ----
$e = [
    ['2026-01-10', 12, '2027-01-10', 'a year later, same day'],
    ['2026-01-31', 1, '2026-02-28', '31 Jan + 1 month clamps to 28 Feb'],
    ['2028-01-31', 1, '2028-02-29', 'clamps to 29 Feb in a leap year'],
    ['2028-02-29', 12, '2029-02-28', '29 Feb + 12 months is 28 Feb'],
    ['2028-02-29', 48, '2032-02-29', '29 Feb + 48 months is 29 Feb again'],
    ['2026-08-31', 6, '2027-02-28', '31 Aug + 6 months clamps across the year end'],
    ['2026-03-31', 1, '2026-04-30', '31 Mar + 1 month is 30 Apr'],
    ['2026-12-15', 1, '2027-01-15', 'December rolls into January'],
    ['2026-11-30', 3, '2027-02-28', '30 Nov + 3 months clamps'],
    ['2026-05-20', 120, '2036-05-20', 'the 120-month maximum'],
    ['2026-10-31', 24, '2028-10-31', 'two years'],
];
foreach ($e as [$from, $months, $want, $label]) {
    check("expiry: $label", Expiry::compute($from, $months) === $want, (string) Expiry::compute($from, $months));
}
check('expiry: no renewal period means no expiry', Expiry::compute('2026-10-31', null) === null);
foreach ([0, 121, -1] as $bad) {
    try {
        Expiry::compute('2026-01-01', $bad);
        check("expiry: $bad months is refused", false);
    } catch (InvalidArgumentException) {
        check("expiry: $bad months is refused", true);
    }
}
try {
    Expiry::compute('2026-02-30', 12);
    check('expiry: an impossible date is refused', false);
} catch (InvalidArgumentException) {
    check('expiry: an impossible date is refused', true);
}

// ---- state rule ----
check('state: approved, no expiry is valid', CertificateState::of('approved', null, null, '2026-10-31') === 'valid');
check('state: valid on the expiry date itself', CertificateState::of('approved', null, '2026-10-31', '2026-10-31') === 'valid');
check('state: expired the day after', CertificateState::of('approved', null, '2026-10-30', '2026-10-31') === 'expired');
check('state: revoked needs a revocation record', CertificateState::of('withdrawn', '2026-10-01 10:00:00', '2027-01-01', '2026-10-31') === 'revoked');
check('state: withdrawn without a record is not issued', CertificateState::of('withdrawn', null, '2027-01-01', '2026-10-31') === 'not_issued');
check('state: draft is not issued', CertificateState::of('draft', null, null, '2026-10-31') === 'not_issued');
check('state: an approved row with a stray revoked_at is still judged by status', CertificateState::of('approved', '2026-10-01 10:00:00', null, '2026-10-31') === 'valid');
check('state: an unknown status is not issued', CertificateState::of('issued', null, null, '2026-10-31') === 'not_issued');

// ---- issue from attendance ----
$org = make_org($pdo, 'Oak House');
$course = make_course($pdo, ['title' => 'Moving and Handling', 'renewal' => 12, 'hours' => 3]);
$pat = make_person($pdo, ['first' => 'Pat', 'last' => 'Jones', 'org' => $org]);
// 23:30 UTC on 31 Aug is 00:30 on 1 Sep in London: the London date is the completion date.
[$sid, $aid] = make_attendance($pdo, $course, $pat, '2026-08-31 23:30:00');
[$s, $b] = $call('POST', '/api/admin/certificates', 'office', ['attendeeId' => $aid]);
check('admin issues from an attended pass', $s === 201, json_encode($b));
$cert = $b['certificate'];
check('completion is the London date of the session', $cert['completedOn'] === '2026-09-01');
check('expiry is computed on the server from the course', $cert['expiresOn'] === '2027-09-01');
check('issue date is today in London', $cert['issuedOn'] === '2026-10-31');
check('snapshot: name, course, hours', $cert['personName'] === 'Pat Jones' && $cert['courseTitle'] === 'Moving and Handling' && $cert['hours'] === 3.0);
check('state valid, not by hand', $cert['state'] === 'valid' && $cert['byHand'] === false);
check('the code is random and well formed', CertificateCode::normalise($cert['code']) !== null);
[$s, $b] = $call('POST', '/api/admin/certificates', 'office', ['attendeeId' => $aid]);
check('issuing twice for one attendance is 409 already_issued', $s === 409 && $b['error']['code'] === 'already_issued');
check('only one row exists', (int) $pdo->query("SELECT COUNT(*) FROM certificates WHERE attendee_id = '$aid'")->fetchColumn() === 1);
check('client-sent expiry or name is ignored', $call('POST', '/api/admin/certificates', 'office', ['attendeeId' => make_attendance($pdo, $course, make_person($pdo), '2026-10-01 09:00:00')[1], 'expiresOn' => '2099-01-01', 'learnerName' => 'Someone Else'])[1]['certificate']['expiresOn'] === '2027-10-01');
$pdo->exec("UPDATE courses SET title = 'Renamed', renewal_months = 36 WHERE id = '$course'");
check('editing the course later never rewrites an issued certificate', $call('GET', '/api/admin/certificates/' . $cert['id'], 'office')[1]['certificate']['courseTitle'] === 'Moving and Handling');
$pdo->exec("UPDATE courses SET title = 'Moving and Handling', renewal_months = 12 WHERE id = '$course'");

foreach ([['no_show', null, 'a no-show'], ['attended', 'fail', 'a fail'], ['attended', null, 'no result yet'], ['booked', null, 'still booked']] as [$att, $res, $label]) {
    [, $x] = make_attendance($pdo, $course, make_person($pdo), '2026-10-01 09:00:00', $att, $res);
    [$s, $b] = $call('POST', '/api/admin/certificates', 'office', ['attendeeId' => $x]);
    check("$label cannot get a certificate (409 not_eligible)", $s === 409 && $b['error']['code'] === 'not_eligible');
}
[, $na] = make_attendance($pdo, $course, make_person($pdo), '2026-10-01 09:00:00', 'attended', 'not_assessed');
check('"not assessed" can get a certificate', $call('POST', '/api/admin/certificates', 'office', ['attendeeId' => $na])[0] === 201);
[, $future] = make_attendance($pdo, $course, make_person($pdo), '2026-11-20 09:00:00');
check('a session in the future cannot be certified yet', $call('POST', '/api/admin/certificates', 'office', ['attendeeId' => $future])[1]['error']['code'] === 'not_eligible');
check('an unknown attendance is 404', $call('POST', '/api/admin/certificates', 'office', ['attendeeId' => Db::uuid()])[0] === 404);
$noExpiry = make_course($pdo, ['renewal' => null, 'title' => 'Induction']);
[, $ne] = make_attendance($pdo, $noExpiry, make_person($pdo), '2026-10-01 09:00:00');
check('a course with no renewal period gives no expiry', $call('POST', '/api/admin/certificates', 'office', ['attendeeId' => $ne])[1]['certificate']['expiresOn'] === null);
check('changing a certified attendance is refused until revoked', $call('PATCH', "/api/admin/attendees/$aid", 'office', ['result' => 'fail'])[1]['error']['code'] === 'certificate_issued');

// ---- by hand (owner only, with a reason) ----
$manual = ['personId' => $pat, 'courseId' => $course, 'completedOn' => '2025-01-31', 'reason' => 'Paper register from January 2025'];
check('admin cannot issue by hand', $call('POST', '/api/admin/certificates', 'office', $manual)[0] === 403);
[$s, $b] = $call('POST', '/api/admin/certificates', 'owner', ['reason' => 'short'] + $manual);
check('a reason under 10 characters is refused', $s === 422 && isset($b['error']['fields']['reason']));
check('a future completion date is refused', $call('POST', '/api/admin/certificates', 'owner', ['completedOn' => '2026-11-01'] + $manual)[0] === 422);
check('a missing person is refused', $call('POST', '/api/admin/certificates', 'owner', ['personId' => Db::uuid()] + $manual)[0] === 422);
[$s, $b] = $call('POST', '/api/admin/certificates', 'owner', $manual);
check('owner issues by hand', $s === 201 && $b['certificate']['byHand'] === true && $b['certificate']['issueReason'] === 'Paper register from January 2025');
check('by-hand expiry uses the month-end rule', $b['certificate']['expiresOn'] === '2026-01-31' && $b['certificate']['state'] === 'expired');

// ---- revoke (owner only) ----
$cid = $cert['id'];
check('admin cannot revoke', $call('POST', "/api/admin/certificates/$cid/revoke", 'office', ['reason' => 'Issued in error'])[0] === 403);
check('a revoke needs a reason', $call('POST', "/api/admin/certificates/$cid/revoke", 'owner', ['reason' => ''])[0] === 422);
[$s, $b] = $call('POST', "/api/admin/certificates/$cid/revoke", 'owner', ['reason' => 'Issued to the wrong person']);
check('owner revokes', $s === 200 && $b['certificate']['state'] === 'revoked' && $b['certificate']['revokedOn'] === '2026-10-31' && $b['certificate']['revokeReason'] === 'Issued to the wrong person');
check('revoking twice is 409', $call('POST', "/api/admin/certificates/$cid/revoke", 'owner', ['reason' => 'Issued to the wrong person'])[1]['error']['code'] === 'not_approved');
check('revoking a missing certificate is 404', $call('POST', '/api/admin/certificates/' . Db::uuid() . '/revoke', 'owner', ['reason' => 'Issued to the wrong person'])[0] === 404);
check('after revoking, the attendance can be corrected', $call('PATCH', "/api/admin/attendees/$aid", 'office', ['result' => 'fail'])[0] === 200);

// ---- public verify: the four states ----
$code = $cert['code'];
[$s, $b] = $call('GET', '/api/verify/' . $code);
check('verify: revoked says revoked', $s === 200 && $b['status'] === 'revoked');
check('verify: revoked shows no name and no reason', !str_contains(json_encode($b), 'Pat') && !str_contains(json_encode($b), 'wrong person'));
check('verify: revoked shows only code, course, dates, issuer', array_keys($b['certificate']) === ['code', 'courseTitle', 'completedOn', 'revokedOn', 'issuer']);
$valid = $call('POST', '/api/admin/certificates', 'office', ['attendeeId' => $na])[1];
$validCode = $pdo->query("SELECT code FROM certificates WHERE attendee_id = '$na'")->fetchColumn();
$v = $call('GET', "/api/verify/$validCode")[1];
check('verify: valid shows the snapshot', $v['status'] === 'valid' && $v['certificate']['courseTitle'] === 'Moving and Handling');
$oldCode = $pdo->query("SELECT code FROM certificates WHERE issue_reason IS NOT NULL")->fetchColumn();
check('verify: expired', $call('GET', "/api/verify/$oldCode")[1]['status'] === 'expired');
check('verify: not issued for an unknown code, with nothing else', $call('GET', '/api/verify/' . CertificateCode::generate())[1] === ['status' => 'not_issued']);

// ---- lists ----
[$s, $b] = $call('GET', '/api/admin/certificates', 'office', null, ['status' => 'revoked']);
check('list: filter revoked', $s === 200 && $b['total'] === 1 && $b['items'][0]['id'] === $cid);
check('list: filter expired', $call('GET', '/api/admin/certificates', 'office', null, ['status' => 'expired'])[1]['total'] === 1);
check('list: search by code typed loosely', $call('GET', '/api/admin/certificates', 'office', null, ['q' => strtolower(CertificateCode::display((string) $validCode))])[1]['total'] === 1);

// ---- PDF ----
[$s, $b, $h] = $call('GET', '/api/admin/certificates/' . $cid . '/pdf', 'office');
check('no PDF for a revoked certificate', $s === 409);
$okId = $pdo->query("SELECT id FROM certificates WHERE attendee_id = '$na'")->fetchColumn();
[$s, $b] = $call('GET', "/api/admin/certificates/$okId/pdf", 'office');
check('PDF download is a RawBody attachment', $s === 200 && $b instanceof RawBody && $b->contentType === 'application/pdf' && str_contains($b->headers()['Content-Disposition'], 'certificate-' . $validCode . '.pdf'));
$pdf = $b->content;
check('PDF: header and end marker', str_starts_with($pdf, "%PDF-1.4\n") && str_ends_with($pdf, "%%EOF\n"));
preg_match('/startxref\n(\d+)\n/', $pdf, $m);
check('PDF: startxref points at the xref table', isset($m[1]) && substr($pdf, (int) $m[1], 4) === 'xref');
preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $offs);
$okOffsets = true;
foreach ($offs[1] as $i => $o) {
    $okOffsets = $okOffsets && substr($pdf, (int) $o, strlen(($i + 1) . ' 0 obj')) === ($i + 1) . ' 0 obj';
}
check('PDF: every xref offset points at its object', count($offs[1]) === 7 && $okOffsets);
preg_match('/\/Length (\d+) >>\nstream\n/', $pdf, $len, PREG_OFFSET_CAPTURE);
$streamStart = $len[0][1] + strlen($len[0][0]);
check('PDF: the stream length is exact', substr($pdf, $streamStart + (int) $len[1][0], 10) === "\nendstream");
check('PDF: carries the code, name and course', str_contains($pdf, CertificateCode::display((string) $validCode)) && str_contains($pdf, 'Moving and Handling') && str_contains($pdf, 'vitalcare.uk/verify/' . $validCode));
check('PDF: no email address in it', !str_contains($pdf, '@'));
check('PDF: the same certificate gives the same bytes', $call('GET', "/api/admin/certificates/$okId/pdf", 'office')[1]->content === $pdf);
$tricky = CertificatePdf::render(['code' => 'ABCDEFGHJKMN', 'learnerName' => 'Zoë (née) O\'Brien \\ Ł', 'courseTitle' => str_repeat('Very long course title ', 12), 'hours' => 1.0, 'completedOn' => '2026-10-01', 'issuedOn' => null, 'expiresOn' => null]);
check('PDF: parentheses and backslashes are escaped', str_contains($tricky, '(n\\351e\\)') === false && str_contains($tricky, '\\(n') && str_contains($tricky, '\\\\'));
check('PDF: Latin-1 letters are kept, others become ?', str_contains($tricky, "Zo\xEB") && str_contains($tricky, '?'));
check('PDF: one hour is singular, and no expiry is said plainly', str_contains($tricky, '\\(1 hour\\)') && str_contains($tricky, 'No expiry date is recorded'));
check('PDF: a long title wraps instead of running off the page', substr_count($tricky, 'Very long course title') >= 2 && preg_match_all('/Td \(Very long/', $tricky) >= 2);
check('PDF: font tables have 95 widths', count(CertificatePdf::widths('F1')) === 95 && count(CertificatePdf::widths('F2')) === 95);
check('PDF: Helvetica width of "Hello" at 10pt is 22.78', abs(CertificatePdf::width('Hello', 'F1', 10) - 22.78) < 0.001);
$dl = $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'certificate.download'")->fetchColumn();
check('downloads are audited', (int) $dl === 2);
