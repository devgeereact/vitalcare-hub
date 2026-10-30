<?php

declare(strict_types=1);

// Public certificate verification: three outcomes, no leaks, no enumeration.
use Vitalcare\{Api, CertificateCode, CertificateVerifier, HttpError};

/** @var PDO $pdo */
$learner = make_user($pdo, 'learner@example.com', 'learner');
$approver = make_user($pdo, 'admin@example.com', 'admin');

function cert(PDO $pdo, string $learner, string $approver, array $o = []): string
{
    $code = $o['code'] ?? CertificateCode::generate();
    $status = $o['status'] ?? 'approved';
    $pdo->prepare('INSERT INTO certificates (id, code, learner_id, learner_name, course_title, course_hours, completed_on, expires_on, status, approved_at, approved_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            Vitalcare\Db::uuid(), $code, $learner, $o['name'] ?? 'Lee Learner', $o['title'] ?? 'Basic Life Support',
            array_key_exists('hours', $o) ? $o['hours'] : 3.5, $o['completed'] ?? '2026-01-10', $o['expires'] ?? null, $status,
            $status === 'approved' ? '2026-01-11 09:00:00' : null, $status === 'approved' ? $approver : null,
        ]);
    return $code;
}

// ---- the code itself ----
$codes = [];
for ($i = 0; $i < 500; $i++) {
    $codes[] = CertificateCode::generate();
}
check('codes are 12 characters', count(array_unique(array_map('strlen', $codes))) === 1 && strlen($codes[0]) === 12);
check('codes use only the safe alphabet (no 0, 1, I, L, O)', array_reduce($codes, fn ($c, $x) => $c && preg_match('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{12}$/', $x) === 1, true));
check('500 generated codes are all different', count(array_unique($codes)) === 500);
check('normalise accepts lowercase, spaces and hyphens', CertificateCode::normalise(' abcd-efgh  jkmn ') === 'ABCDEFGHJKMN');
foreach (['', 'ABC', 'ABCDEFGHJKMNP', 'ABCDEFGHJKM0', 'ABCDEFGHJKMI', 'ABCD-EFGH-JKM!', "ABCDEFGHJKM\0", str_repeat('A', 50), "' OR 1=1 --", '../../etc/passwd'] as $bad) {
    check('normalise rejects ' . json_encode(substr($bad, 0, 20)), CertificateCode::normalise($bad) === null);
}
check('display groups in fours', CertificateCode::display('ABCDEFGHJKMN') === 'ABCD-EFGH-JKMN');

// ---- the three outcomes ----
$v = new CertificateVerifier($pdo);
$london = new DateTimeZone('Europe/London');
$at = fn (string $s) => new DateTimeImmutable($s, new DateTimeZone('UTC'));

$noExpiry = cert($pdo, $learner, $approver);
$r = $v->check($noExpiry, $at('2026-10-30 12:00'));
check('approved with no expiry is valid', $r['status'] === 'valid' && $r['certificate']['expiresOn'] === null);
check('valid answer carries the issue snapshot', $r['certificate']['learnerName'] === 'Lee Learner' && $r['certificate']['courseTitle'] === 'Basic Life Support' && $r['certificate']['hours'] === 3.5 && $r['certificate']['completedOn'] === '2026-01-10');
check('valid answer names the issuing company', $r['certificate']['issuer'] === 'Vitalcare Training Hub Ltd');
check('the code is shown in groups of four', preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $r['certificate']['code']) === 1);
check('valid answer exposes nothing internal', array_keys($r['certificate']) === ['code', 'learnerName', 'courseTitle', 'hours', 'completedOn', 'expiresOn', 'issuer']);
$noHours = cert($pdo, $learner, $approver, ['code' => 'NHKRSAAAAAAA', 'hours' => null]);
$rh = $v->check($noHours, $at('2026-10-30 12:00'));
check('hours may be absent', $rh['status'] === 'valid' && array_key_exists('hours', $rh['certificate']) && $rh['certificate']['hours'] === null);

$withExpiry = cert($pdo, $learner, $approver, ['expires' => '2027-03-03']);
check('valid the day before expiry', $v->check($withExpiry, $at('2027-03-02 12:00'))['status'] === 'valid');
check('valid on the expiry date itself (last day of validity)', $v->check($withExpiry, $at('2027-03-03 23:30'))['status'] === 'valid');
check('expired the day after', $v->check($withExpiry, $at('2027-03-04 00:30'))['status'] === 'expired');
check('expired answer still carries the snapshot and the date', ($x = $v->check($withExpiry, $at('2027-03-04 00:30')))['certificate']['expiresOn'] === '2027-03-03' && $x['certificate']['learnerName'] === 'Lee Learner');

// British Summer Time: 23:30 UTC on 30 June is 00:30 on 1 July in London, so it has expired there.
$summer = cert($pdo, $learner, $approver, ['expires' => '2027-06-30']);
check('London date decides: 23:30 UTC on the last day in BST is already expired', $v->check($summer, $at('2027-06-30 23:30'))['status'] === 'expired');
check('London date decides: 22:30 UTC on the last day in BST is still valid', $v->check($summer, $at('2027-06-30 22:30'))['status'] === 'valid');

