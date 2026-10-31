<?php

declare(strict_types=1);

// bin/seed-demo.php: the refusal guard, then a real run on this suite's throwaway database, and the
// development-only loopback origin rule in Config.
use Vitalcare\Certificates\CertificateState;
use Vitalcare\Cms\LondonTime;
use Vitalcare\Support\Config;
use Vitalcare\Support\DemoSeed;

/** @var PDO $pdo */

// ---- the guard (pure) ----
$r = fn (string $h, ?int $port, string $db, bool $empty, bool $reset) => DemoSeed::refusal($h, $port, $db, $empty, $reset);
check('guard: an empty local demo database is allowed', $r('127.0.0.1', 33175, 'vitalcare_demo', true, false) === null);
check('guard: "test" and "local" names are allowed', $r('localhost', null, 'vitalcare_test', true, false) === null && $r('127.0.0.1', 33175, 'my_local_db', true, false) === null);
check('guard: a remote host is refused', $r('db.example.com', 3306, 'vitalcare_demo', true, false) !== null);
check('guard: a private-network host is refused', $r('192.168.1.10', 3306, 'vitalcare_demo', true, false) !== null);
check('guard: port 3308 is always refused', $r('127.0.0.1', 3308, 'vitalcare_demo', true, false) !== null);
check('guard: a name without demo/test/local is refused', $r('127.0.0.1', 33175, 'vitalcare', true, false) !== null);
check('guard: a production-looking name is refused even with --reset', $r('127.0.0.1', 33175, 'acct_vch', false, true) !== null);
check('guard: a demo database with data is refused without --reset', str_contains((string) $r('127.0.0.1', 33175, 'vitalcare_demo', false, false), '--reset'));
check('guard: a demo database with data is allowed with --reset', $r('127.0.0.1', 33175, 'vitalcare_demo', false, true) === null);
check('dsn host and port are read', DemoSeed::dsnHost('mysql:host=127.0.0.1;port=33175;dbname=x;charset=utf8mb4') === ['host' => '127.0.0.1', 'port' => 33175]);
check('dsn without a port', DemoSeed::dsnHost('mysql:host=localhost;dbname=x') === ['host' => 'localhost', 'port' => null]);
check('dbname=host trick does not fool the host parser', DemoSeed::dsnHost('mysql:dbname=host=127.0.0.1;host=db.example.com')['host'] === 'db.example.com');

