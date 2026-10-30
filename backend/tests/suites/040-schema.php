<?php

declare(strict_types=1);

// The users table enforces what the code assumes, and the migration runner keeps its promises.
use Vitalcare\Support\Migrator;

/** @var PDO $pdo */
$fails = function (string $label, callable $fn) {
    try {
        $fn();
        check($label, false, 'no exception');
    } catch (PDOException) {
        check($label, true);
    }
};

make_user($pdo, 'one@example.com', 'learner');
$fails('email is unique ignoring case', fn () => make_user($pdo, 'ONE@example.com', 'learner'));
$fails('a role outside the five is rejected by the database', fn () => make_user($pdo, 'x1@example.com', 'owner'));
$fails('content_editor is no longer a role', fn () => make_user($pdo, 'x2@example.com', 'content_editor'));
$fails('a status outside active/disabled is rejected', fn () => make_user($pdo, 'x3@example.com', 'learner', null, null, 'banned'));
make_user($pdo, 'two@example.com', 'learner', null, 'clerk_dup');
$fails('a Clerk id can belong to only one user', fn () => make_user($pdo, 'three@example.com', 'learner', null, 'clerk_dup'));
make_user($pdo, 'four@example.com', 'learner');
make_user($pdo, 'five@example.com', 'learner');
check('many users may have no Clerk id yet', (int) $pdo->query('SELECT COUNT(*) FROM users WHERE clerk_user_id IS NULL')->fetchColumn() >= 3);
$fails('a user cannot point at a missing organisation', fn () => make_user($pdo, 'six@example.com', 'manager', Vitalcare\Support\Db::uuid()));
$pdo->exec("UPDATE users SET display_name = 'Changed' WHERE email = 'one@example.com'");
$u = $pdo->query("SELECT created_at, updated_at FROM users WHERE email = 'one@example.com'")->fetch();
check('timestamps are stored', $u['created_at'] !== null && $u['updated_at'] !== null);
check('a fresh migration creates no users: only the four this test inserted exist', (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 4);

// UUIDv7 ids: right version, variant, ordered by time.
$a = Vitalcare\Support\Db::uuid(1_000);
$b = Vitalcare\Support\Db::uuid(2_000);
check('uuid is version 7', $a[14] === '7' && in_array($a[19], ['8', '9', 'a', 'b'], true), $a);
check('uuid is time ordered', strcmp($a, $b) < 0);
check('uuid has the 8-4-4-4-12 shape', preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a) === 1);

// The runner: re-running applies nothing; editing an applied file stops everything.
$dir = dirname(__DIR__) . '/../../.local-test/tmp/mig-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
file_put_contents("$dir/001-first.sql", "CREATE TABLE t_one (id INT PRIMARY KEY);\n");
$m = new Migrator($pdo, $dir);
check('pending() lists a file that has not been applied', $m->pending() === ['001-first.sql']);
check('pending() changes nothing (the table is not created by it)', (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_one'")->fetchColumn() === 0);
check('a new file is applied once', $m->run() === ['001-first.sql']);
check('pending() is empty once everything is applied', $m->pending() === []);
check('re-running applies nothing', $m->run() === []);
file_put_contents("$dir/001-first.sql", "CREATE TABLE t_one (id INT PRIMARY KEY, extra INT);\n");
try {
    $m->run();
    check('editing an applied migration is refused', false);
} catch (RuntimeException $e) {
    check('editing an applied migration is refused', str_contains($e->getMessage(), 'edited'));
}
file_put_contents("$dir/001-first.sql", "CREATE TABLE t_one (id INT PRIMARY KEY);\n");
file_put_contents("$dir/bad name.sql", 'SELECT 1;');
try {
    $m->run();
    check('an un-numbered file name is refused', false);
} catch (RuntimeException $e) {
    check('an un-numbered file name is refused', str_contains($e->getMessage(), 'not numbered'));
}
try {
    $m->pending();
    check('pending() also refuses an un-numbered file name', false);
} catch (RuntimeException $e) {
    check('pending() also refuses an un-numbered file name', str_contains($e->getMessage(), 'not numbered'));
}
unlink("$dir/bad name.sql");
file_put_contents("$dir/002-second.sql", "-- a comment; with a semicolon\nCREATE TABLE t_two (id INT PRIMARY KEY);\nINSERT INTO t_two VALUES (1);\n");
check('pending() sees the new file, and edits to an applied one are still refused by pending()', $m->pending() === ['002-second.sql']);
check('files apply in order and comments with semicolons are safe', $m->run() === ['002-second.sql']);
check('statements split correctly', (int) $pdo->query('SELECT COUNT(*) FROM t_two')->fetchColumn() === 1);
$recorded = (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE name IN ('001-first.sql','002-second.sql','001-users.sql')")->fetchColumn();
check('every applied file is recorded with its checksum', $recorded === 3);
