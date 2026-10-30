<?php

declare(strict_types=1);

namespace Vitalcare\Security;

use Vitalcare\Http\HttpError;

/**
 * Fixed-window counters in the database. The caller is stored only as a SHA-256 of (name, identity),
 * never as a raw IP address. Fails closed on a database error: the exception propagates and the
 * request is refused by the Api's catch-all.
 */
final class RateLimiter
{
    /** @param (\Closure():int)|null $clock unix seconds; injected by tests */
    public function __construct(private readonly \PDO $pdo, private readonly ?\Closure $clock = null)
    {
    }

    /** @throws HttpError 429 once `$limit` hits are exceeded inside the current window */
    public function hit(string $name, string $identity, int $limit, int $windowSeconds): void
    {
        $now = $this->clock !== null ? ($this->clock)() : time();
        $window = intdiv($now, $windowSeconds) * $windowSeconds;
        $bucket = hash('sha256', $name . '|' . $identity);

        $up = $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (:b, :w, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1'
        );
        $up->execute([':b' => $bucket, ':w' => $window]);

        $st = $this->pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = :b AND window_start = :w');
        $st->execute([':b' => $bucket, ':w' => $window]);
        $hits = (int) $st->fetchColumn();

        if ($hits > $limit) {
            throw HttpError::tooMany($window + $windowSeconds - $now);
        }
    }

    /** Deletes windows older than the given age. Run by the maintenance cron. */
    public function purge(int $olderThanSeconds = 86400): int
    {
        $now = $this->clock !== null ? ($this->clock)() : time();
        $st = $this->pdo->prepare('DELETE FROM rate_limits WHERE window_start < :t');
        $st->execute([':t' => max(0, $now - $olderThanSeconds)]);
        return $st->rowCount();
    }
}
