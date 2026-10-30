<?php

declare(strict_types=1);

// Shared test harness. Each suite runs in its OWN PHP process on a FRESH database (run.php, suite.php).
// Env: TEST_DB_HOST/PORT/NAME/USER/PASS. The database is dropped and recreated, so it must be local
// and its name must start with "vitalcare_test".
require_once __DIR__ . '/../src/Autoload.php';

use Vitalcare\{HttpError, Migrator};

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
    $pdo = Vitalcare\Db::connect(['dsn' => "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", 'user' => $user, 'password' => $pass]);
    (new Migrator($pdo, __DIR__ . '/../migrations'))->run();
    return $pdo;
}

/** Inserts a user row and returns its id. */
function make_user(PDO $pdo, string $email, string $role, ?string $org = null, ?string $clerk = null, string $status = 'active'): string
{
    $id = Vitalcare\Db::uuid();
    $pdo->prepare('INSERT INTO users (id, email, display_name, role, organisation_id, clerk_user_id, status) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id, $email, 'Test ' . $role, $role, $org, $clerk, $status]);
    return $id;
}

function make_org(PDO $pdo, string $name): string
{
    $id = Vitalcare\Db::uuid();
    $pdo->prepare('INSERT INTO organisations (id, name) VALUES (?,?)')->execute([$id, $name]);
    return $id;
}
