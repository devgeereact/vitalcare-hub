<?php

declare(strict_types=1);

// D-018 permissions, route by route: anonymous 401; no row, other roles and disabled staff 403 no_access;
// admin 403 forbidden on owner-only routes; super_admin everywhere.
use Vitalcare\Auth\Authz;
use Vitalcare\Auth\Role;
use Vitalcare\Auth\User;
use Vitalcare\Cms\AdminApi;
use Vitalcare\Support\Db;

/** @var PDO $pdo */
make_user($pdo, 'office@example.com', 'admin');
make_user($pdo, 'owner@example.com', 'super_admin');
make_user($pdo, 'trainer@example.com', 'trainer');
make_user($pdo, 'manager@example.com', 'manager', make_org($pdo, 'Elm Care'));
make_user($pdo, 'learner@example.com', 'learner');
make_user($pdo, 'disabled@example.com', 'admin', null, null, 'disabled');
$call = cms_api($pdo);
$U = Db::uuid();

// [method, path, owner only?, body, query]
$cases = [
    ['GET', '/api/admin/dashboard', false],
    ['GET', '/api/admin/search', false, null, ['q' => 'ab']],
    ['GET', '/api/admin/staff', false],
    ['GET', '/api/admin/people', false],
    ['POST', '/api/admin/people', false, '{}'],
    ['GET', "/api/admin/people/$U", false],
    ['PATCH', "/api/admin/people/$U", false, '{}'],
    ['GET', "/api/admin/people/$U/notes", false],
    ['POST', "/api/admin/people/$U/notes", false, '{}'],
    ['GET', '/api/admin/organisations', false],
    ['POST', '/api/admin/organisations', false, '{}'],
    ['GET', "/api/admin/organisations/$U", false],
    ['PATCH', "/api/admin/organisations/$U", false, '{}'],
    ['GET', "/api/admin/organisations/$U/notes", false],
    ['POST', "/api/admin/organisations/$U/notes", false, '{}'],
    ['GET', '/api/admin/courses', false],
    ['POST', '/api/admin/courses', true, '{}'],
    ['GET', "/api/admin/courses/$U", false],
    ['PATCH', "/api/admin/courses/$U", true, '{}'],
    ['GET', '/api/admin/sessions', false],
    ['POST', '/api/admin/sessions', false, '{}'],
    ['GET', "/api/admin/sessions/$U", false],
    ['PATCH', "/api/admin/sessions/$U", false, '{}'],
    ['POST', "/api/admin/sessions/$U/attendees", false, '{}'],
    ['PATCH', "/api/admin/attendees/$U", false, '{}'],
    ['GET', '/api/admin/certificates', false],
    ['POST', '/api/admin/certificates', false, ['attendeeId' => $U]],
    ['POST', '/api/admin/certificates', true, ['personId' => $U, 'courseId' => $U, 'completedOn' => '2026-10-01', 'reason' => 'Paper record from 2025']],
    ['GET', "/api/admin/certificates/$U", false],
    ['POST', "/api/admin/certificates/$U/revoke", true, '{}'],
    ['GET', "/api/admin/certificates/$U/pdf", false],
    ['GET', '/api/admin/renewals', false],
    ['GET', '/api/admin/follow-ups', false],
    ['POST', '/api/admin/follow-ups', false, '{}'],
    ['PATCH', "/api/admin/follow-ups/$U", false, '{}'],
    ['GET', '/api/admin/enquiries', false],
    ['GET', "/api/admin/enquiries/$U", false],
    ['PATCH', "/api/admin/enquiries/$U", false, '{}'],
    ['POST', "/api/admin/enquiries/$U/notes", false, '{}'],
    ['POST', "/api/admin/enquiries/$U/reply", false, '{}'],
    ['GET', '/api/admin/export/people.csv', true],
    ['GET', '/api/admin/export/certificates.csv', true],
    ['GET', '/api/admin/audit', true],
    ['GET', '/api/admin/users', true],
    ['POST', '/api/admin/users', true, '{}'],
    ['PATCH', "/api/admin/users/$U", true, '{}'],
];

