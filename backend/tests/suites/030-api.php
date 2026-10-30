<?php

declare(strict_types=1);

// HTTP contract: health, /api/me, and the error shape.
use Vitalcare\{Api, HttpError};

/** @var PDO $pdo */
$org = make_org($pdo, 'Test Org');
make_user($pdo, 'trainer@example.com', 'trainer');
make_user($pdo, 'mgr@example.com', 'manager', $org);

$tokens = [
    'tok-trainer-aaaaaaaaaaaaaaaaaaaaaaaa' => ['sub' => 'user_t', 'email' => 'trainer@example.com'],
    'tok-mgr-aaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => ['sub' => 'user_m', 'email' => 'mgr@example.com'],
    'tok-stranger-aaaaaaaaaaaaaaaaaaaaaaaa' => ['sub' => 'user_s', 'email' => 'nobody@example.com'],
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
[$s, $b] = $call('GET', '/api/me', 'Bearer tok-trainer-aaaaaaaaaaaaaaaaaaaaaaaa');
check('known user is 200 with their role', $s === 200 && $b['user']['role'] === 'trainer');
check('/me returns no secrets or internals', array_keys($b['user']) === ['id', 'email', 'displayName', 'role', 'organisationId']);
[$s, $b] = $call('GET', '/api/me', 'bearer tok-mgr-aaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
check('the Bearer scheme is case-insensitive (RFC 7235)', $s === 200 && $b['user']['role'] === 'manager');
[$s, $b] = $call('GET', '/api/me', 'Bearer tok-mgr-aaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
check('manager organisation id returned', $s === 200 && $b['user']['organisationId'] === $org);
check('/me rejects POST with 405', $call('POST', '/api/me', 'Bearer tok-trainer-aaaaaaaaaaaaaaaaaaaaaaaa')[0] === 405);

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
