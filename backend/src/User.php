<?php

declare(strict_types=1);

namespace Vitalcare;

/** The signed-in person as the database knows them. Role and organisation come from here, never from a token or a request. */
final class User
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $role,
        public readonly ?string $organisationId,
        public readonly bool $active,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['id'],
            (string) $row['email'],
            (string) $row['display_name'],
            (string) $row['role'],
            isset($row['organisation_id']) ? (string) $row['organisation_id'] : null,
            ($row['status'] ?? '') === 'active',
        );
    }
}
