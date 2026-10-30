<?php

declare(strict_types=1);

// The role matrix (docs/AUTHORISATION-MAP.md, decision D-010).
use Vitalcare\Auth\Authz;
use Vitalcare\Http\HttpError;
use Vitalcare\Auth\Role;
use Vitalcare\Auth\User;

$org1 = 'org-1';
$org2 = 'org-2';
function u(string $role, ?string $org = null, bool $active = true, string $id = ''): User
{
    return new User($id ?: "id-$role", "$role@example.com", $role, $role, $org, $active);
}

$matrix = [
    //            super  admin  manager staff  internal
    'super_admin' => [true, true, true, true, true],
    'admin'       => [false, true, true, true, true],
    'manager'     => [false, false, true, false, true],
    'trainer'     => [false, false, false, true, true],
    'learner'     => [false, false, false, false, false],
];
foreach ($matrix as $role => [$super, $admin, $manager, $staff, $internal]) {
    $z = new Authz(u($role));
    check("$role authenticated", $z->authenticated());
    check("$role isSuperAdmin", $z->isSuperAdmin() === $super);
    check("$role isAdmin", $z->isAdmin() === $admin);
    check("$role isManager", $z->isManager() === $manager);
    check("$role isStaff", $z->isStaff() === $staff);
    check("$role isInternal", $z->isInternal() === $internal);
}

// Fail closed: nobody, inactive users and unknown roles hold nothing.
foreach (['nobody' => new Authz(null), 'inactive super_admin' => new Authz(u('super_admin', null, false)), 'unknown role' => new Authz(u('owner')), 'content_editor (retired)' => new Authz(u('content_editor')), 'guest (retired)' => new Authz(u('guest')), 'empty role' => new Authz(u(''))] as $label => $z) {
    check("$label is not authenticated", !$z->authenticated());
    check("$label holds no role", !$z->isSuperAdmin() && !$z->isAdmin() && !$z->isManager() && !$z->isStaff() && !$z->isInternal());
    check("$label cannot read a person", !$z->canReadPerson('someone', $org1));
    check("$label is not staff for anyone", !$z->staffFor('someone', $org1));
    expectError("$label fails require()", fn () => $z->require($z->isStaff()), 403, 'forbidden');
}
check('role string is case-sensitive (Admin is not admin)', !(new Authz(u('Admin')))->isAdmin());

// canReadPerson: self, staff (platform-wide), manager only inside their own organisation.
$learner = new Authz(u('learner', $org1, true, 'L1'));
check('learner reads themselves', $learner->canReadPerson('L1', $org1));
check('learner cannot read a colleague in the same organisation', !$learner->canReadPerson('L2', $org1));
check('learner cannot read someone elsewhere', !$learner->canReadPerson('L3', $org2));

$trainer = new Authz(u('trainer', null, true, 'T1'));
check('trainer reads a learner in any organisation (D-010)', $trainer->canReadPerson('L2', $org1) && $trainer->canReadPerson('L3', $org2) && $trainer->canReadPerson('L4', null));
check('trainer staffFor is platform-wide', $trainer->staffFor('L2', $org2));

$mgr = new Authz(u('manager', $org1, true, 'M1'));
check('manager reads their own organisation member', $mgr->canReadPerson('L2', $org1));
check('manager cannot read another organisation member', !$mgr->canReadPerson('L3', $org2));
check('manager cannot read a person with no organisation', !$mgr->canReadPerson('L4', null));
check('manager is not staffFor anyone (read only, not staff)', !$mgr->staffFor('L2', $org1));
$mgrNoOrg = new Authz(u('manager', null, true, 'M2'));
check('manager with no organisation reads nobody else', !$mgrNoOrg->canReadPerson('L2', null) && !$mgrNoOrg->canReadPerson('L2', $org1));
check('manager with no organisation still reads themselves', $mgrNoOrg->canReadPerson('M2', null));

$admin = new Authz(u('admin', null, true, 'A1'));
check('admin reads anyone', $admin->canReadPerson('L9', $org2));

// sameOrg mirrors the source: self, super_admin anywhere, otherwise the same non-null organisation.
check('sameOrg: self', $learner->sameOrg('L1', null));
check('sameOrg: same non-null organisation', $mgr->sameOrg('X', $org1));
check('sameOrg: different organisation', !$mgr->sameOrg('X', $org2));
check('sameOrg: null on both sides is not a match', !$mgrNoOrg->sameOrg('X', null));
check('sameOrg: super_admin everywhere', (new Authz(u('super_admin')))->sameOrg('X', $org2));

// require() throws a bare 403 that does not explain which rule failed.
try {
    $learner->require($learner->isAdmin());
    check('require denies a learner admin action', false);
} catch (HttpError $e) {
    check('require denies a learner admin action', $e->status === 403 && $e->errorCode === 'forbidden');
    check('the 403 message does not name a role or rule', !preg_match('/admin|role|manager|staff/i', $e->getMessage()), $e->getMessage());
}
$learner->require(true);
check('require(true) passes silently', true);

// Role constants stay in step with the database CHECK.
check('five roles exist', count(Role::ALL) === 5);
