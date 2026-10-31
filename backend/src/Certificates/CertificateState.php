<?php

declare(strict_types=1);

namespace Vitalcare\Certificates;

/**
 * The one rule for what a certificate row means, used by the public verify page and the CMS alike.
 *
 * - valid: approved, and today (Europe/London) is on or before expires_on, or there is no expiry date;
 * - expired: approved, and today is after expires_on (expires_on is the last day of validity);
 * - revoked: withdrawn through the CMS (status 'withdrawn' with revoked_at set);
 * - not_issued: anything else (draft, a withdrawal without a revocation record, an unknown status).
 */
final class CertificateState
{
    public const VALID = 'valid';
    public const EXPIRED = 'expired';
    public const REVOKED = 'revoked';
    public const NOT_ISSUED = 'not_issued';

    public static function of(string $status, ?string $revokedAt, ?string $expiresOn, string $today): string
    {
        if ($status === 'approved') {
            return $expiresOn !== null && $expiresOn < $today ? self::EXPIRED : self::VALID;
        }
        if ($status === 'withdrawn' && $revokedAt !== null) {
            return self::REVOKED;
        }
        return self::NOT_ISSUED;
    }
}
