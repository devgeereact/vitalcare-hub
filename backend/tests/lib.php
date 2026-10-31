<?php

declare(strict_types=1);

// Shared test harness. Each suite runs in its OWN PHP process on a FRESH database (run.php, suite.php).
// Env: TEST_DB_HOST/PORT/NAME/USER/PASS. The database is dropped and recreated, so it must be local
// and its name must start with "vitalcare_test".
require_once __DIR__ . '/../src/Autoload.php';

use Vitalcare\Http\HttpError;
use Vitalcare\Support\Migrator;

$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('TEST_DB_PORT') ?: '33175';
$name = getenv('TEST_DB_NAME') ?: 'vitalcare_test';
$user = getenv('TEST_DB_USER') ?: 'vc_test';
$pass = getenv('TEST_DB_PASS') ?: 'vc_test_local_only';
if (!str_starts_with($name, 'vitalcare_test') || !in_array($host, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Refusing to run: the database must be local and named vitalcare_test*\n");
    exit(2);
}

$pass_n = 0;
$failures = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass_n, $failures;
    if ($ok) {
        $pass_n++;
        return;
    }
    $failures[] = $label . ($detail !== '' ? " [$detail]" : '');
    echo "FAIL: $label $detail\n";
}

function expectError(string $label, callable $fn, int $status, ?string $code = null): void
{
    try {
        $fn();
        check($label, false, 'no error thrown');
    } catch (HttpError $e) {
        check($label, $e->status === $status && ($code === null || $e->errorCode === $code), "got {$e->status} {$e->errorCode}");
    }
}

/** Fresh database with every migration applied by the real runner. */
function fresh_db(): PDO
{
    global $host, $port, $name, $user, $pass;
    $raw = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $raw->exec("DROP DATABASE IF EXISTS `$name`");
    $raw->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
    $pdo = Vitalcare\Support\Db::connect(['dsn' => "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", 'user' => $user, 'password' => $pass]);
    (new Migrator($pdo, __DIR__ . '/../migrations'))->run();
    return $pdo;
}

/** Inserts a user row and returns its id. */
function make_user(PDO $pdo, string $email, string $role, ?string $org = null, ?string $clerk = null, string $status = 'active'): string
{
    $id = Vitalcare\Support\Db::uuid();
    $pdo->prepare('INSERT INTO users (id, email, display_name, role, organisation_id, clerk_user_id, status) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id, $email, 'Test ' . $role, $role, $org, $clerk, $status]);
    return $id;
}

function make_org(PDO $pdo, string $name): string
{
    $id = Vitalcare\Support\Db::uuid();
    $pdo->prepare('INSERT INTO organisations (id, name) VALUES (?,?)')->execute([$id, $name]);
    return $id;
}

/**
 * CMS test client. A caller is named by the local part of their email: cms_call('GET', '/api/admin/people', 'owner')
 * signs in as owner@example.com with Clerk id clerk_owner. null means no token at all.
 *
 * @param (\Closure():\DateTimeImmutable)|null $clock
 */
function cms_api(PDO $pdo, ?Closure $clock = null): Closure
{
    $verify = function (string $t): array {
        if (preg_match('/^tok-([a-z0-9]+)-x{24}$/', $t, $m) === 1) {
            return ['sub' => 'clerk_' . $m[1], 'email' => $m[1] . '@example.com'];
        }
        throw HttpError::unauthorized();
    };
    $api = new Vitalcare\Http\Api($verify, $pdo, '8.4.0', true, null, false, 'info@vitalcare.uk', $clock);
    return function (string $method, string $path, ?string $who = null, mixed $body = null, array $query = [], string $ip = '203.0.113.1') use ($api): array {
        $auth = $who === null ? null : 'Bearer tok-' . $who . '-' . str_repeat('x', 24);
        $raw = $body === null ? null : (is_string($body) ? $body : json_encode($body));
        return array_pad($api->handle($method, $path, $auth, $raw, $ip, $query), 3, []);
    };
}

/** A minimal published course; returns its id. */
function make_course(PDO $pdo, array $o = []): string
{
    $id = Vitalcare\Support\Db::uuid();
    $pdo->prepare('INSERT INTO courses (id, slug, title, summary, renewal_months, hours, published, published_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$id, $o['slug'] ?? 'course-' . substr($id, -8), $o['title'] ?? 'Basic Life Support', $o['summary'] ?? 'What you learn.',
            array_key_exists('renewal', $o) ? $o['renewal'] : 12, $o['hours'] ?? 3.5, $o['published'] ?? 1, ($o['published'] ?? 1) ? '2026-10-01 09:00:00' : null]);
    return $id;
}

function make_person(PDO $pdo, array $o = []): string
{
    $id = Vitalcare\Support\Db::uuid();
    $pdo->prepare('INSERT INTO people (id, first_name, last_name, email, organisation_id, reminders_opt_out, status) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id, $o['first'] ?? 'Pat', $o['last'] ?? 'Jones', array_key_exists('email', $o) ? $o['email'] : 'pat@example.org',
            $o['org'] ?? null, $o['optOut'] ?? 0, $o['status'] ?? 'active']);
    return $id;
}

/** A session (UTC times) and an attendee on it; returns [sessionId, attendeeId]. */
function make_attendance(PDO $pdo, string $course, string $person, string $startsUtc = '2026-10-05 09:00:00', string $attendance = 'attended', ?string $result = 'pass'): array
{
    $sid = Vitalcare\Support\Db::uuid();
    $pdo->prepare("INSERT INTO training_sessions (id, course_id, starts_at, ends_at, venue) VALUES (?,?,?, DATE_ADD(?, INTERVAL 3 HOUR), 'Room 1')")
        ->execute([$sid, $course, $startsUtc, $startsUtc]);
    $aid = Vitalcare\Support\Db::uuid();
    $pdo->prepare('INSERT INTO session_attendees (id, session_id, person_id, attendance, result) VALUES (?,?,?,?,?)')
        ->execute([$aid, $sid, $person, $attendance, $result]);
    return [$sid, $aid];
}