// ---- the script, on this suite's database ----
global $host, $port, $name, $user, $pass;
$tmp = dirname(__DIR__, 3) . '/.local-test/tmp/seed-' . bin2hex(random_bytes(4));
mkdir("$tmp/config", 0700, true);
$writeConfig = function (string $dsn) use ($tmp, $user, $pass) {
    $cfg = ['db' => ['dsn' => $dsn, 'user' => $user, 'password' => $pass],
        'clerk' => ['issuer' => 'https://example-1234.clerk.accounts.dev', 'authorized_parties' => ['http://127.0.0.1:5175']]];
    file_put_contents("$tmp/config/demo.php", "<?php\nreturn " . var_export($cfg, true) . ";\n");
};
$run = function (array $args = []) use ($tmp): array {
    $p = proc_open(array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/seed-demo.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        ['VITALCARE_PRIVATE_ROOT' => $tmp, 'VITALCARE_CONFIG' => 'demo', 'PATH' => getenv('PATH') ?: '', 'TMPDIR' => getenv('TMPDIR') ?: '/tmp']);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out, $err];
};
$count = fn (string $t) => (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();

$writeConfig('mysql:host=192.0.2.1;port=3306;dbname=vitalcare_demo;charset=utf8mb4');
[$rc, , $err] = $run();
check('script: a remote host is refused before connecting', $rc === 3 && str_contains($err, 'this machine'), "rc=$rc $err");

$writeConfig("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4");
[$rc, $out, $err] = $run();
check('script: seeds an empty local test database', $rc === 0, "rc=$rc $err");
check('script: says what it made and never prints a password', str_contains($out, '25 people') && !str_contains($out . $err, $pass));
$people = $count('people');
$certs = $count('certificates');
check('3 organisations, 25 people, 6 courses, 2 staff', $count('organisations') === 3 && $people === 25 && $count('courses') === 6 && $count('users') === 2);

[$rc, , $err] = $run();
check('script: a second run without --reset is refused', $rc === 3 && str_contains($err, '--reset'), "rc=$rc $err");
check('script: ...and changes nothing', $count('people') === $people && $count('certificates') === $certs);

[$rc, , $err] = $run(['--reset']);
check('script: --reset wipes and seeds again (no duplicates)', $rc === 0 && $count('people') === 25 && $count('certificates') === $certs && $count('users') === 2, "rc=$rc $err");
check('migrations are kept by --reset', $count('schema_migrations') >= 10);

// ---- what is in it ----
$roles = $pdo->query('SELECT email, role, clerk_user_id FROM users ORDER BY role')->fetchAll(PDO::FETCH_ASSOC);
check('staff: owner is super_admin and office is admin, both unlinked until first sign-in',
    $roles[0]['email'] === 'office+clerk_test@example.com' && $roles[0]['role'] === 'admin'
    && $roles[1]['email'] === 'owner+clerk_test@example.com' && $roles[1]['role'] === 'super_admin'
    && $roles[0]['clerk_user_id'] === null && $roles[1]['clerk_user_id'] === null);
$names = $pdo->query("SELECT CONCAT(first_name, ' ', last_name) FROM people")->fetchAll(PDO::FETCH_COLUMN);
check('unicode names survive the round trip', in_array('Łukasz Kowalski', $names, true) && in_array('Ọlá Adébáyọ̀', $names, true) && in_array('Thị Lan Nguyễn', $names, true));
check('some people belong to an organisation and some do not', $count('people') > (int) $pdo->query('SELECT COUNT(*) FROM people WHERE organisation_id IS NOT NULL')->fetchColumn()
    && (int) $pdo->query('SELECT COUNT(*) FROM people WHERE organisation_id IS NOT NULL')->fetchColumn() > 10);
check('5 published courses and 1 draft', (int) $pdo->query('SELECT SUM(published) FROM courses')->fetchColumn() === 5);
check('every course says DEMO and claims no accreditation', (int) $pdo->query("SELECT COUNT(*) FROM courses WHERE summary LIKE 'DEMO%' AND accreditation_note LIKE '%No accreditation is claimed%'")->fetchColumn() === 6);
$today = LondonTime::today(new DateTimeImmutable('now', new DateTimeZone('UTC')));
$states = [];
foreach ($pdo->query('SELECT status, revoked_at, expires_on, issue_reason FROM certificates')->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $s = CertificateState::of($c['status'], $c['revoked_at'], $c['expires_on'], $today);
    $states[$s] = ($states[$s] ?? 0) + 1;
}
check('certificates: valid, expired and revoked all present', ($states['valid'] ?? 0) > 0 && ($states['expired'] ?? 0) > 0 && ($states['revoked'] ?? 0) === 1);
foreach ([20, 45, 80] as $d) {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM certificates WHERE status = 'approved' AND expires_on = '" . LondonTime::addDays($today, $d) . "'")->fetchColumn();
    check("certificates: at least one due in $d days", $n > 0);
}
check('certificates: one issued by hand, with a reason', (int) $pdo->query('SELECT COUNT(*) FROM certificates WHERE attendee_id IS NULL AND issue_reason IS NOT NULL')->fetchColumn() === 1);
check('certificates: one with no expiry date', (int) $pdo->query("SELECT COUNT(*) FROM certificates WHERE status = 'approved' AND expires_on IS NULL")->fetchColumn() > 0);
$fu = $pdo->query("SELECT SUM(done_at IS NULL AND due_on < '$today') o, SUM(done_at IS NULL AND due_on = '$today') t, SUM(done_at IS NULL AND due_on > '$today') f, SUM(done_at IS NOT NULL) d FROM follow_ups")->fetch(PDO::FETCH_ASSOC);
check('follow-ups: overdue, due today, future and done', $fu['o'] > 0 && $fu['t'] > 0 && $fu['f'] > 0 && $fu['d'] > 0);
$es = $pdo->query('SELECT DISTINCT status FROM enquiries')->fetchAll(PDO::FETCH_COLUMN);
sort($es);
check('enquiries: new, in progress and closed', $es === ['closed', 'in_progress', 'new']);
check('sessions: past and upcoming', (int) $pdo->query('SELECT COUNT(*) FROM training_sessions WHERE starts_at > UTC_TIMESTAMP()')->fetchColumn() >= 3
    && (int) $pdo->query('SELECT COUNT(*) FROM training_sessions WHERE starts_at < UTC_TIMESTAMP()')->fetchColumn() >= 5);
check('attendance includes a no-show and a fail', (int) $pdo->query("SELECT COUNT(*) FROM session_attendees WHERE attendance = 'no_show' OR result = 'fail'")->fetchColumn() >= 2);
check('notes and audit entries exist', $count('notes') >= 4 && $count('audit_log') >= 10);
check('no email is queued', $count('outbox_email') === 0);
$emails = $pdo->query('SELECT email FROM people WHERE email IS NOT NULL UNION SELECT contact_email FROM organisations UNION SELECT email FROM enquiries')->fetchAll(PDO::FETCH_COLUMN);
check('every demo email address is on example.org', $emails !== [] && array_filter($emails, fn ($e) => !str_ends_with((string) $e, '@example.org')) === []);

// ---- Config: http loopback origins only for a Clerk development issuer ----
$cfg = function (string $issuer, array $parties) use ($tmp): bool {
    file_put_contents("$tmp/config/cfgtest.php", "<?php\nreturn " . var_export(['db' => ['dsn' => 'mysql:host=x', 'user' => 'u', 'password' => 'p'],
        'clerk' => ['issuer' => $issuer, 'authorized_parties' => $parties]], true) . ";\n");
    try {
        Config::load($tmp, 'cfgtest');
        return true;
    } catch (RuntimeException) {
        return false;
    }
};
check('config: a dev issuer may allow http://127.0.0.1:5175', $cfg('https://curious-vulture-4542.clerk.accounts.dev', ['http://127.0.0.1:5175', 'http://localhost:5175']));
check('config: a production issuer may not', !$cfg('https://clerk.vitalcare.uk', ['http://127.0.0.1:5175']));
check('config: a dev issuer still refuses a non-loopback http origin', !$cfg('https://curious-vulture-4542.clerk.accounts.dev', ['http://evil.example']));
check('config: a dev issuer refuses loopback with a path', !$cfg('https://curious-vulture-4542.clerk.accounts.dev', ['http://127.0.0.1:5175/']));
check('config: a look-alike dev issuer is not a dev issuer', !$cfg('https://x.clerk.accounts.dev.evil.example', ['http://127.0.0.1:5175']));
check('config: production https origins still work', $cfg('https://clerk.vitalcare.uk', ['https://vitalcare.uk']));
