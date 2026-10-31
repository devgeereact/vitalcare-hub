<?php

declare(strict_types=1);

// HTTP contract: health, /api/me, and the error shape.
use Vitalcare\Http\Api;
use Vitalcare\Http\HttpError;

/** @var PDO $pdo */
$org = make_org($pdo, 'Test Org');
make_user($pdo, 'trainer@example.com', 'trainer');
make_user($pdo, 'mgr@example.com', 'manager', $org);
make_user($pdo, 'office@example.com', 'admin', $org);
make_user($pdo, 'owner@example.com', 'super_admin');

$tokens = [
    'tok-trainer-aaaaaaaaaaaaaaaaaaaaaaaa' => ['sub' => 'user_t', 'email' => 'trainer@example.com'],
    'tok-mgr-aaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => ['sub' => 'user_m', 'email' => 'mgr@example.com'],
    'tok-stranger-aaaaaaaaaaaaaaaaaaaaaaaa' => ['sub' => 'user_s', 'email' => 'nobody@example.com'],
    'tok-office-aaaaaaaaaaaaaaaaaaaaaaaaaa' => ['sub' => 'user_o', 'email' => 'office@example.com'],
    'tok-owner-aaaaaaaaaaaaaaaaaaaaaaaaaaa' => ['sub' => 'user_w', 'email' => 'owner@example.com'],
];
$verify = function (string $t) use ($tokens): array {
    return $tokens[$t] ?? throw HttpError::unauthorized();
};
$api = new Api($verify, $pdo, '8.4.0');
$call = fn (string $m, string $p, ?string $a = null) => $api->handle($m, $p, $a);
$err = fn (array $res): ?array => $res[1]['error'] ?? null;

// Health is public and honest.
[$s, $b] = $call('GET', '/api/health');
check('health is public and ok', $s === 200 && $b['status'] === 'ok');
check('health reports the PHP version', $b['php'] === '8.4.0');
check('health reports auth configured', $b['authConfigured'] === true);
check('health says "unknown" when no release is recorded', $b['release'] === 'unknown');
[$s, $b] = (new Api($verify, $pdo, '8.4.0', true, release: 'abcdef012345'))->handle('GET', '/api/health', null);
check('health reports the deployed release', $b['release'] === 'abcdef012345');
$rf = sys_get_temp_dir() . '/vc-release-' . getmypid();
file_put_contents($rf, str_repeat('ab', 20) . "\n");
check('Bootstrap::release reads a valid 40-hex id as 12 characters', \Vitalcare\Bootstrap::release($rf) === 'abababababab');
file_put_contents($rf, "not a commit; <script>\n");
check('Bootstrap::release refuses anything that is not a 40-hex id', \Vitalcare\Bootstrap::release($rf) === 'unknown');
unlink($rf);
check('Bootstrap::release is "unknown" when the file is missing', \Vitalcare\Bootstrap::release($rf) === 'unknown');
[$s, $b] = (new Api($verify, null, '8.4.0', false))->handle('GET', '/api/health', null);
check('unconfigured health still answers and says so', $s === 200 && $b['authConfigured'] === false);
check('trailing slash is tolerated', $call('GET', '/api/health/')[0] === 200);
check('health rejects POST', $call('POST', '/api/health')[0] === 405);

// /api/me: 401 without a usable token, 403 for a valid identity with no row, 200 with a row.
check('/me without a token is 401', $call('GET', '/api/me')[0] === 401);
check('/me with a malformed header is 401', $call('GET', '/api/me', 'Basic abcdefghijklmnopqrstuvwxyz')[0] === 401);
check('/me with a too-short bearer is 401', $call('GET', '/api/me', 'Bearer short')[0] === 401);
check('/me with an invalid token is 401', $call('GET', '/api/me', 'Bearer tok-invalid-aaaaaaaaaaaaaaaaaaaaaaaaaa')[0] === 401);
[$s, $b] = $call('GET', '/api/me', 'Bearer tok-stranger-aaaaaaaaaaaaaaaaaaaaaaaa');
check('valid identity with no row is 403 no_access', $s === 403 && ($err([$s, $b])['code'] ?? '') === 'no_access');
// D-018: only admin and super_admin sign in. Other roles get the same 403 no_access as a stranger.
[$s, $b] = $call('GET', '/api/me', 'Bearer tok-trainer-aaaaaaaaaaaaaaaaaaaaaaaa');
check('a trainer row is 403 no_access (no LMS roles sign in)', $s === 403 && ($err([$s, $b])['code'] ?? '') === 'no_access');
[$s, $b] = $call('GET', '/api/me', 'Bearer tok-mgr-aaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
check('a manager row is 403 no_access', $s === 403 && ($err([$s, $b])['code'] ?? '') === 'no_access');
check('the no_access answer is the same for a stranger and a non-CMS role', $call('GET', '/api/me', 'Bearer tok-stranger-aaaaaaaaaaaaaaaaaaaaaaaa')[1] === $b);
[$s, $b] = $call('GET', '/api/me', 'Bearer tok-office-aaaaaaaaaaaaaaaaaaaaaaaaaa');
check('an admin is 200 with their role', $s === 200 && $b['user']['role'] === 'admin');
check('/me returns no secrets or internals', array_keys($b['user']) === ['id', 'email', 'displayName', 'role', 'organisationId']);
check('admin organisation id returned', $b['user']['organisationId'] === $org);
[$s, $b] = $call('GET', '/api/me', 'bearer tok-owner-aaaaaaaaaaaaaaaaaaaaaaaaaaa');
check('the Bearer scheme is case-insensitive (RFC 7235)', $s === 200 && $b['user']['role'] === 'super_admin');
check('/me rejects POST with 405', $call('POST', '/api/me', 'Bearer tok-office-aaaaaaaaaaaaaaaaaaaaaaaaaa')[0] === 405);

// Unconfigured database: protected routes are 503, not a crash and not open.
$closed = new Api($verify, null, '8.4.0', false);
check('protected route with no database is 503', $closed->handle('GET', '/api/me', 'Bearer tok-trainer-aaaaaaaaaaaaaaaaaaaaaaaa')[0] === 503);

// Unknown routes and the error shape.
[$s, $b] = $call('GET', '/api/nope');
check('unknown route is 404 with the standard shape', $s === 404 && array_keys($b['error']) === ['code', 'message']);
check('path traversal style route is 404', $call('GET', '/api/../etc/passwd')[0] === 404);

// Unexpected exceptions never leak internals.
ini_set('error_log', '/dev/null'); // the next call logs on purpose
$boom = new Api(function (string $t): array { throw new RuntimeException('SQLSTATE secret detail /home/x/config.php'); }, $pdo);
[$s, $b] = $boom->handle('GET', '/api/me', 'Bearer tok-trainer-aaaaaaaaaaaaaaaaaaaaaaaa');
check('an unexpected exception is a plain 500', $s === 500 && $b['error']['code'] === 'server_error');
check('the 500 body leaks nothing', !str_contains(json_encode($b), 'SQLSTATE') && !str_contains(json_encode($b), '/home'));
