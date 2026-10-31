<?php

declare(strict_types=1);

namespace Vitalcare\Certificates;

use Vitalcare\Cms\Audit;
use Vitalcare\Cms\LondonTime;
use Vitalcare\Http\HttpError;
use Vitalcare\Support\Db;

/**
 * Issues and revokes certificates (D-018). Permission checks happen before these are called; this class
 * holds the business rules:
 * - from attendance: the attendee attended and the result is pass or not_assessed, the session is not
 *   cancelled and has happened; one certificate per attendance;
 * - by hand: a reason is required and the completion date cannot be in the future;
 * - the completion date is the session's London date; expiry is computed here from the course's
 *   renewal months (Expiry); the issue date is today in London;
 * - the snapshot (name, course title, hours) is copied in and never changes afterwards.
 */
final class CertificateIssuer
{
    public function __construct(private readonly \PDO $pdo, private readonly \DateTimeImmutable $now)
    {
    }

    /** @return string the new certificate id */
    public function fromAttendance(string $attendeeId, string $actorId): string
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                "SELECT a.id, a.attendance, a.result, a.person_id, s.starts_at, s.status AS session_status,
                        c.id AS course_id, c.title, c.hours, c.renewal_months, p.first_name, p.last_name
                 FROM session_attendees a
                 JOIN training_sessions s ON s.id = a.session_id
                 JOIN courses c ON c.id = s.course_id
                 JOIN people p ON p.id = a.person_id
                 WHERE a.id = ? FOR UPDATE"
            );
            $st->execute([$attendeeId]);
            $a = $st->fetch();
            if ($a === false) {
                throw HttpError::notFound();
            }
            $dup = $this->pdo->prepare('SELECT id FROM certificates WHERE attendee_id = ?');
            $dup->execute([$attendeeId]);
            if ($dup->fetchColumn() !== false) {
                throw HttpError::conflict('already_issued', 'A certificate has already been issued for this attendance.');
            }
            $completed = LondonTime::dateOf((string) $a['starts_at']);
            if ($a['attendance'] !== 'attended' || !in_array($a['result'], ['pass', 'not_assessed'], true)
                || $a['session_status'] === 'cancelled' || $completed > LondonTime::today($this->now)) {
                throw HttpError::conflict('not_eligible', 'Only an attended session with a pass or "not assessed" result, on or before today, can have a certificate.');
            }
            $id = $this->insert([
                'person_id' => $a['person_id'],
                'course_id' => $a['course_id'],
                'attendee_id' => $a['id'],
                'learner_name' => trim($a['first_name'] . ' ' . $a['last_name']),
                'course_title' => $a['title'],
                'course_hours' => $a['hours'],
                'completed_on' => $completed,
                'expires_on' => Expiry::compute($completed, $a['renewal_months'] !== null ? (int) $a['renewal_months'] : null),
                'issue_reason' => null,
            ], $actorId);
            Audit::log($this->pdo, $actorId, 'certificate.issue', 'certificate', $id, ['attendeeId']);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** By hand, with a reason (owner only, checked by the caller). */
    public function manual(string $personId, string $courseId, string $completedOn, string $reason, string $actorId): string
    {
        $p = $this->pdo->prepare('SELECT first_name, last_name FROM people WHERE id = ?');
        $p->execute([$personId]);
        $person = $p->fetch();
        $c = $this->pdo->prepare('SELECT title, hours, renewal_months FROM courses WHERE id = ?');
        $c->execute([$courseId]);
        $course = $c->fetch();
        $fields = [];
        if ($person === false) {
            $fields['personId'] = 'Choose a person from the list.';
        }
        if ($course === false) {
            $fields['courseId'] = 'Choose a course from the list.';
        }
        if ($completedOn > LondonTime::today($this->now)) {
            $fields['completedOn'] = 'The completion date cannot be in the future.';
        }
        if ($fields !== []) {
            throw HttpError::invalid('Check the highlighted fields and try again.', $fields);
        }
        $this->pdo->beginTransaction();
        try {
            $id = $this->insert([
                'person_id' => $personId,
                'course_id' => $courseId,
                'attendee_id' => null,
                'learner_name' => trim($person['first_name'] . ' ' . $person['last_name']),
                'course_title' => $course['title'],
                'course_hours' => $course['hours'],
                'completed_on' => $completedOn,
                'expires_on' => Expiry::compute($completedOn, $course['renewal_months'] !== null ? (int) $course['renewal_months'] : null),
                'issue_reason' => $reason,
            ], $actorId);
            Audit::log($this->pdo, $actorId, 'certificate.issue_manual', 'certificate', $id, ['completedOn', 'courseId', 'personId', 'reason']);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function revoke(string $certificateId, string $reason, string $actorId): void
    {
        $st = $this->pdo->prepare(
            "UPDATE certificates SET status = 'withdrawn', revoked_at = ?, revoked_by = ?, revoke_reason = ?
             WHERE id = ? AND status = 'approved'"
        );
        $st->execute([$this->now->format('Y-m-d H:i:s.v'), $actorId, $reason, $certificateId]);
        if ($st->rowCount() !== 1) {
            $exists = $this->pdo->prepare('SELECT 1 FROM certificates WHERE id = ?');
            $exists->execute([$certificateId]);
            if ($exists->fetchColumn() === false) {
                throw HttpError::notFound();
            }
            throw HttpError::conflict('not_approved', 'Only an issued, unrevoked certificate can be revoked.');
        }
        Audit::log($this->pdo, $actorId, 'certificate.revoke', 'certificate', $certificateId, ['reason', 'status']);
    }

    /** @param array<string,mixed> $v */
    private function insert(array $v, string $actorId): string
    {
        $id = Db::uuid();
        $issued = LondonTime::today($this->now);
        for ($try = 0; $try < 5; $try++) {
            try {
                $this->pdo->prepare(
                    "INSERT INTO certificates (id, code, person_id, course_id, attendee_id, learner_name, course_title, course_hours,
                       completed_on, issued_on, expires_on, status, approved_at, approved_by, issue_reason)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?, 'approved', ?, ?, ?)"
                )->execute([
                    $id, CertificateCode::generate(), $v['person_id'], $v['course_id'], $v['attendee_id'], $v['learner_name'], $v['course_title'],
                    $v['course_hours'], $v['completed_on'], max($issued, $v['completed_on']), $v['expires_on'],
                    $this->now->format('Y-m-d H:i:s.v'), $actorId, $v['issue_reason'],
                ]);
                return $id;
            } catch (\PDOException $e) {
                // A code collision (about 1 in 10^17 per pair) is retried with a fresh code; anything else is real.
                if (!str_contains($e->getMessage(), 'certificates_code_uq')) {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('Could not generate a free certificate code');
    }
}
