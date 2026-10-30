<?php

declare(strict_types=1);

namespace Vitalcare\Certificates;

/**
 * Public certificate check. Exactly three outcomes: valid, expired, not issued.
 *
 * - "Not issued" covers an unknown code, a malformed code, a draft and a withdrawn certificate, with
 *   one identical answer and no personal data, so the endpoint cannot be used to learn which codes
 *   exist or which are pending.
 * - `expires_on` is the last day of validity (inclusive). Dates are compared as Europe/London calendar
 *   dates, so a certificate expiring on a British Summer Time evening does not lapse early.
 * - A valid or expired answer carries the snapshot taken at issue and nothing else: no learner id,
 *   email, approver or internal id.
 */
final class CertificateVerifier
{
    public const ISSUER = 'Vitalcare Training Hub Ltd';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @return array{status:string,certificate?:array<string,mixed>} */
    public function check(string $rawCode, \DateTimeImmutable $now): array
    {
        $code = CertificateCode::normalise($rawCode);
        if ($code === null) {
            return ['status' => 'not_issued'];
        }
        $st = $this->pdo->prepare(
            'SELECT learner_name, course_title, course_hours, completed_on, expires_on, status
             FROM certificates WHERE code = :c LIMIT 1'
        );
        $st->execute([':c' => $code]);
        $row = $st->fetch();
        if ($row === false || $row['status'] !== 'approved') {
            return ['status' => 'not_issued'];
        }

        $today = $now->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
        $expires = $row['expires_on'] !== null ? (string) $row['expires_on'] : null;
        $expired = $expires !== null && $expires < $today;

        return [
            'status' => $expired ? 'expired' : 'valid',
            'certificate' => [
                'code' => CertificateCode::display($code),
                'learnerName' => (string) $row['learner_name'],
                'courseTitle' => (string) $row['course_title'],
                'hours' => $row['course_hours'] !== null ? (float) $row['course_hours'] : null,
                'completedOn' => (string) $row['completed_on'],
                'expiresOn' => $expires,
                'issuer' => self::ISSUER,
            ],
        ];
    }
}
