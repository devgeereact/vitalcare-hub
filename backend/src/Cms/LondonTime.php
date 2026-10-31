<?php

declare(strict_types=1);

namespace Vitalcare\Cms;

/** The office works in Europe/London; the database stores UTC. These are the only conversions. */
final class LondonTime
{
    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/London');
    }

    /** "2026-11-05T09:30" in London to "2026-11-05 09:30:00" UTC, or null if it is not a real local time. */
    public static function toUtc(string $local): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $local) !== 1) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, self::zone());
        if ($d === false || $d->format('Y-m-d\TH:i') !== $local) {
            return null;   // 31 February, 25:00, or a time skipped when the clocks go forward
        }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** A stored UTC "Y-m-d H:i:s" as London "Y-m-d\TH:i". */
    public static function fromUtc(?string $utc): ?string
    {
        if ($utc === null) {
            return null;
        }
        $d = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        return $d->setTimezone(self::zone())->format('Y-m-d\TH:i');
    }

    /** The London calendar date of a stored UTC timestamp. */
    public static function dateOf(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(self::zone())->format('Y-m-d');
    }

    public static function today(\DateTimeImmutable $now): string
    {
        return $now->setTimezone(self::zone())->format('Y-m-d');
    }

    public static function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date . ' 12:00:00', new \DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** Whole calendar days from $from to $to (negative when $to is earlier). */
    public static function daysBetween(string $from, string $to): int
    {
        $a = new \DateTimeImmutable($from . ' 12:00:00', new \DateTimeZone('UTC'));
        $b = new \DateTimeImmutable($to . ' 12:00:00', new \DateTimeZone('UTC'));
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }
}
