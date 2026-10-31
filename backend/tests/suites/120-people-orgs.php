<?php

declare(strict_types=1);

// People and organisations: validation, partial updates, audit with field names only, staff training status.
use Vitalcare\Support\Db;

/** @var PDO $pdo */
make_user($pdo, 'office@example.com', 'admin');
$now = new DateTimeImmutable('2026-10-31 10:00:00', new DateTimeZone('UTC'));
$call = cms_api($pdo, fn () => $now);

// ---- organisations ----
[$s, $b] = $call('POST', '/api/admin/organisations', 'office', ['name' => 'Oak House', 'kind' => 'care_home', 'contactEmail' => 'Manager@Oak.example', 'reminderCopy' => true]);
check('create organisation is 201', $s === 201, json_encode($b));
$org = $b['organisation']['id'];
check('email is normalised and booleans are booleans', $b['organisation']['contactEmail'] === 'manager@oak.example' && $b['organisation']['reminderCopy'] === true && $b['organisation']['status'] === 'active');
[$s, $b] = $call('POST', '/api/admin/organisations', 'office', ['kind' => 'hospital', 'contactEmail' => 'not-an-email', 'phone' => 'call me']);
check('bad organisation is 422 with a message per field', $s === 422 && isset($b['error']['fields']['name'], $b['error']['fields']['kind'], $b['error']['fields']['contactEmail'], $b['error']['fields']['phone']), json_encode($b));
check('a list body is refused', $call('POST', '/api/admin/organisations', 'office', '[1,2]')[0] === 422);
check('an empty body is refused', $call('POST', '/api/admin/organisations', 'office', '')[0] === 422);
check('a body over 64 KB is 413', $call('POST', '/api/admin/organisations', 'office', json_encode(['name' => str_repeat('a', 70000)]))[0] === 413);
[$s, $b] = $call('PATCH', "/api/admin/organisations/$org", 'office', ['phone' => '020 8059 8757']);
check('a partial update changes only what was sent', $s === 200 && $b['organisation']['phone'] === '020 8059 8757' && $b['organisation']['name'] === 'Oak House' && $b['organisation']['reminderCopy'] === true);
check('updating a missing organisation is 404', $call('PATCH', '/api/admin/organisations/' . Db::uuid(), 'office', ['phone' => '020 8059 8757'])[0] === 404);

