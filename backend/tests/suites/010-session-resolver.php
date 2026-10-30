<?php

declare(strict_types=1);

// "No row, no access": Clerk sign-up is public, so a valid identity alone grants nothing.
use Vitalcare\Auth\SessionResolver;

/** @var PDO $pdo */
$r = new SessionResolver($pdo);
$count = fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

// Unknown people get nothing, and nothing is created for them.
$before = $count();
check('unknown email has no access', $r->resolve(['sub' => 'user_x1', 'email' => 'stranger@example.com']) === null);
check('no row is created for a stranger', $count() === $before);
check('missing email claim has no access', $r->resolve(['sub' => 'user_x2']) === null);
check('non-string email claim has no access', $r->resolve(['sub' => 'user_x3', 'email' => ['a@example.com']]) === null);
check('malformed email has no access', $r->resolve(['sub' => 'user_x4', 'email' => 'not-an-email']) === null);
check('empty sub has no access', $r->resolve(['sub' => '', 'email' => 'a@example.com']) === null);
check('oversized sub has no access', $r->resolve(['sub' => str_repeat('u', 65), 'email' => 'a@example.com']) === null);
check('missing sub has no access', $r->resolve(['email' => 'a@example.com']) === null);

// First sign-in links the pre-created row by verified email, case-insensitively.
$learner = make_user($pdo, 'Learner@Example.com', 'learner');
$u = $r->resolve(['sub' => 'user_learner', 'email' => 'learner@EXAMPLE.com']);
check('pre-created user is linked on first sign-in', $u !== null && $u->id === $learner);
check('role comes from the database', $u !== null && $u->role === 'learner');
$stored = $pdo->query("SELECT clerk_user_id FROM users WHERE id = '$learner'")->fetchColumn();
check('the Clerk id is now stored', $stored === 'user_learner');

// Later sign-ins use the Clerk id, so the email may change in Clerk without losing access.
$u2 = $r->resolve(['sub' => 'user_learner', 'email' => 'new-address@example.com']);
check('linked user resolves by Clerk id even if the claim email changed', $u2 !== null && $u2->id === $learner);

// A second Clerk identity cannot take over an already linked email.
check('different Clerk id with the same email is refused', $r->resolve(['sub' => 'user_attacker', 'email' => 'learner@example.com']) === null);
$still = $pdo->query("SELECT clerk_user_id FROM users WHERE id = '$learner'")->fetchColumn();
check('the original link is untouched', $still === 'user_learner');

// Roles in the token are ignored: only the row decides.
$u3 = $r->resolve(['sub' => 'user_learner', 'email' => 'learner@example.com', 'role' => 'super_admin', 'public_metadata' => ['role' => 'admin']]);
check('a role claim in the token cannot escalate', $u3 !== null && $u3->role === 'learner');

// Disabled users have no access, linked or not.
$dis = make_user($pdo, 'gone@example.com', 'trainer', null, null, 'disabled');
check('disabled and unlinked user cannot link', $r->resolve(['sub' => 'user_gone', 'email' => 'gone@example.com']) === null);
check('the disabled row stays unlinked', $pdo->query("SELECT clerk_user_id FROM users WHERE id = '$dis'")->fetchColumn() === null);
$linkedDis = make_user($pdo, 'left@example.com', 'trainer', null, 'user_left', 'disabled');
check('disabled and linked user has no access', $r->resolve(['sub' => 'user_left', 'email' => 'left@example.com']) === null);
$pdo->exec("UPDATE users SET status = 'active' WHERE id = '$linkedDis'");
check('re-enabled user regains access', $r->resolve(['sub' => 'user_left', 'email' => 'left@example.com']) !== null);

// A race: the row is linked to someone else between our read and write.
$race = make_user($pdo, 'race@example.com', 'learner');
$pdo->exec("UPDATE users SET clerk_user_id = 'user_winner' WHERE id = '$race'");
check('losing a link race does not grant access', $r->resolve(['sub' => 'user_loser', 'email' => 'race@example.com']) === null);
check('the same identity retrying after the race succeeds', $r->resolve(['sub' => 'user_winner', 'email' => 'race@example.com']) !== null);

// Organisation scoping comes from the row.
$org = make_org($pdo, 'Test Care Home');
make_user($pdo, 'mgr@example.com', 'manager', $org);
$m = $r->resolve(['sub' => 'user_mgr', 'email' => 'mgr@example.com']);
check('manager carries their organisation from the row', $m !== null && $m->organisationId === $org);

// Email normalisation.
check('normaliseEmail trims and lowercases', SessionResolver::normaliseEmail('  A@B.Co ') === 'a@b.co');
check('normaliseEmail rejects overlong addresses', SessionResolver::normaliseEmail(str_repeat('a', 250) . '@b.co') === null);
