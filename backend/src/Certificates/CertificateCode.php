<?php

declare(strict_types=1);

namespace Vitalcare\Certificates;

/**
 * Public certificate codes: 12 characters from a 31-character alphabet with no 0, 1, I, L or O,
 * so they survive being read aloud or typed from paper. Generated from random bytes by rejection
 * sampling (no modulo bias). About 59 bits, so guessing is not a realistic attack even before the
 * rate limit.
 */
final class CertificateCode
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const LENGTH = 12;

    public static function generate(): string
    {
        $n = strlen(self::ALPHABET);
        $limit = intdiv(256, $n) * $n;
        $out = '';
        while (strlen($out) < self::LENGTH) {
            foreach (str_split(random_bytes(16)) as $ch) {
                $v = ord($ch);
                if ($v < $limit && strlen($out) < self::LENGTH) {
                    $out .= self::ALPHABET[$v % $n];
                }
            }
        }
        return $out;
    }

    /** Accepts what people type: any case, spaces, hyphens. Returns null unless it is a well-formed code. */
    public static function normalise(string $raw): ?string
    {
        if (strlen($raw) > 40) {
            return null;
        }
        $code = strtoupper((string) preg_replace('/[\s-]+/', '', $raw));
        if (strlen($code) !== self::LENGTH || strspn($code, self::ALPHABET) !== self::LENGTH) {
            return null;
        }
        return $code;
    }

    /** XXXX-XXXX-XXXX for printing. */
    public static function display(string $code): string
    {
        return implode('-', str_split($code, 4));
    }
}
