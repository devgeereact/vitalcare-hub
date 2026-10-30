<?php

declare(strict_types=1);

namespace Vitalcare;

/** The five roles. Anything else is treated as no role at all. */
final class Role
{
    public const SUPER_ADMIN = 'super_admin';
    public const ADMIN = 'admin';
    public const MANAGER = 'manager';
    public const TRAINER = 'trainer';
    public const LEARNER = 'learner';

    public const ALL = [self::SUPER_ADMIN, self::ADMIN, self::MANAGER, self::TRAINER, self::LEARNER];

    public static function valid(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }
}
