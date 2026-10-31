<?php

declare(strict_types=1);

namespace Vitalcare\Auth;

use Vitalcare\Http\HttpError;

/**
 * Every authorisation rule lives here (docs/AUTHORISATION-MAP.md). Handlers call a predicate or
 * require(); none of them tests a role itself. Fails closed: an inactive user or an unknown role
 * holds no permission at all.
 *
 * Tenant model (D-010): the Vitalcare internal roles (trainer, admin, super_admin) are
 * platform-wide. A manager works for an employer and sees only their own organisation.
 */
final class Authz
{
    /**
     * CMS permissions (D-018). Only admin and super_admin ever hold one. An unknown name holds none.
     * Owner-only (super_admin): the public course statements, certificates without an attendance
     * behind them, revocation, bulk exports, the audit log and staff accounts.
     */
    public const PERMISSIONS = [
        'cms.read' => [Role::ADMIN, Role::SUPER_ADMIN],
        'people.write' => [Role::ADMIN, Role::SUPER_ADMIN],
        'organisations.write' => [Role::ADMIN, Role::SUPER_ADMIN],
        'notes.write' => [Role::ADMIN, Role::SUPER_ADMIN],
        'followups.write' => [Role::ADMIN, Role::SUPER_ADMIN],
        'sessions.write' => [Role::ADMIN, Role::SUPER_ADMIN],
        'attendance.write' => [Role::ADMIN, Role::SUPER_ADMIN],
        'certificates.issue' => [Role::ADMIN, Role::SUPER_ADMIN],
        'enquiries.write' => [Role::ADMIN, Role::SUPER_ADMIN],
        'courses.write' => [Role::SUPER_ADMIN],
        'certificates.issue_manual' => [Role::SUPER_ADMIN],
        'certificates.revoke' => [Role::SUPER_ADMIN],
        'export' => [Role::SUPER_ADMIN],
        'audit.read' => [Role::SUPER_ADMIN],
        'users.manage' => [Role::SUPER_ADMIN],
    ];

    private readonly bool $usable;

    public function __construct(private readonly ?User $user)
    {
        $this->usable = $user !== null && $user->active && Role::valid($user->role);
    }

    /** D-018: only the owner and office staff sign in. Everyone else, including other roles, is "no access". */
    public function canUseCms(): bool
    {
        return $this->has(Role::ADMIN, Role::SUPER_ADMIN);
    }

    /** One named CMS permission. Fails closed for an unknown name and for anyone outside the CMS. */
    public function can(string $permission): bool
    {
        $roles = self::PERMISSIONS[$permission] ?? null;
        return $roles !== null && $this->canUseCms() && $this->has(...$roles);
    }

    public function authenticated(): bool
    {
        return $this->usable;
    }

    public function userId(): ?string
    {
        return $this->usable ? $this->user?->id : null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->has(Role::SUPER_ADMIN);
    }

    public function isAdmin(): bool
    {
        return $this->has(Role::ADMIN, Role::SUPER_ADMIN);
    }

    /** Source is_manager(): manager, admin, super_admin. */
    public function isManager(): bool
    {
        return $this->has(Role::MANAGER, Role::ADMIN, Role::SUPER_ADMIN);
    }

    /** Source is_staff(): trainer, admin, super_admin. A manager is NOT staff. */
    public function isStaff(): bool
    {
        return $this->has(Role::TRAINER, Role::ADMIN, Role::SUPER_ADMIN);
    }

    /** Source is_internal(): trainer, manager, admin, super_admin. */
    public function isInternal(): bool
    {
        return $this->has(Role::TRAINER, Role::MANAGER, Role::ADMIN, Role::SUPER_ADMIN);
    }

    /** True when the target is the signed-in user, or both are in the same non-empty organisation. */
    public function sameOrg(string $targetUserId, ?string $targetOrganisationId): bool
    {
        if (!$this->usable) {
            return false;
        }
        if ($targetUserId === $this->user?->id) {
            return true;
        }
        if ($this->isSuperAdmin()) {
            return true;
        }
        $mine = $this->user?->organisationId;
        return $mine !== null && $targetOrganisationId !== null && $mine === $targetOrganisationId;
    }

    /**
     * Source staff_for(u): staff acting on a person. Platform-wide for internal staff (D-010), so
     * the target is not consulted. The arguments keep every call site shaped like the map in
     * docs/AUTHORISATION-MAP.md, and let a later tenant rule change here only.
     */
    public function staffFor(string $targetUserId, ?string $targetOrganisationId): bool
    {
        unset($targetUserId, $targetOrganisationId);
        return $this->isStaff();
    }

    /**
     * May the signed-in user read this person's record? Self, any staff member, or a manager
     * within their own organisation (read only).
     */
    public function canReadPerson(string $targetUserId, ?string $targetOrganisationId): bool
    {
        if (!$this->usable) {
            return false;
        }
        if ($targetUserId === $this->user?->id || $this->isStaff()) {
            return true;
        }
        return $this->has(Role::MANAGER)
            && $this->user?->organisationId !== null
            && $this->user->organisationId === $targetOrganisationId;
    }

    /** Throws 403 unless the condition holds. The message never says which rule failed. */
    public function require(bool $allowed): void
    {
        if (!$allowed) {
            throw HttpError::forbidden();
        }
    }

    private function has(string ...$roles): bool
    {
        return $this->usable && in_array($this->user?->role, $roles, true);
    }
}
