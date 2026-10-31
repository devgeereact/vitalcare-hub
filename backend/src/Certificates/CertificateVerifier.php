<?php

declare(strict_types=1);

namespace Vitalcare\Certificates;

/**
 * Public certificate check. Four outcomes: valid, expired, revoked, not issued (CertificateState).
 *
 * - "Not issued" covers an unknown code, a malformed code, a draft and a withdrawal that was never a
 *   revocation, with one identical answer and no personal data, so the endpoint cannot be used to learn
 *   which codes exist or which are pending.
 * - "Revoked" (D-018 item 4) says so with the code, course title and dates, but never the person's name
 *   or the reason.
 * - `expires_on` is the last day of validity (inclusive). Dates are compared as Europe/London calendar
 *   dates, so a certificate expiring on a British Summer Time evening does not lapse early.
 * - A valid or expired answer carries the snapshot taken at issue and nothing else: no person id,
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
            return ['status' => CertificateState::NOT_ISSUED];
        }
        $st = $this->pdo->prepare(
            'SELECT learner_name, course_title, course_hours, completed_on, expires_on, status, revoked_at
             FROM certificates WHERE code = :c LIMIT 1'
        );
        $st->execute([':c' => $code]);
        $row = $st->fetch();
        if ($row === false) {
            return ['status' => CertificateState::NOT_ISSUED];
        }
        $london = new \DateTimeZone('Europe/London');
        $today = $now->setTimezone($london)->format('Y-m-d');
        $expires = $row['expires_on'] !== null ? (string) $row['expires_on'] : null;
        $revokedAt = $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null;
        $state = CertificateState::of((string) $row['status'], $revokedAt, $expires, $today);

        if ($state === CertificateState::NOT_ISSUED) {
            return ['status' => CertificateState::NOT_ISSUED];
        }
        if ($state === CertificateState::REVOKED) {
            return [
                'status' => CertificateState::REVOKED,
                'certificate' => [
                    'code' => CertificateCode::display($code),
                    'courseTitle' => (string) $row['course_title'],
                    'completedOn' => (string) $row['completed_on'],
                    'revokedOn' => (new \DateTimeImmutable((string) $revokedAt, new \DateTimeZone('UTC')))->setTimezone($london)->format('Y-m-d'),
                    'issuer' => self::ISSUER,
                ],
            ];
        }
        return [
            'status' => $state,
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
