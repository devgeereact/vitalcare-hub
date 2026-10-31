<?php

declare(strict_types=1);

// CSV formula-injection safety, owner-only exports, the audit viewer, and staff accounts.
use Vitalcare\Cms\Audit;
use Vitalcare\Cms\Csv;
use Vitalcare\Http\RawBody;
use Vitalcare\Support\Db;

/** @var PDO $pdo */
$office = make_user($pdo, 'office@example.com', 'admin');
$owner = make_user($pdo, 'owner@example.com', 'super_admin');
$now = new DateTimeImmutable('2026-10-31 10:00:00', new DateTimeZone('UTC'));
$call = cms_api($pdo, fn () => $now);

// ---- CSV cells ----
$cases = [
    ['=1+1', '"\'=1+1"'], ['+44 20 8059', '"\'+44 20 8059"'], ['-2', '"\'-2"'], ['@SUM(A1)', '"\'@SUM(A1)"'],
    ["\t=cmd", "\"'\t=cmd\""], ["\r=cmd", "\"'\r=cmd\""], ['  =HYPERLINK("x")', '"\'  =HYPERLINK(""x"")"'],
    ['＝1+1', '"\'＝1+1"'], ['Pat "PJ" Jones', '"Pat ""PJ"" Jones"'], ['O\'Brien', '"O\'Brien"'], ['a,b', '"a,b"'],
    ["line1\nline2", "\"line1\nline2\""], [null, '""'], [true, '"yes"'], [3.5, '"3.5"'], ['Plain', '"Plain"'], ['2026-10-31', '"2026-10-31"'],
];
foreach ($cases as [$in, $want]) {
    check('csv cell ' . json_encode($in), Csv::cell($in) === $want, Csv::cell($in));
}
$file = Csv::build(['A', 'B'], [['=x', 'y'], ['1', null]]);
check('csv: BOM, CRLF lines, header first', $file === "\xEF\xBB\xBF\"A\",\"B\"\r\n\"'=x\",\"y\"\r\n\"1\",\"\"\r\n");