// Everything else is one identical "not issued" with no data.
$draft = cert($pdo, $learner, $approver, ['status' => 'draft']);
$withdrawn = cert($pdo, $learner, $approver, ['status' => 'withdrawn']);
$unknown = CertificateCode::generate();
$answers = [
    'draft' => $v->check($draft, $at('2026-10-30 12:00')),
    'withdrawn' => $v->check($withdrawn, $at('2026-10-30 12:00')),
    'unknown' => $v->check($unknown, $at('2026-10-30 12:00')),
    'malformed' => $v->check('nonsense', $at('2026-10-30 12:00')),
    'sql' => $v->check("' OR '1'='1", $at('2026-10-30 12:00')),
];
foreach ($answers as $label => $a) {
    check("$label is not issued with no data", $a === ['status' => 'not_issued']);
}
check('all five not-issued answers are byte-identical (no enumeration oracle)', count(array_unique(array_map('json_encode', $answers))) === 1);
check('a withdrawn certificate carries no learner name anywhere in the answer', !str_contains(json_encode($answers['withdrawn']), 'Lee'));

// Database rules back the application rules.
$fails = function (string $label, callable $fn) {
    try { $fn(); check($label, false, 'no exception'); } catch (PDOException) { check($label, true); }
};
$fails('an approved certificate must record who approved it', fn () => $pdo->prepare("INSERT INTO certificates (id, code, learner_id, learner_name, course_title, completed_on, status) VALUES (?,?,?,?,?,?, 'approved')")->execute([Vitalcare\Db::uuid(), 'BADAPPROVEDX', $learner, 'X', 'Y', '2026-01-01']));
$fails('expiry cannot precede completion', fn () => cert($pdo, $learner, $approver, ['expires' => '2025-01-01']));
$fails('a code is unique', fn () => cert($pdo, $learner, $approver, ['code' => $noExpiry]));
$fails('a status outside draft/approved/withdrawn is refused', fn () => $pdo->prepare("INSERT INTO certificates (id, code, learner_id, learner_name, course_title, completed_on, status) VALUES (?,?,?,?,?,?, 'issued')")->execute([Vitalcare\Db::uuid(), 'BADSTATUSAAA', $learner, 'X', 'Y', '2026-01-01']));

// ---- the endpoint ----
$now = new DateTimeImmutable('2026-10-30 12:00:00', new DateTimeZone('UTC'));
$api = new Api(fn (string $t): array => throw HttpError::unauthorized(), $pdo, '8.4.0', true, null, false, 'info@vitalcare.uk', fn () => $now);
[$s, $b, $h] = $api->handle('GET', '/api/verify/' . $noExpiry, null, null, '203.0.113.7');
check('endpoint: valid certificate is 200', $s === 200 && $b['status'] === 'valid');
check('endpoint: answers are never cached', ($h['Cache-Control'] ?? '') === 'no-store');
[$s, $b] = $api->handle('GET', '/api/verify/' . strtolower(CertificateCode::display($noExpiry)), null, null, '203.0.113.7');
check('endpoint: lowercase hyphenated code works', $s === 200 && $b['status'] === 'valid');
[$s, $b] = $api->handle('GET', '/api/verify/%27%20OR%201%3D1', null, null, '203.0.113.7');
check('endpoint: hostile input is a plain not issued', $s === 200 && $b === ['status' => 'not_issued']);
check('endpoint: needs no sign-in (public)', $api->handle('GET', '/api/verify/' . $noExpiry, null, null, '203.0.113.8')[0] === 200);
check('endpoint: POST is refused', $api->handle('POST', '/api/verify/' . $noExpiry, null, null, '203.0.113.9')[0] === 405);

// Rate limit: 30 per 5 minutes per caller, then 429 with Retry-After; another caller is unaffected.
$ip = '198.51.100.20';
$last = null;
for ($i = 1; $i <= 30; $i++) {
    $last = $api->handle('GET', '/api/verify/' . $unknown, null, null, $ip);
}
check('endpoint: 30 checks in the window are allowed', $last[0] === 200);
[$s, $b, $h] = $api->handle('GET', '/api/verify/' . $unknown, null, null, $ip);
check('endpoint: the 31st is 429 rate_limited', $s === 429 && $b['error']['code'] === 'rate_limited');
check('endpoint: 429 carries Retry-After', isset($h['Retry-After']) && (int) $h['Retry-After'] > 0 && (int) $h['Retry-After'] <= 300);
check('endpoint: a different caller is not limited', $api->handle('GET', '/api/verify/' . $unknown, null, null, '198.51.100.21')[0] === 200);
check('endpoint: the stored bucket is a hash, not the address', $pdo->query("SELECT COUNT(*) FROM rate_limits WHERE bucket LIKE '%198.51.100%'")->fetchColumn() == 0);
check('endpoint: with no database it is 503, not open', (new Api(fn () => [], null))->handle('GET', '/api/verify/' . $noExpiry, null, null, '1.1.1.1')[0] === 503);
