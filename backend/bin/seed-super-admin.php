<?php

declare(strict_types=1);

// Usage: php backend/bin/seed-super-admin.php owner@example.com "Display Name"
// Creates ONE super_admin row with no Clerk id. It is linked on that person's first sign-in
// by verified email. Refuses if the email already exists. Never creates demo or test users.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../src/Bootstrap.php';

use Vitalcare\{Config, Db, Role, SessionResolver};

$email = SessionResolver::normaliseEmail($argv[1] ?? null);
if ($email === null) {
    fwrite(STDERR, "A valid email address is required.\n");
    exit(2);
}
$name = trim($argv[2] ?? '');
$root = getenv('VITALCARE_PRIVATE_ROOT') ?: dirname(__DIR__, 2) . '/.local-state/private';
$pdo = Db::connect(Config::load($root, getenv('VITALCARE_CONFIG') ?: 'prod')['migrate']);
$exists = $pdo->prepare('SELECT 1 FROM users WHERE email_lower = :e');
$exists->execute([':e' => $email]);
if ($exists->fetchColumn() !== false) {
    fwrite(STDERR, "A user with that email already exists. Nothing changed.\n");
    exit(3);
}
$pdo->prepare('INSERT INTO users (id, email, display_name, role) VALUES (:id, :e, :n, :r)')
    ->execute([':id' => Vitalcare\Db::uuid(), ':e' => $email, ':n' => $name, ':r' => Role::SUPER_ADMIN]);
echo "Created super_admin row for the given email. It links on first sign-in.\n";
