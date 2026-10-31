<?php

declare(strict_types=1);

namespace Vitalcare\Certificates;

/**
 * Certificate expiry, computed on the server only (D-018 item 3).
 *
 * expires_on = the completion (training) date plus the course's renewal months. When the target month is
 * shorter, the day is clamped to its last day: 31 January + 1 month = 28 February (29 in a leap year);
 * 29 February 2028 + 12 months = 28 February 2029. expires_on is the last day of validity. A course with
 * no renewal period gives no expiry date.
 */
final class Expiry
{
    public static function compute(string $completedOn, ?int $renewalMonths): ?string
    {
        if ($renewalMonths === null) {
            return null;
        }
        if ($renewalMonths < 1 || $renewalMonths > 120) {
            throw new \InvalidArgumentException('Renewal period must be 1 to 120 months');
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $completedOn, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException('Completion date must be YYYY-MM-DD');
        }
        $total = (int) $m[1] * 12 + ((int) $m[2] - 1) + $renewalMonths;
        $year = intdiv($total, 12);
        $month = $total % 12 + 1;
        $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new \DateTimeZone('UTC')))->format('t');
        return sprintf('%04d-%02d-%02d', $year, $month, min((int) $m[3], $lastDay));
    }
}