// Every route the CMS has is in the table above (so a new route cannot ship untested).
$probe = new User(Db::uuid(), 'x@example.com', 'X', Role::ADMIN, null, true);
$routes = (new AdminApi($pdo, $probe, new Authz($probe), new DateTimeImmutable(), 'info@vitalcare.uk', null, []))->routes();
foreach ($routes as [$m, $pattern]) {
    $covered = false;
    foreach ($cases as $c) {
        if ($c[0] === $m && preg_match($pattern, substr($c[1], strlen('/api/admin'))) === 1) {
            $covered = true;
        }
    }
    check("route $m $pattern has a permission case", $covered);
}

foreach ($cases as $c) {
    [$m, $p, $ownerOnly] = $c;
    $body = $c[3] ?? null;
    $query = $c[4] ?? [];
    $label = "$m " . str_replace($U, '{id}', $p);
    check("$label: anonymous is 401", $call($m, $p, null, $body, $query)[0] === 401);
    foreach (['nobody', 'trainer', 'manager', 'learner', 'disabled'] as $who) {
        [$s, $b] = $call($m, $p, $who, $body, $query);
        check("$label: $who is 403 no_access", $s === 403 && ($b['error']['code'] ?? '') === 'no_access', "$s");
    }
    [$s, $b] = $call($m, $p, 'office', $body, $query);
    if ($ownerOnly) {
        check("$label: admin is 403 forbidden (owner only)", $s === 403 && ($b['error']['code'] ?? '') === 'forbidden', "$s " . json_encode($b));
    } else {
        check("$label: admin is let through (no 401, 403 or 5xx)", $s !== 401 && $s !== 403 && $s < 500, "$s " . json_encode($b));
    }
    [$s, $b] = $call($m, $p, 'owner', $body, $query);
    check("$label: owner is let through (no 401, 403 or 5xx)", $s !== 401 && $s !== 403 && $s < 500, "$s " . json_encode($b));
}

// The permission table itself.
$z = fn (string $role, bool $active = true) => new Authz(new User(Db::uuid(), 'a@example.com', 'A', $role, null, $active));
check('an unknown permission is refused even for the owner', !$z(Role::SUPER_ADMIN)->can('everything') && !$z(Role::SUPER_ADMIN)->can(''));
foreach (Authz::PERMISSIONS as $perm => $roles) {
    check("$perm is never held by trainer, manager or learner", !$z(Role::TRAINER)->can($perm) && !$z(Role::MANAGER)->can($perm) && !$z(Role::LEARNER)->can($perm));
    check("$perm is held by the owner", $z(Role::SUPER_ADMIN)->can($perm));
    check("$perm is refused for a disabled owner", !$z(Role::SUPER_ADMIN, false)->can($perm));
}
$ownerOnly = ['courses.write', 'certificates.issue_manual', 'certificates.revoke', 'export', 'audit.read', 'users.manage'];
foreach ($ownerOnly as $perm) {
    check("$perm is owner only", Authz::PERMISSIONS[$perm] === [Role::SUPER_ADMIN] && !$z(Role::ADMIN)->can($perm));
}
foreach (array_diff(array_keys(Authz::PERMISSIONS), $ownerOnly) as $perm) {
    check("$perm is open to admin", $z(Role::ADMIN)->can($perm));
}
check('canUseCms: admin and owner only', $z(Role::ADMIN)->canUseCms() && $z(Role::SUPER_ADMIN)->canUseCms() && !$z(Role::TRAINER)->canUseCms() && !$z(Role::MANAGER)->canUseCms() && !$z(Role::LEARNER)->canUseCms() && !(new Authz(null))->canUseCms());

// Routing edges.
check('an unknown admin route is 404 for staff', $call('GET', '/api/admin/nope', 'office')[0] === 404);
check('an unknown admin route is 401 for anonymous (no route map leak)', $call('GET', '/api/admin/nope')[0] === 401);
[$s, , $h] = $call('DELETE', '/api/admin/people', 'office');
check('a wrong method is 405 with Allow', $s === 405 && str_contains($h['Allow'] ?? '', 'GET'));
check('an id that is not a UUID is 404, never a query', $call('GET', "/api/admin/people/1'%20OR%201=1", 'office')[0] === 404);
check('no database means 503 for the CMS', (new Vitalcare\Http\Api(fn () => [], null))->handle('GET', '/api/admin/people', 'Bearer tok-office-' . str_repeat('x', 24))[0] === 503);
