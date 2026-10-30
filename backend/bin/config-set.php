<?php

declare(strict_types=1);

// Changes allowlisted values in the private config without ever printing them.
//
//   echo '{"set":{"clerk.authorized_parties":["https://vitalcare.uk"]}}' | php config-set.php
//
// - Values arrive on STDIN as JSON, never on the command line (so they are not in the process list).
// - Only the keys below are accepted, each with its own validation. Anything else is refused.
// - The previous file is kept as prod.php.bak-<UTC> (mode 0600, newest 5 kept); the new file is written
//   to a temporary name with mode 0600, loaded back and compared, then renamed into place atomically.
// - Output names the keys that changed; secret values are never echoed, not even on error.
// Environment: VITALCARE_PRIVATE_ROOT (default $HOME/vitalcare-private), VITALCARE_CONFIG (default prod).
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = rtrim(getenv('VITALCARE_PRIVATE_ROOT') ?: (getenv('HOME') ?: '') . '/vitalcare-private', '/');
$name = getenv('VITALCARE_CONFIG') ?: 'prod';
if (preg_match('/^[a-z0-9_-]+$/', $name) !== 1) {
    fwrite(STDERR, "Invalid configuration name.\n");
    exit(2);
}
$file = "$root/config/$name.php";

$email = static fn (mixed $v): bool => is_string($v) && strlen($v) <= 254 && filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
$secret = static fn (mixed $v): bool => is_string($v) && strlen($v) >= 8 && strlen($v) <= 200 && preg_match('/^[\x21-\x7e]+$/', $v) === 1;

/** key => [validator, is secret] */
$allowed = [
    'office_email' => [$email, false],
    'clerk.authorized_parties' => [static fn (mixed $v): bool => is_array($v) && $v !== [] && array_is_list($v) && count($v) <= 10 && array_reduce($v, static fn (bool $c, mixed $o): bool => $c && is_string($o) && preg_match('#^https://[a-z0-9.-]+(:\d{2,5})?$#', $o) === 1, true), false],
    'turnstile.secret' => [$secret, true],
    'smtp.host' => [static fn (mixed $v): bool => is_string($v) && preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/i', $v) === 1, false],
    'smtp.port' => [static fn (mixed $v): bool => $v === 465 || $v === 587, false],
    'smtp.secure' => [static fn (mixed $v): bool => $v === 'ssl' || $v === 'tls', false],
    'smtp.user' => [$email, false],
    'smtp.password' => [$secret, true],
    'smtp.from_email' => [$email, false],
    'smtp.from_name' => [static fn (mixed $v): bool => is_string($v) && $v !== '' && mb_strlen($v) <= 80 && preg_match('/[\x00-\x1f\x7f"<>]/', $v) !== 1, false],
];

$in = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($in) || !isset($in['set']) || !is_array($in['set']) || $in['set'] === []) {
    fwrite(STDERR, "Expected JSON on stdin: {\"set\": {\"key\": value}}\n");
    exit(2);
}
foreach ($in['set'] as $key => $value) {
    if (!is_string($key) || !isset($allowed[$key])) {
        fwrite(STDERR, 'Refused: key not allowed: ' . (is_string($key) ? $key : '(non-string)') . "\n");
        exit(3);
    }
    if (($allowed[$key][0])($value) !== true) {
        fwrite(STDERR, "Refused: the value for $key is not valid.\n");   // never echo the value
        exit(3);
    }
}

if (!is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "Configuration file is missing or unreadable.\n");
    exit(4);
}
$config = require $file;
if (!is_array($config)) {
    fwrite(STDERR, "Configuration file does not return an array.\n");
    exit(4);
}
foreach ($in['set'] as $key => $value) {
    $parts = explode('.', $key);
    $ref = &$config;
    foreach ($parts as $i => $p) {
        if ($i === count($parts) - 1) {
            $ref[$p] = $value;
        } else {
            if (!isset($ref[$p]) || !is_array($ref[$p])) {
                $ref[$p] = [];
            }
            $ref = &$ref[$p];
        }
    }
    unset($ref);
}

$php = "<?php\n// Private runtime configuration. Outside every webroot, mode 0600. Never commit or print.\n"
    . "// Edited by bin/config-set.php; comments are not preserved.\nreturn " . var_export($config, true) . ";\n";

umask(0077);
$tmp = "$file.new";
if (file_put_contents($tmp, $php, LOCK_EX) === false) {
    fwrite(STDERR, "Could not write the new configuration.\n");
    exit(5);
}
chmod($tmp, 0600);
if ((static function (string $f) { return require $f; })($tmp) !== $config) {
    unlink($tmp);
    fwrite(STDERR, "The new file did not load back identically; nothing changed.\n");
    exit(5);
}
$backup = "$file.bak-" . gmdate('Ymd-His');
copy($file, $backup);
chmod($backup, 0600);
rename($tmp, $file);

$old = glob("$file.bak-*") ?: [];
sort($old);
foreach (array_slice($old, 0, max(0, count($old) - 5)) as $b) {
    unlink($b);
}

foreach (array_keys($in['set']) as $key) {
    echo "set $key", $allowed[$key][1] ? ' (secret, not shown)' : '', "\n";
}
