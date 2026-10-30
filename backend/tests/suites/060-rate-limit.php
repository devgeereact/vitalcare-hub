<?php

declare(strict_types=1);

use Vitalcare\Http\HttpError;
use Vitalcare\Security\RateLimiter;

/** @var PDO $pdo */
$t = 1_900_000_000;
$clock = function () use (&$t): int { return $t; };
$rl = new RateLimiter($pdo, $clock);

for ($i = 1; $i <= 3; $i++) {
    $rl->hit('x', 'a', 3, 60);
}
check('hits up to the limit pass', true);
expectError('the next hit is 429', fn () => $rl->hit('x', 'a', 3, 60), 429, 'rate_limited');
try {
    $rl->hit('x', 'a', 3, 60);
} catch (HttpError $e) {
    check('429 says when to retry', (int) ($e->headers['Retry-After'] ?? 0) >= 1 && (int) $e->headers['Retry-After'] <= 60);
    check('429 message is plain and gives no internals', !preg_match('/bucket|window|sql|hits/i', $e->getMessage()));
}

$rl->hit('x', 'b', 3, 60);
check('a different caller has its own count', true);
$rl->hit('y', 'a', 3, 60);
check('a different bucket name has its own count', true);

$t += 60;
$rl->hit('x', 'a', 3, 60);
check('a new window resets the count', true);

// Boundary: the window start is aligned, so Retry-After never exceeds the window.
$t = 1_900_000_100;
for ($i = 0; $i < 2; $i++) { $rl->hit('edge', 'a', 2, 100); }
try { $rl->hit('edge', 'a', 2, 100); check('edge: third hit refused', false); } catch (HttpError $e) { check('edge: Retry-After is at most the window', (int) $e->headers['Retry-After'] <= 100); }

// Privacy and housekeeping.
$buckets = $pdo->query('SELECT bucket FROM rate_limits')->fetchAll(PDO::FETCH_COLUMN);
check('buckets are 64-character hashes', $buckets !== [] && array_reduce($buckets, fn ($c, $b) => $c && preg_match('/^[0-9a-f]{64}$/', $b) === 1, true));
check('no raw identity is stored', !str_contains(implode('', $buckets), 'a'.'|'));
$before = (int) $pdo->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn();
$t += 90_000;
$purged = $rl->purge(86_400);
check('purge deletes old windows', $purged === $before && (int) $pdo->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn() === 0);
check('purge on an empty table is safe', $rl->purge() === 0);

// A different limit on the same key in the same window uses the caller-supplied limit each time.
$t += 100;
$rl->hit('lim', 'a', 1, 60);
expectError('limit 1 refuses the second hit', fn () => $rl->hit('lim', 'a', 1, 60), 429);
$rl->hit('lim', 'a', 5, 60);
check('the same counter honours a higher limit when asked', true);