// ---- people ----
[$s, $b] = $call('POST', '/api/admin/people', 'office', ['firstName' => ' Pat ', 'lastName' => 'Jones', 'email' => 'PAT@example.org', 'organisationId' => $org, 'jobTitle' => 'Carer']);
check('create person is 201', $s === 201, json_encode($b));
$pat = $b['person']['id'];
check('names are trimmed, email lowered, organisation named', $b['person']['firstName'] === 'Pat' && $b['person']['email'] === 'pat@example.org' && $b['person']['organisationName'] === 'Oak House');
check('defaults: active, reminders on', $b['person']['status'] === 'active' && $b['person']['remindersOptOut'] === false);
[$s, $b] = $call('POST', '/api/admin/people', 'office', ['firstName' => 'Sam', 'lastName' => 'Lee', 'organisationId' => Db::uuid()]);
check('a person pointing at a missing organisation is 422 on that field', $s === 422 && isset($b['error']['fields']['organisationId']));
[$s, $b] = $call('POST', '/api/admin/people', 'office', ['firstName' => "Sam\nX", 'lastName' => str_repeat('a', 101)]);
check('control characters and over-long names are refused', $s === 422 && isset($b['error']['fields']['firstName'], $b['error']['fields']['lastName']));
check('a person with no email is fine', $call('POST', '/api/admin/people', 'office', ['firstName' => 'Ann', 'lastName' => 'Bell'])[0] === 201);
[$s, $b] = $call('PATCH', "/api/admin/people/$pat", 'office', ['remindersOptOut' => true, 'role' => 'super_admin', 'createdBy' => Db::uuid()]);
check('unknown fields are ignored, never stored', $s === 200 && $b['person']['remindersOptOut'] === true && !array_key_exists('role', $b['person']));
check('a person cannot be turned into a user', (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'pat@example.org'")->fetchColumn() === 0);

[$s, $b] = $call('GET', '/api/admin/people', 'office', null, ['q' => 'jon']);
check('search by part of a name', $s === 200 && $b['total'] === 1 && $b['items'][0]['id'] === $pat);
[$s, $b] = $call('GET', '/api/admin/people', 'office', null, ['q' => '%']);
check('a % in the search is literal, not a wildcard', $s === 200 && $b['total'] === 0);
[$s, $b] = $call('GET', '/api/admin/people', 'office', null, ['organisationId' => $org]);
check('filter by organisation', $b['total'] === 1);

// ---- organisation training status ----
$course = make_course($pdo, ['title' => 'Moving and Handling']);
$course2 = make_course($pdo, ['title' => 'Fire Safety']);
$cert = function (string $person, string $courseId, string $title, ?string $expires, string $completed = '2025-11-01') use ($pdo) {
    $pdo->prepare("INSERT INTO certificates (id, code, person_id, course_id, learner_name, course_title, completed_on, expires_on, status, approved_at, approved_by)
                   VALUES (?,?,?,?, 'Pat Jones', ?, ?, ?, 'approved', CURRENT_TIMESTAMP(3), (SELECT id FROM users LIMIT 1))")
        ->execute([Db::uuid(), Vitalcare\Certificates\CertificateCode::generate(), $person, $courseId, $title, $completed, $expires]);
};
$cert($pat, $course, 'Moving and Handling', '2026-11-20');           // due within 30 days
$cert($pat, $course2, 'Fire Safety', '2027-06-01');                  // in date
$sam = make_person($pdo, ['first' => 'Sam', 'last' => 'Lee', 'org' => $org]);
$cert($sam, $course, 'Moving and Handling', '2026-09-01', '2025-09-01'); // expired
$cert($sam, $course, 'Moving and Handling', '2027-10-15', '2026-10-15'); // renewed: supersedes the expired one
$amy = make_person($pdo, ['first' => 'Amy', 'last' => 'Ash', 'org' => $org]);
$cert($amy, $course2, 'Fire Safety', '2026-10-01');                  // expired, not renewed
make_person($pdo, ['first' => 'Zed', 'last' => 'Zane', 'org' => $org]);
[$s, $b] = $call('GET', "/api/admin/organisations/$org", 'office');
$byName = array_column($b['staff'], null, 'name');
check('organisation detail lists its staff', $s === 200 && count($b['staff']) === 4, json_encode(array_keys($byName)));
check('worst state wins: Pat is due soon', $byName['Pat Jones']['summary'] === 'due_soon' && count($byName['Pat Jones']['certificates']) === 2);
check('a renewal supersedes the old certificate: Sam is in date with one current certificate', $byName['Sam Lee']['summary'] === 'in_date' && count($byName['Sam Lee']['certificates']) === 1);
check('Amy is expired', $byName['Amy Ash']['summary'] === 'expired');
check('Zed has no training', $byName['Zed Zane']['summary'] === 'none' && $byName['Zed Zane']['certificates'] === []);

// ---- notes ----
[$s, $b] = $call('POST', "/api/admin/people/$pat/notes", 'office', ['body' => "Called about refresher.\nWill book in November."]);
check('add a note keeps line breaks', $s === 201 && $b['items'][0]['body'] === "Called about refresher.\nWill book in November." && $b['items'][0]['authorName'] === 'Test admin');
usleep(3000); // notes are ordered by time at millisecond resolution
$call('POST', "/api/admin/people/$pat/notes", 'office', ['body' => 'Second note']);
[$s, $b] = $call('GET', "/api/admin/people/$pat", 'office');
check('person detail shows note history newest first', $s === 200 && count($b['notes']) === 2 && $b['notes'][0]['body'] === 'Second note');
check('person detail shows certificates', count($b['certificates']) === 2);
check('a note on a missing person is 404', $call('POST', '/api/admin/people/' . Db::uuid() . '/notes', 'office', ['body' => 'x'])[0] === 404);
check('an empty note is 422', $call('POST', "/api/admin/people/$pat/notes", 'office', ['body' => '   '])[0] === 422);
check('notes on an organisation work too', $call('POST', "/api/admin/organisations/$org/notes", 'office', ['body' => 'Contract renewed'])[0] === 201);

// ---- audit: names only ----
$log = $pdo->query("SELECT action, entity_type, fields FROM audit_log ORDER BY id")->fetchAll();
check('every write is audited', count($log) >= 7, (string) count($log));
check('the person.create entry names the fields', in_array(['action' => 'person.create', 'entity_type' => 'person', 'fields' => 'email,firstName,jobTitle,lastName,organisationId,phone,remindersOptOut,status'], $log, true), json_encode($log));
$all = json_encode($pdo->query('SELECT * FROM audit_log')->fetchAll());
foreach (['pat@example.org', 'Pat', 'Jones', 'Oak House', 'refresher', '020 8059', 'manager@oak'] as $pii) {
    check("the audit log does not contain \"$pii\"", !str_contains($all, $pii));
}
