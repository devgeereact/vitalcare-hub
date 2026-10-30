<?php

declare(strict_types=1);

namespace Vitalcare\Mail;

/**
 * Sends queued rows from outbox_email. Safe to run from cron every minute:
 * - one sender at a time (a named database lock; a second run exits at once);
 * - only rows that are queued and due (next_attempt_at has passed);
 * - a failure schedules a later retry (1, 5, 15, 60 minutes, then 4 hours) and the row becomes `failed`
 *   after 5 failed attempts. last_error stores only the short, secret-free failure text.
 */
final class OutboxSender
{
    public const MAX_ATTEMPTS = 5;
    private const BACKOFF_SECONDS = [60, 300, 900, 3600, 14400];

    /** @param callable(string,?string,string,string):void $send sends one message, throws on failure */
    public function __construct(private readonly \PDO $pdo, private $send, private readonly int $batch = 20)
    {
    }

    /** @return array{sent:int,failed:int,retry:int,skipped:bool} */
    public function run(): array
    {
        $lock = $this->pdo->query("SELECT GET_LOCK('vitalcare_outbox', 0)")->fetchColumn();
        if ((int) $lock !== 1) {
            return ['sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => true];
        }
        $result = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => false];
        try {
            $due = $this->pdo->prepare(
                "SELECT id, to_email, reply_to, subject, body_text, attempts FROM outbox_email
                 WHERE status = 'queued' AND next_attempt_at <= CURRENT_TIMESTAMP(3)
                 ORDER BY created_at LIMIT " . (int) $this->batch
            );
            $due->execute();
            foreach ($due->fetchAll() as $row) {
                try {
                    ($this->send)((string) $row['to_email'], $row['reply_to'] !== null ? (string) $row['reply_to'] : null, (string) $row['subject'], (string) $row['body_text']);
                    $this->pdo->prepare("UPDATE outbox_email SET status = 'sent', sent_at = CURRENT_TIMESTAMP(3), last_error = NULL WHERE id = :id AND status = 'queued'")
                        ->execute([':id' => $row['id']]);
                    $result['sent']++;
                } catch (\Throwable $e) {
                    $attempts = (int) $row['attempts'] + 1;
                    $message = $e instanceof SmtpException ? $e->getMessage() : 'Unexpected sending error';
                    if ($attempts >= self::MAX_ATTEMPTS) {
                        $this->pdo->prepare("UPDATE outbox_email SET status = 'failed', attempts = :a, last_error = :e WHERE id = :id")
                            ->execute([':a' => $attempts, ':e' => substr($message, 0, 255), ':id' => $row['id']]);
                        $result['failed']++;
                    } else {
                        $delay = self::BACKOFF_SECONDS[$attempts - 1];
                        $this->pdo->prepare("UPDATE outbox_email SET attempts = :a, last_error = :e, next_attempt_at = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL :d SECOND) WHERE id = :id")
                            ->execute([':a' => $attempts, ':e' => substr($message, 0, 255), ':d' => $delay, ':id' => $row['id']]);
                        $result['retry']++;
                    }
                    if (!($e instanceof SmtpException)) {
                        // The class only. An arbitrary exception message may carry a credential or an address.
                        error_log('[outbox] unexpected ' . get_class($e));
                    }
                }
            }
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('vitalcare_outbox')");
        }
        return $result;
    }
}
