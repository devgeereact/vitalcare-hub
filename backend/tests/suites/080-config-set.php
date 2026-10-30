<?php

declare(strict_types=1);

// bin/config-set.php: allowlist, validation, atomic write, backups, and no value is ever printed.
use Vitalcare\Config;

$tmp = dirname(__DIR__, 3) . '/.local-test/tmp/cfgset-' . bin2hex(random_bytes(4));
mkdir("$tmp/config", 0700, true);
$base = ['db' => ['dsn' => 'mysql:host=x;dbname=d', 'user' => 'u', 'password' => 'DB-PASSWORD-VALUE'],
    'office_email' => 'info@vitalcare.uk', 'turnstile' => ['secret' => '', 'dev_bypass' => false],
    'clerk' => ['issuer' => 'https://clerk.vitalcare.uk', 'authorized_parties' => ['https://vitalcare.uk']]];
$file = "$tmp/config/prod.php";
$reset = function () use ($file, $base, $tmp) {
    foreach (glob("$file*") ?: [] as $f) { unlink($f); }
    file_put_contents($file, "<?php\nreturn " . var_export($base, true) . ";\n");
    chmod($file, 0600);
};
$run = function (array|string $input) use ($tmp): array {
    $p = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/config-set.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['VITALCARE_PRIVATE_ROOT' => $tmp, 'PATH' => getenv('PATH') ?: '']);
    fwrite($pipes[0], is_string($input) ? $input : json_encode($input));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
};
$load = fn () => require $file;
$mode = fn (string $f) => substr(sprintf('%o', fileperms($f)), -4);

$reset();
[$rc, $out, $err] = $run(['set' => ['clerk.authorized_parties' => ['https://vitalcare.uk', 'https://local.vitalcare.uk']]]);
check('a valid change exits 0', $rc === 0, $err);
check('the change is applied', $load()['clerk']['authorized_parties'] === ['https://vitalcare.uk', 'https://local.vitalcare.uk']);
check('other values are untouched', $load()['db']['password'] === 'DB-PASSWORD-VALUE' && $load()['office_email'] === 'info@vitalcare.uk');
check('the file stays mode 0600', $mode($file) === '0600');
check('a backup of the previous file is kept (0600)', count(glob("$file.bak-*") ?: []) === 1 && $mode(glob("$file.bak-*")[0]) === '0600');
check('the backup holds the previous value', (require glob("$file.bak-*")[0])['clerk']['authorized_parties'] === ['https://vitalcare.uk']);
check('no temporary file is left behind', !file_exists("$file.new"));
check('output names the key', str_contains($out, 'set clerk.authorized_parties'));

// Secrets: applied, but never echoed anywhere.
$reset();
$secretValue = 'S3cret-Value_abc123XYZ';
[$rc, $out, $err] = $run(['set' => ['turnstile.secret' => $secretValue, 'smtp.password' => 'Mailbox-Pass_9876xyz']]);
check('secrets are applied', $rc === 0 && $load()['turnstile']['secret'] === $secretValue && $load()['smtp']['password'] === 'Mailbox-Pass_9876xyz');
check('secret values are never printed to stdout', !str_contains($out, $secretValue) && !str_contains($out, 'Mailbox-Pass'));
check('secret values are never printed to stderr', !str_contains($err, $secretValue));
check('output says a secret was set without showing it', str_contains($out, 'turnstile.secret (secret, not shown)'));
check('the config still loads through the real Config class (db section intact)', Config::load($tmp)['db']['user'] === 'u');
check('a half-finished smtp section (password only) disables mail sending, not the API', Config::load($tmp)['smtp'] === null);
check('...and the API-facing sections are still fully usable', Config::load($tmp)['clerk']['issuer'] === 'https://clerk.vitalcare.uk' && Config::load($tmp)['office_email'] === 'info@vitalcare.uk');