// ---- exports ----
$org = make_org($pdo, '=Evil Org');
make_person($pdo, ['first' => '=HYPERLINK("http://x")', 'last' => '@Jones', 'email' => 'pat@example.org', 'org' => $org]);
make_person($pdo, ['first' => 'Ann', 'last' => 'Able', 'email' => null]);
check('admin cannot export people', $call('GET', '/api/admin/export/people.csv', 'office')[0] === 403);
[$s, $b] = $call('GET', '/api/admin/export/people.csv', 'owner');
check('owner gets a CSV attachment', $s === 200 && $b instanceof RawBody && str_starts_with($b->contentType, 'text/csv') && str_contains($b->headers()['Content-Disposition'], 'people-2026-10-31.csv'));
$lines = explode("\r\n", trim($b->content));
check('one header and two rows', count($lines) === 3);
check('no exported cell starts a formula', preg_match('/(^|,)"[=+\-@]/m', $b->content) === 0 && str_contains($b->content, '"\'=HYPERLINK(""http://x"")"') && str_contains($b->content, '"\'@Jones"') && str_contains($b->content, '"\'=Evil Org"'));
$course = make_course($pdo, ['title' => '+Fire Safety']);
$pdo->prepare("INSERT INTO certificates (id, code, person_id, course_id, learner_name, course_title, completed_on, expires_on, status, approved_at, approved_by)
               VALUES (?, 'ABCDEFGHJKMN', (SELECT id FROM people WHERE last_name = 'Able'), ?, '-Ann Able', '+Fire Safety', '2025-10-01', '2026-10-01', 'approved', CURRENT_TIMESTAMP(3), ?)")
    ->execute([Db::uuid(), $course, $owner]);
check('admin cannot export certificates', $call('GET', '/api/admin/export/certificates.csv', 'office')[0] === 403);
[$s, $b] = $call('GET', '/api/admin/export/certificates.csv', 'owner');
check('certificate export is safe and states the state', $s === 200 && str_contains($b->content, '"ABCD-EFGH-JKMN","\'-Ann Able"') && str_contains($b->content, '"\'+Fire Safety"') && str_contains($b->content, '"expired"'), $b->content ?? '');
check('exports are audited without content', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('export.people', 'export.certificates') AND fields = ''")->fetchColumn() === 2);

// ---- audit log ----
check('the audit log refuses a value-looking field name', (function () use ($pdo) {
    try {
        Audit::log($pdo, null, 'person.update', 'person', null, ['pat@example.org']);
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
})());
$call('POST', '/api/admin/people', 'office', ['firstName' => 'Zara', 'lastName' => 'Zed', 'email' => 'zara@example.org']);
check('admin cannot read the audit log', $call('GET', '/api/admin/audit', 'office')[0] === 403);
[$s, $b] = $call('GET', '/api/admin/audit', 'owner');
check('owner reads the audit log newest first', $s === 200 && $b['items'][0]['action'] === 'person.create' && $b['items'][0]['actorName'] === 'Test admin' && in_array('email', $b['items'][0]['fields'], true));
check('the audit view carries no personal data of learners', !str_contains(json_encode($b), 'Zara') && !str_contains(json_encode($b), 'zara@'));

// ---- staff accounts ----
check('admin cannot list staff accounts', $call('GET', '/api/admin/users', 'office')[0] === 403);
[$s, $b] = $call('POST', '/api/admin/users', 'owner', ['email' => 'New.Staff@Example.com', 'displayName' => 'New Staff', 'role' => 'admin']);
check('owner adds an office account', $s === 201 && $b['user']['email'] === 'new.staff@example.com' && $b['user']['role'] === 'admin' && $b['user']['linked'] === false);
$newId = $b['user']['id'];
check('a learner, trainer or manager account cannot be created', $call('POST', '/api/admin/users', 'owner', ['email' => 'l@example.com', 'displayName' => 'L', 'role' => 'learner'])[0] === 422);
check('a duplicate email is refused', isset($call('POST', '/api/admin/users', 'owner', ['email' => 'office@example.com', 'displayName' => 'X', 'role' => 'admin'])[1]['error']['fields']['email']));
check('owner promotes the new account', $call('PATCH', "/api/admin/users/$newId", 'owner', ['role' => 'super_admin'])[1]['user']['role'] === 'super_admin');
[$s, $b] = $call('PATCH', "/api/admin/users/$owner", 'owner', ['status' => 'disabled']);
check('nobody changes their own status', $s === 409 && $b['error']['code'] === 'self');
check('an owner may rename themselves', $call('PATCH', "/api/admin/users/$owner", 'owner', ['displayName' => 'Owner'])[0] === 200);
$pdo->exec("UPDATE users SET clerk_user_id = 'clerk_newstaff' WHERE id = '$newId'");
$verifyNew = new Vitalcare\Http\Api(fn (string $t) => ['sub' => 'clerk_newstaff', 'email' => 'new.staff@example.com'], $pdo, '8.4.0', true, null, false, 'info@vitalcare.uk', fn () => $now);
[$s, $b] = $verifyNew->handle('PATCH', "/api/admin/users/$owner", 'Bearer ' . str_repeat('t', 30), json_encode(['role' => 'admin']));
check('one owner may demote another while one remains', $s === 200 && $b['user']['role'] === 'admin', json_encode($b));
[$s, $b] = $call('PATCH', "/api/admin/users/$newId", 'owner', ['role' => 'admin']);
check('the demoted account can no longer manage staff', $s === 403);
[$s, $b] = $verifyNew->handle('PATCH', "/api/admin/users/$newId", 'Bearer ' . str_repeat('t', 30), json_encode(['status' => 'disabled']));
check('the last active owner cannot disable themselves', $s === 409);
$pdo->exec("UPDATE users SET role = 'super_admin' WHERE id = '$owner'");
$pdo->exec("UPDATE users SET status = 'disabled' WHERE id = '$owner'");
[$s, $b] = $verifyNew->handle('PATCH', "/api/admin/users/$newId", 'Bearer ' . str_repeat('t', 30), json_encode(['displayName' => 'Still Owner']));
check('renaming yourself is fine', $s === 200);
$third = make_user($pdo, 'third@example.com', 'super_admin');
$pdo->exec("UPDATE users SET status = 'disabled' WHERE id = '$third'");
[$s, $b] = $verifyNew->handle('PATCH', "/api/admin/users/$owner", 'Bearer ' . str_repeat('t', 30), json_encode(['status' => 'active']));
check('re-enabling a disabled owner is fine', $s === 200 && $b['user']['status'] === 'active');
[$s, $b] = $call('PATCH', "/api/admin/users/$newId", 'owner', ['role' => 'admin']);
check('with two active owners one may demote the other', $s === 200);
[$s, $b] = $call('PATCH', "/api/admin/users/$owner", 'owner', ['role' => 'admin']);
check('an owner cannot demote themselves, so an owner always remains', $s === 409 && $b['error']['code'] === 'self');
$disabledAdmin = make_user($pdo, 'gone@example.com', 'admin', null, null, 'disabled');
check('a disabled account gets no access', $call('GET', '/api/me', 'gone')[1]['error']['code'] === 'no_access');
unset($disabledAdmin, $office);
