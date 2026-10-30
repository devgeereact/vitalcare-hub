<?php

declare(strict_types=1);

namespace Vitalcare\Contact;

use Vitalcare\Auth\SessionResolver;
use Vitalcare\Http\HttpError;
use Vitalcare\Support\Db;

/**
 * Validates a contact-form submission, stores it, and queues one email to the office.
 * Nothing is ever sent to the person who wrote in (no relay for strangers to abuse).
 * The subject is fixed; the visitor's address is only ever used as a validated Reply-To.
 */
final class ContactService
{
    public function __construct(private readonly \PDO $pdo, private readonly string $officeEmail)
    {
    }

    /**
     * @param array<string,mixed> $in decoded JSON body
     * @return array{name:string,email:string,phone:?string,message:string}
     * @throws HttpError 422 with per-field messages
     */
    public static function validate(array $in): array
    {
        $errors = [];

        $name = self::text($in['name'] ?? null);
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            $errors['name'] = 'Enter your name.';
        }

        $email = SessionResolver::normaliseEmail($in['email'] ?? null);
        if ($email === null) {
            $errors['email'] = 'Enter a valid email address.';
        }

        $phoneRaw = self::text($in['phone'] ?? null);
        $phone = null;
        if ($phoneRaw !== '') {
            if (preg_match('/^[0-9 +()\-]{6,30}$/', $phoneRaw) !== 1) {
                $errors['phone'] = 'Enter a telephone number using digits, spaces, plus, brackets or hyphens.';
            } else {
                $phone = $phoneRaw;
            }
        }

        $message = is_string($in['message'] ?? null) ? trim(str_replace("\0", '', $in['message'])) : '';
        $len = mb_strlen($message);
        if ($len < 10) {
            $errors['message'] = 'Tell us a little more: at least 10 characters.';
        } elseif ($len > 4000) {
            $errors['message'] = 'Keep your message under 4,000 characters.';
        }

        if ($errors !== []) {
            throw HttpError::invalid('Check the highlighted fields and try again.', $errors);
        }
        return ['name' => $name, 'email' => (string) $email, 'phone' => $phone, 'message' => $message];
    }

    /** @param array{name:string,email:string,phone:?string,message:string} $v */
    public function store(array $v, ?string $ipHash): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO enquiries (id, name, email, phone, message, ip_hash) VALUES (?,?,?,?,?,?)')
                ->execute([Db::uuid(), $v['name'], $v['email'], $v['phone'], $v['message'], $ipHash]);
            $body = "New enquiry from the website\n\n"
                . "Name: {$v['name']}\n"
                . "Email: {$v['email']}\n"
                . 'Telephone: ' . ($v['phone'] ?? 'not given') . "\n\n"
                . "Message:\n{$v['message']}\n";
            $this->pdo->prepare('INSERT INTO outbox_email (id, to_email, reply_to, subject, body_text) VALUES (?,?,?,?,?)')
                ->execute([Db::uuid(), $this->officeEmail, $v['email'], 'New website enquiry', $body]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private static function text(mixed $v): string
    {
        return is_string($v) ? trim($v) : '';
    }
}
