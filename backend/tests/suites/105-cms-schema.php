<?php

declare(strict_types=1);

// Migrations 006 to 010: the database refuses what the CMS code must never write.
use Vitalcare\Support\Db;

/** @var PDO $pdo */
$fails = function (string $label, callable $fn) {
    try {
        $fn();
        check($label, false, 'no exception');
    } catch (PDOException) {
        check($label, true);
    }
};
$ok = function (string $label, callable $fn) {
    try {
        $fn();
        check($label, true);
    } catch (PDOException $e) {
        check($label, false, $e->getMessage());
    }
};
$admin = make_user($pdo, 'office@example.com', 'admin');
$org = make_org($pdo, 'Oak House');

// ---- organisations and people ----
$o = $pdo->query("SELECT kind, reminder_copy FROM organisations WHERE id = '$org'")->fetch();
check('an existing organisation gets kind employer and no reminder copy', $o['kind'] === 'employer' && (int) $o['reminder_copy'] === 0);
$fails('organisation kind outside the list is refused', fn () => $pdo->exec("UPDATE organisations SET kind = 'hospital' WHERE id = '$org'"));
$person = fn (array $o = []) => $pdo->prepare('INSERT INTO people (id, first_name, last_name, email, organisation_id, status, created_by) VALUES (?,?,?,?,?,?,?)')
    ->execute([$o['id'] ?? Db::uuid(), $o['first'] ?? 'Pat', $o['last'] ?? 'Jones', $o['email'] ?? null, $o['org'] ?? null, $o['status'] ?? 'active', $admin]);
$pid = Db::uuid();
$ok('a person with no email and no organisation is allowed', fn () => $person(['id' => $pid]));
$ok('two people may share a work email', fn () => $person(['email' => 'Team@Oak.example']) && $person(['email' => 'team@oak.example']));
check('email_lower is filled for search', (int) $pdo->query("SELECT COUNT(*) FROM people WHERE email_lower = 'team@oak.example'")->fetchColumn() === 2);
$fails('a person needs a first name', fn () => $person(['first' => '']));
$fails('a person status outside active/archived is refused', fn () => $person(['status' => 'deleted']));
$fails('a person cannot point at a missing organisation', fn () => $person(['org' => Db::uuid()]));

// ---- courses ----
$course = function (array $o = []) use ($pdo): string {
    $id = Db::uuid();
    $pdo->prepare('INSERT INTO courses (id, slug, title, summary, format, renewal_months, hours, published, published_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $o['slug'] ?? 'basic-life-support-' . substr($id, -6), $o['title'] ?? 'Basic Life Support', 'Summary', $o['format'] ?? 'in_person',
            array_key_exists('renewal', $o) ? $o['renewal'] : 12, $o['hours'] ?? null, $o['published'] ?? 0, $o['publishedAt'] ?? null]);
    return $id;
};
$cid = $course(['slug' => 'moving-and-handling']);
$fails('course slugs are unique', fn () => $course(['slug' => 'moving-and-handling']));
foreach (['Moving', 'moving handling', '-moving', 'moving--handling', 'moving_handling', ''] as $bad) {
    $fails('slug ' . json_encode($bad) . ' is refused', fn () => $course(['slug' => $bad]));
}
$fails('format outside in_person/online/blended is refused', fn () => $course(['format' => 'video']));
$fails('renewal of 0 months is refused', fn () => $course(['renewal' => 0]));
$fails('renewal over 120 months is refused', fn () => $course(['renewal' => 121]));
$ok('no renewal period (no expiry) is allowed', fn () => $course(['renewal' => null]));
$fails('hours must be positive', fn () => $course(['hours' => 0]));
$fails('published needs published_at', fn () => $course(['published' => 1]));

// ---- sessions and attendees ----
$sid = Db::uuid();
$pdo->prepare("INSERT INTO training_sessions (id, course_id, starts_at, ends_at, capacity) VALUES (?,?, '2026-11-05 09:00:00', '2026-11-05 12:00:00', 2)")->execute([$sid, $cid]);
$fails('a session must end after it starts', fn () => $pdo->prepare("INSERT INTO training_sessions (id, course_id, starts_at, ends_at) VALUES (?,?, '2026-11-05 09:00:00', '2026-11-05 09:00:00')")->execute([Db::uuid(), $cid]));
$fails('capacity 0 is refused', fn () => $pdo->prepare("INSERT INTO training_sessions (id, course_id, starts_at, ends_at, capacity) VALUES (?,?, '2026-11-05 09:00:00', '2026-11-05 10:00:00', 0)")->execute([Db::uuid(), $cid]));
$aid = Db::uuid();
$pdo->prepare('INSERT INTO session_attendees (id, session_id, person_id) VALUES (?,?,?)')->execute([$aid, $sid, $pid]);
$fails('a person is booked once per session', fn () => $pdo->prepare('INSERT INTO session_attendees (id, session_id, person_id) VALUES (?,?,?)')->execute([Db::uuid(), $sid, $pid]));
$fails('attendance outside the list is refused', fn () => $pdo->exec("UPDATE session_attendees SET attendance = 'present' WHERE id = '$aid'"));
$fails('result outside the list is refused', fn () => $pdo->exec("UPDATE session_attendees SET result = 'merit' WHERE id = '$aid'"));

