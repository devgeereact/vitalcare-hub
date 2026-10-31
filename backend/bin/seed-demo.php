<?php

declare(strict_types=1);

// LOCAL DEMO DATA ONLY. Never run this against a real database.
// Usage: VITALCARE_PRIVATE_ROOT=... VITALCARE_CONFIG=demo php backend/bin/seed-demo.php [--reset]
// Fills an EMPTY local database with invented care homes, people, courses, sessions, certificates, notes,
// follow-ups and enquiries (Vitalcare\Support\DemoSeed). Refuses unless the database is on 127.0.0.1 or
// localhost, its name contains "demo", "test" or "local", all migrations are applied, and it is empty.
// --reset first deletes every row in that demo database (the schema is kept), then seeds again.
// Uses the migration database user from the private config. Sends no email: nothing is queued.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../src/Bootstrap.php';

use Vitalcare\Support\Config;
use Vitalcare\Support\Db;
use Vitalcare\Support\DemoSeed;
use Vitalcare\Support\Migrator;

$reset = in_array('--reset', $argv, true);
$root = getenv('VITALCARE_PRIVATE_ROOT') ?: dirname(__DIR__, 2) . '/.local-state/private';
$name = getenv('VITALCARE_CONFIG') ?: 'prod';
try {
    $c = Config::load($root, $name)['migrate'];
    $where = DemoSeed::dsnHost($c['dsn']);
    // Check the host before connecting anywhere.
    $early = DemoSeed::refusal($where['host'], $where['port'], 'demo', true, false);
    if ($early !== null) {
        fwrite(STDERR, $early . "\n");
        exit(3);
    }
    $pdo = Db::connect($c);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Could not load the configuration or connect: ' . $e->getMessage() . "\n");
    exit(2);
}
$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ((new Migrator($pdo, __DIR__ . '/../migrations'))->pending() !== []) {
    fwrite(STDERR, "Refusing: migrations are pending. Run bin/migrate.php first.\n");
    exit(3);
}
$why = DemoSeed::refusal($where['host'], $where['port'], $database, DemoSeed::isEmpty($pdo), $reset);
if ($why !== null) {
    fwrite(STDERR, $why . " Nothing changed.\n");
    exit(3);
}
if ($reset) {
    DemoSeed::wipe($pdo);
}
$made = (new DemoSeed($pdo, new DateTimeImmutable('now', new DateTimeZone('UTC'))))->run();
$parts = [];
foreach ($made as $k => $n) {
    $parts[] = "$n $k";
}
echo 'Demo data seeded into ' . $database . ': ' . implode(', ', $parts) . ".\n";
echo 'Staff sign-in (Clerk development instance, test code 424242): ' . DemoSeed::OWNER_EMAIL . ' (super_admin), ' . DemoSeed::OFFICE_EMAIL . " (admin).\n";
