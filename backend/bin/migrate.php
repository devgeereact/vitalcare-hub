<?php

declare(strict_types=1);

// Usage: VITALCARE_PRIVATE_ROOT=... php backend/bin/migrate.php
// Uses the migration database user from the private config. CLI only.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../src/Bootstrap.php';

use Vitalcare\Support\Config;
use Vitalcare\Support\Db;
use Vitalcare\Support\Migrator;

$root = getenv('VITALCARE_PRIVATE_ROOT') ?: dirname(__DIR__, 2) . '/.local-state/private';
$c = Config::load($root, getenv('VITALCARE_CONFIG') ?: 'prod');
$applied = (new Migrator(Db::connect($c['migrate']), __DIR__ . '/../migrations'))->run();
echo $applied === [] ? "Nothing to apply.\n" : 'Applied: ' . implode(', ', $applied) . "\n";
