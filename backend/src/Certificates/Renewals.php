<?php

declare(strict_types=1);

namespace Vitalcare\Certificates;

use Vitalcare\Cms\LondonTime;
use Vitalcare\Support\Db;

/**
 * Renewals: the CMS view, and the reminder emails (D-018 item 5).
 *
 * A certificate is "current" when it is approved and no later approved certificate exists for the same
 * person and course (a renewal supersedes it). Only current certificates appear in the view or get reminders.
 *
 * Reminders: stages 60, 30 and 7 days before expires_on. On each run the most urgent due stage is chosen
 * (days left <= 7 gives 7, <= 30 gives 30, <= 60 gives 60), so a certificate first seen late never gets a
 * stale early reminder. INSERT IGNORE on (certificate, stage) decides whether this run owns the stage;
 * the marker and the queued emails are committed together. Running twice queues nothing new.
 */
final class Renewals
{
    public const STAGES = [7, 30, 60];

    /** SQL condition (alias c): approved, held by a person, and not superseded by a later certificate for the same course. */
    public const CURRENT = "c.status = 'approved' AND c.person_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM certificates n WHERE n.person_id = c.person_id AND n.course_id <=> c.course_id
          AND n.status = 'approved' AND n.id <> c.id
          AND (n.expires_on IS NULL OR (c.expires_on IS NOT NULL AND n.expires_on > c.expires_on)))";

    public function __construct(private readonly \PDO $pdo, private readonly \DateTimeImmutable $now)
    {
    }

    /** The stage a reminder is due for with this many days left, or null when none is due. */
    public static function stageFor(int $daysLeft): ?int
    {
        if ($daysLeft < 0) {
            return null;
        }
        foreach (self::STAGES as $s) {
            if ($daysLeft <= $s) {
                return $s;
            }
        }
        return null;
    }

    /**
     * @param string $window '30', '60', '90' or 'expired'
     * @return list<array<string,mixed>>
     */
    public function view(string $window): array
    {
        $today = LondonTime::today($this->now);
        [$where, $params] = $window === 'expired'
            ? ['c.expires_on < ?', [$today]]
            : ['c.expires_on BETWEEN ? AND ?', [$today, LondonTime::addDays($today, (int) $window)]];
        $st = $this->pdo->prepare(
            'SELECT c.id, c.code, c.person_id, c.learner_name, c.course_title, c.expires_on, o.id AS organisation_id, o.name AS organisation_name,
                    p.email, (SELECT GROUP_CONCAT(r.stage ORDER BY r.stage DESC) FROM certificate_reminders r WHERE r.certificate_id = c.id) AS stages
             FROM certificates c JOIN people p ON p.id = c.person_id LEFT JOIN organisations o ON o.id = p.organisation_id
             WHERE ' . self::CURRENT . " AND c.expires_on IS NOT NULL AND $where
             ORDER BY c.expires_on " . ($window === 'expired' ? 'DESC' : 'ASC') . ' LIMIT 500'
        );
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[] = [
                'certificateId' => $r['id'],
                'code' => CertificateCode::display((string) $r['code']),
                'personId' => $r['person_id'],
                'personName' => $r['learner_name'],
                'hasEmail' => $r['email'] !== null,
                'organisationId' => $r['organisation_id'],
                'organisationName' => $r['organisation_name'],
                'courseTitle' => $r['course_title'],
                'expiresOn' => $r['expires_on'],
                'daysLeft' => LondonTime::daysBetween($today, (string) $r['expires_on']),
                'remindersSent' => $r['stages'] !== null ? array_map('intval', explode(',', (string) $r['stages'])) : [],
            ];
        }
        return $out;
    }

    /** Counts for the dashboard: current certificates expiring in the next 30 days, and expired in the last 90. */
    public function counts(): array
    {
        $today = LondonTime::today($this->now);
        $q = fn (string $where, array $p) => (function () use ($where, $p): int {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM certificates c WHERE ' . self::CURRENT . " AND c.expires_on IS NOT NULL AND $where");
            $st->execute($p);
            return (int) $st->fetchColumn();
        })();
        return [
            'expiring30' => $q('c.expires_on BETWEEN ? AND ?', [$today, LondonTime::addDays($today, 30)]),
            'expired90' => $q('c.expires_on < ? AND c.expires_on >= ?', [$today, LondonTime::addDays($today, -90)]),
        ];
    }

    /**
     * Queues due reminders through the outbox. Safe to run every minute.
     *
     * @return array{certificates:int,emails:int,skipped:bool}
     */
    public function queueReminders(string $officeEmail): array
    {
        $lock = $this->pdo->query("SELECT GET_LOCK('vitalcare_reminders', 0)")->fetchColumn();
        if ((int) $lock !== 1) {
            return ['certificates' => 0, 'emails' => 0, 'skipped' => true];
        }
        $done = ['certificates' => 0, 'emails' => 0, 'skipped' => false];
        try {
            $today = LondonTime::today($this->now);
            $st = $this->pdo->prepare(
                'SELECT c.id, c.code, c.course_title, c.expires_on, p.first_name, p.last_name, p.email, p.reminders_opt_out, p.status AS person_status,
                        o.contact_email, o.reminder_copy, o.status AS organisation_status
                 FROM certificates c JOIN people p ON p.id = c.person_id LEFT JOIN organisations o ON o.id = p.organisation_id
                 WHERE ' . self::CURRENT . ' AND c.expires_on BETWEEN ? AND ? ORDER BY c.expires_on LIMIT 500'
            );
            $st->execute([$today, LondonTime::addDays($today, max(self::STAGES))]);
            foreach ($st->fetchAll() as $c) {
                $stage = self::stageFor(LondonTime::daysBetween($today, (string) $c['expires_on']));
                if ($stage === null) {
                    continue;
                }
                $this->pdo->beginTransaction();
                try {
                    $mark = $this->pdo->prepare('INSERT IGNORE INTO certificate_reminders (certificate_id, stage) VALUES (?, ?)');
                    $mark->execute([$c['id'], $stage]);
                    if ($mark->rowCount() !== 1) {
                        $this->pdo->rollBack();
                        continue;   // this stage was already handled: never twice
                    }
                    $sent = 0;
                    foreach ($this->messages($c) as [$to, $subject, $body]) {
                        $this->pdo->prepare('INSERT INTO outbox_email (id, to_email, reply_to, subject, body_text) VALUES (?,?,?,?,?)')
                            ->execute([Db::uuid(), $to, $officeEmail, $subject, $body]);
                        $sent++;
                    }
                    $this->pdo->prepare('UPDATE certificate_reminders SET recipients = ? WHERE certificate_id = ? AND stage = ?')->execute([$sent, $c['id'], $stage]);
                    $this->pdo->commit();
                    $done['certificates']++;
                    $done['emails'] += $sent;
                } catch (\Throwable $e) {
                    $this->pdo->rollBack();
                    throw $e;
                }
            }
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('vitalcare_reminders')");
        }
        return $done;
    }

    /**
     * @param array<string,mixed> $c
     * @return list<array{0:string,1:string,2:string}> to, subject, body
     */
    private function messages(array $c): array
    {
        $date = (new \DateTimeImmutable($c['expires_on'] . ' 12:00:00', new \DateTimeZone('UTC')))->format('j F Y');
        $code = CertificateCode::display((string) $c['code']);
        $course = (string) $c['course_title'];
        $name = trim($c['first_name'] . ' ' . $c['last_name']);
        $footer = "\n\nVitalcare Training Hub Ltd\nEmail info@vitalcare.uk. Telephone 020 8059 8757. Monday to Friday, 9am to 5pm.\n";
        $out = [];
        if ($c['email'] !== null && (int) $c['reminders_opt_out'] === 0 && $c['person_status'] === 'active') {
            $out[] = [
                (string) $c['email'],
                "Your $course certificate is valid until $date",
                "Hello {$c['first_name']},\n\nYour certificate for $course (code $code) is valid until $date. "
                . "To book a refresher, reply to this email or contact us.\n\nIf you would rather not receive these reminders, reply and tell us."
                . $footer,
            ];
        }
        if ((int) ($c['reminder_copy'] ?? 0) === 1 && $c['contact_email'] !== null && $c['organisation_status'] === 'active'
            && strcasecmp((string) $c['contact_email'], (string) ($c['email'] ?? '')) !== 0) {
            $out[] = [
                (string) $c['contact_email'],
                "Certificate renewal due: $name, $course",
                "Hello,\n\nThe certificate for $name on $course (code $code) is valid until $date. "
                . "To book a refresher, reply to this email or contact us." . $footer,
            ];
        }
        return $out;
    }
}