// Refusals: nothing changes, and the rejected value is not echoed.
$reset(); $before = file_get_contents($file);
$refuse = function (string $label, array|string $input, int $code) use ($run, $file, $before, $reset) {
    [$rc, $out, $err] = $run($input);
    check("$label: refused with exit $code", $rc === $code, "rc=$rc $err");
    check("$label: file unchanged", file_get_contents($file) === $before);
    return $out . $err;
};
$refuse('unknown key', ['set' => ['db.password' => 'overwrite-the-database-password']], 3);
$refuse('a key outside the allowlist', ['set' => ['db.user' => 'evil_user_name_x']], 3);
$refuse('path-like key', ['set' => ['../x' => 'abcdefghij']], 3);
$e = $refuse('invalid email', ['set' => ['office_email' => 'not-an-email']], 3);
check('the rejected value is not echoed back', !str_contains($e, 'not-an-email'));
$refuse('http origin', ['set' => ['clerk.authorized_parties' => ['http://insecure.example']]], 3);
$refuse('origin with a path', ['set' => ['clerk.authorized_parties' => ['https://vitalcare.uk/x']]], 3);
$refuse('empty origin list', ['set' => ['clerk.authorized_parties' => []]], 3);
$refuse('origin list as a string', ['set' => ['clerk.authorized_parties' => 'https://vitalcare.uk']], 3);
$e = $refuse('short secret', ['set' => ['turnstile.secret' => 'short']], 3);
check('the short secret is not echoed back', !str_contains($e, 'short'));
$refuse('secret with a newline', ['set' => ['smtp.password' => "line1\nline2-longer"]], 3);
$refuse('secret with a space', ['set' => ['turnstile.secret' => 'has a space inside it']], 3);
$refuse('port 25', ['set' => ['smtp.port' => 25]], 3);
$refuse('port as a string', ['set' => ['smtp.port' => '465']], 3);
$refuse('secure mode none', ['set' => ['smtp.secure' => 'none']], 3);
$refuse('host with a space', ['set' => ['smtp.host' => 'bad host']], 3);
$refuse('from name with an angle bracket', ['set' => ['smtp.from_name' => 'Evil <x@y.z>']], 3);
$refuse('one bad key among good ones refuses the whole request', ['set' => ['office_email' => 'ok@example.com', 'bogus.key' => 'abcdefghij']], 3);
$refuse('empty set', ['set' => []], 2);
$refuse('not JSON', 'this is not json', 2);
$refuse('wrong shape', ['nothing' => 1], 2);

// Missing or broken config.
unlink($file);
[$rc] = $run(['set' => ['office_email' => 'a@example.com']]);
check('a missing config file is refused (exit 4)', $rc === 4);
file_put_contents($file, "<?php return 'a string';"); chmod($file, 0600);
[$rc] = $run(['set' => ['office_email' => 'a@example.com']]);
check('a config that is not an array is refused (exit 4)', $rc === 4);

// Backups rotate: only the newest 5 are kept.
$reset();
for ($i = 0; $i < 8; $i++) {
    $run(['set' => ['office_email' => "n$i@example.com"]]);
    foreach (glob("$file.bak-*") ?: [] as $b) { if (!str_contains($b, 'KEEP')) { /* names carry a one-second timestamp; force distinct */ } }
    usleep(1_100_000);
}
check('at most 5 backups are kept', count(glob("$file.bak-*") ?: []) <= 5, (string) count(glob("$file.bak-*") ?: []));
check('the last write won', $load()['office_email'] === 'n7@example.com');

// Config::load reads the optional smtp section.
$reset();
check('without smtp the loader reports null', Config::load($tmp)['smtp'] === null);
$run(['set' => ['smtp.host' => 'mail.example.com', 'smtp.port' => 465, 'smtp.secure' => 'ssl', 'smtp.user' => 'info@vitalcare.uk', 'smtp.password' => 'Mailbox-Pass_9876xyz', 'smtp.from_email' => 'info@vitalcare.uk', 'smtp.from_name' => 'Vitalcare Training Hub']]);
$s = Config::load($tmp)['smtp'];
check('a complete smtp section loads', is_array($s) && $s['host'] === 'mail.example.com' && $s['port'] === 465 && $s['secure'] === 'ssl');
$run(['set' => ['smtp.password' => 'Another-Pass_12345x']]);
check('an smtp section with the password present stays valid after a partial change', Config::load($tmp)['smtp']['password'] === 'Another-Pass_12345x');

foreach (glob("$tmp/config/*") ?: [] as $f) { unlink($f); }
rmdir("$tmp/config"); rmdir($tmp);
