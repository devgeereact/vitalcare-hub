<?php

declare(strict_types=1);

namespace Vitalcare;

/**
 * Turns verified Clerk claims into a database user, or into nothing.
 *
 * Clerk sign-up is public, so a valid token proves who someone is, not that they may use this
 * system. Access exists only when a `users` row exists and is active. Role and organisation come
 * from that row; nothing in the token or the request can change them.
 *
 * First sign-in: if no row carries this Clerk id, the one active row with the same email and no
 * Clerk id yet is linked to it. An email already linked to a different Clerk id is never relinked
 * here; an administrator must resolve that.
 */
final class SessionResolver
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @param array<string,mixed> $claims verified claims from Jwt::verify() */
    public function resolve(array $claims): ?User
    {
        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '' || strlen($sub) > 64) {
            return null;
        }

        $row = $this->byClerkId($sub);
        if ($row !== null) {
            return $row['status'] === 'active' ? User::fromRow($row) : null;
        }

        $email = self::normaliseEmail($claims['email'] ?? null);
        if ($email === null) {
            return null;
        }

        $st = $this->pdo->prepare(
            "SELECT id, email, display_name, role, organisation_id, status FROM users
             WHERE email_lower = :e AND clerk_user_id IS NULL AND status = 'active' LIMIT 1"
        );
        $st->execute([':e' => $email]);
        $candidate = $st->fetch();
        if ($candidate === false) {
            return null;
        }

        $link = $this->pdo->prepare(
            "UPDATE users SET clerk_user_id = :sub, last_sign_in_at = UTC_TIMESTAMP(3)
             WHERE id = :id AND clerk_user_id IS NULL AND status = 'active'"
        );
        $link->execute([':sub' => $sub, ':id' => $candidate['id']]);
        if ($link->rowCount() !== 1) {
            // Someone else linked it between our read and write. Accept only if it was this same identity.
            $again = $this->byClerkId($sub);
            return $again !== null && $again['status'] === 'active' ? User::fromRow($again) : null;
        }
        return User::fromRow($candidate);
    }

    /** @return array<string,mixed>|null */
    private function byClerkId(string $sub): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, email, display_name, role, organisation_id, status FROM users WHERE clerk_user_id = :s LIMIT 1'
        );
        $st->execute([':s' => $sub]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function normaliseEmail(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $e = strtolower(trim($raw));
        if ($e === '' || strlen($e) > 254 || filter_var($e, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return $e;
    }
}