// ---- certificates (008) ----
$cert = function (array $o = []) use ($pdo, $pid, $cid, $admin): string {
    $id = Db::uuid();
    $pdo->prepare("INSERT INTO certificates (id, code, learner_id, person_id, course_id, attendee_id, learner_name, course_title, completed_on, issued_on, expires_on, status, approved_at, approved_by, revoked_at, revoked_by, revoke_reason)
                   VALUES (?,?,?,?,?,?, 'Pat Jones', 'Basic Life Support', '2026-11-05', ?, ?, ?, CURRENT_TIMESTAMP(3), ?, ?, ?, ?)")
        ->execute([$id, $o['code'] ?? strtoupper(substr(str_replace('-', '', Db::uuid()), -12)), $o['learner'] ?? null, array_key_exists('person', $o) ? $o['person'] : $pid, $cid, $o['attendee'] ?? null,
            $o['issued'] ?? '2026-11-06', $o['expires'] ?? '2027-11-05', $o['status'] ?? 'approved', $admin,
            $o['revokedAt'] ?? null, $o['revokedBy'] ?? null, $o['reason'] ?? null]);
    return $id;
};
$ok('a CMS certificate needs no learner (user) id', fn () => $cert(['attendee' => $aid]));
$fails('one certificate per attendance', fn () => $cert(['attendee' => $aid]));
$fails('a certificate needs a holder: a person or a legacy learner', fn () => $cert(['person' => null]));
$fails('issue date cannot precede the completion date', fn () => $cert(['issued' => '2026-11-04']));
$fails('revoked_at on an approved certificate is refused', fn () => $cert(['revokedAt' => '2026-12-01 10:00:00', 'revokedBy' => $admin, 'reason' => 'Issued in error']));
$fails('a revocation needs who and why', fn () => $cert(['status' => 'withdrawn', 'revokedAt' => '2026-12-01 10:00:00']));
$ok('a full revocation is allowed', fn () => $cert(['status' => 'withdrawn', 'revokedAt' => '2026-12-01 10:00:00', 'revokedBy' => $admin, 'reason' => 'Issued in error']));
$legacy = make_user($pdo, 'legacy@example.com', 'learner');
$ok('the legacy shape (learner id, no person) still inserts, so running code keeps working', fn () => $cert(['learner' => $legacy, 'person' => null]));

// ---- office (009) ----
$fails('a note on an unknown subject type is refused', fn () => $pdo->prepare("INSERT INTO notes (id, subject_type, subject_id, body, author_id) VALUES (?, 'course', ?, 'x', ?)")->execute([Db::uuid(), $cid, $admin]));
$fails('a note needs a real author', fn () => $pdo->prepare("INSERT INTO notes (id, subject_type, subject_id, body, author_id) VALUES (?, 'person', ?, 'x', ?)")->execute([Db::uuid(), $pid, Db::uuid()]));
$fails('a done follow-up records who', fn () => $pdo->prepare("INSERT INTO follow_ups (id, subject_type, subject_id, title, due_on, created_by, done_at) VALUES (?, 'person', ?, 'Call', '2026-11-01', ?, CURRENT_TIMESTAMP(3))")->execute([Db::uuid(), $pid, $admin]));
$eid = Db::uuid();
$pdo->prepare("INSERT INTO enquiries (id, name, email, message) VALUES (?, 'Sam', 'sam@example.com', 'Hello there, a question')")->execute([$eid]);
foreach (['in_progress', 'closed', 'read', 'answered', 'spam', 'new'] as $st) {
    $ok("enquiry status $st is allowed", fn () => $pdo->exec("UPDATE enquiries SET status = '$st' WHERE id = '$eid'"));
}
$fails('enquiry status outside the list is refused', fn () => $pdo->exec("UPDATE enquiries SET status = 'archived' WHERE id = '$eid'"));

// ---- reminders and audit (010) ----
$c1 = $cert();
$pdo->prepare('INSERT INTO certificate_reminders (certificate_id, stage) VALUES (?, 60)')->execute([$c1]);
$fails('a reminder stage is recorded once per certificate', fn () => $pdo->prepare('INSERT INTO certificate_reminders (certificate_id, stage) VALUES (?, 60)')->execute([$c1]));
$fails('stages are 60, 30 and 7 only', fn () => $pdo->prepare('INSERT INTO certificate_reminders (certificate_id, stage) VALUES (?, 14)')->execute([$c1]));
$cols = array_column($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' ORDER BY ORDINAL_POSITION")->fetchAll(), 'COLUMN_NAME');
check('the audit log has no column for values or personal data', $cols === ['id', 'at', 'actor_id', 'action', 'entity_type', 'entity_id', 'fields'], implode(',', $cols));
check('migrations 006 to 010 are recorded', (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE name IN ('006-people.sql','007-courses-sessions.sql','008-certificates-cms.sql','009-office.sql','010-reminders-audit.sql')")->fetchColumn() === 5);
