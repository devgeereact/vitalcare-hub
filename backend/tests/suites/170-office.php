<?php

declare(strict_types=1);

// Follow-ups, the enquiries inbox (status, assignee, notes, reply by email), dashboard, search, staff picker.
use Vitalcare\Contact\ContactService;
use Vitalcare\Support\Db;

/** @var PDO $pdo */
$office = make_user($pdo, 'office@example.com', 'admin');
$owner = make_user($pdo, 'owner@example.com', 'super_admin');
$trainer = make_user($pdo, 'trainer@example.com', 'trainer');
$now = new DateTimeImmutable('2026-10-31 10:00:00', new DateTimeZone('UTC'));
$call = cms_api($pdo, fn () => $now);
$pat = make_person($pdo, ['first' => 'Pat', 'last' => 'Jones', 'email' => 'pat@example.org']);
$org = make_org($pdo, 'Oak House');

// ---- staff picker ----
[$s, $b] = $call('GET', '/api/admin/staff', 'office');
check('staff picker lists CMS staff only, with no emails', $s === 200 && count($b['items']) === 2 && !str_contains(json_encode($b), '@'));

// ---- follow-ups ----
$fu = fn (array $o) => $call('POST', '/api/admin/follow-ups', 'office', $o + ['subjectType' => 'person', 'subjectId' => $pat, 'title' => 'Book refresher', 'dueOn' => '2026-11-03']);
[$s, $b] = $fu(['assigneeId' => $owner]);
check('create a follow-up on a person', $s === 201 && $b['followUp']['subjectLabel'] === 'Pat Jones' && $b['followUp']['assigneeName'] === 'Test super_admin' && $b['followUp']['done'] === false, json_encode($b));
$f1 = $b['followUp']['id'];
check('an assignee must be CMS staff', isset($fu(['assigneeId' => $trainer])[1]['error']['fields']['assigneeId']));
check('the subject must exist', isset($fu(['subjectId' => Db::uuid()])[1]['error']['fields']['subjectId']));
check('the subject type must be known', isset($fu(['subjectType' => 'course'])[1]['error']['fields']['subjectType']));
check('a due date is required', isset($fu(['dueOn' => ''])[1]['error']['fields']['dueOn']));
[$s, $b] = $fu(['dueOn' => '2026-10-29', 'title' => 'Chase invoice', 'subjectType' => 'organisation', 'subjectId' => $org, 'assigneeId' => $office]);
check('an overdue follow-up on an organisation', $s === 201 && $b['followUp']['overdue'] === true && $b['followUp']['subjectLabel'] === 'Oak House');
$f2 = $b['followUp']['id'];
$fu(['dueOn' => '2026-10-31', 'title' => 'Call back today']);
[$s, $b] = $call('GET', '/api/admin/follow-ups', 'office');
check('open follow-ups, overdue first', $s === 200 && count($b['items']) === 3 && $b['items'][0]['id'] === $f2);
check('"mine" shows only those assigned to me', array_column($call('GET', '/api/admin/follow-ups', 'office', null, ['scope' => 'mine'])[1]['items'], 'id') === [$f2]);
check('filter by subject', count($call('GET', '/api/admin/follow-ups', 'office', null, ['subjectType' => 'organisation', 'subjectId' => $org])[1]['items']) === 1);
[$s, $b] = $call('PATCH', "/api/admin/follow-ups/$f2", 'office', ['done' => true]);
check('mark done records when', $s === 200 && $b['followUp']['done'] === true && $b['followUp']['doneAt'] !== null);
check('done_by is the signed-in user', $pdo->query("SELECT done_by FROM follow_ups WHERE id = '$f2'")->fetchColumn() === $office);
check('done items leave the open list', count($call('GET', '/api/admin/follow-ups', 'office')[1]['items']) === 2 && count($call('GET', '/api/admin/follow-ups', 'office', null, ['scope' => 'done'])[1]['items']) === 1);
check('reopen', $call('PATCH', "/api/admin/follow-ups/$f2", 'office', ['done' => false])[1]['followUp']['done'] === false);
check('reassign and re-date', ($x = $call('PATCH', "/api/admin/follow-ups/$f1", 'office', ['assigneeId' => null, 'dueOn' => '2026-11-10'])[1]['followUp'])['assigneeId'] === null && $x['dueOn'] === '2026-11-10');
check('a missing follow-up is 404', $call('PATCH', '/api/admin/follow-ups/' . Db::uuid(), 'office', ['done' => true])[0] === 404);

// ---- enquiries ----
(new ContactService($pdo, 'info@vitalcare.uk'))->store(['name' => 'Sam Visitor', 'email' => 'sam@visitor.example', 'phone' => null, 'message' => 'Do you run moving and handling for 8 staff?'], null);
(new ContactService($pdo, 'info@vitalcare.uk'))->store(['name' => 'Old Read', 'email' => 'old@visitor.example', 'phone' => null, 'message' => 'An older enquiry marked read'], null);
$pdo->exec("UPDATE enquiries SET status = 'read' WHERE name = 'Old Read'");
$pdo->exec("DELETE FROM outbox_email");
[$s, $b] = $call('GET', '/api/admin/enquiries', 'office', null, ['status' => 'new']);
check('new enquiries', $s === 200 && $b['total'] === 1 && $b['items'][0]['name'] === 'Sam Visitor' && $b['items'][0]['status'] === 'new');
$eid = $b['items'][0]['id'];
check('an old "read" row shows as in progress', $call('GET', '/api/admin/enquiries', 'office', null, ['status' => 'in_progress'])[1]['items'][0]['name'] === 'Old Read');
[$s, $b] = $call('PATCH', "/api/admin/enquiries/$eid", 'office', ['assigneeId' => $office]);
check('assign an enquiry', $s === 200 && $b['enquiry']['assigneeName'] === 'Test admin');
check('an enquiry status outside new/in_progress/closed is refused', $call('PATCH', "/api/admin/enquiries/$eid", 'office', ['status' => 'spam'])[0] === 422);
check('internal note', $call('POST', "/api/admin/enquiries/$eid/notes", 'office', ['body' => 'Probably a group booking'])[0] === 201);
check('no email was sent for a note', (int) $pdo->query('SELECT COUNT(*) FROM outbox_email')->fetchColumn() === 0);
usleep(3000); // history is ordered by time at millisecond resolution
[$s, $b] = $call('POST', "/api/admin/enquiries/$eid/reply", 'office', ['body' => "Hello Sam,\nYes, we can. Two dates are free in November."]);
check('reply is 201 and the enquiry moves to in progress', $s === 201 && $b['enquiry']['status'] === 'in_progress', json_encode($b));
$mail = $pdo->query('SELECT * FROM outbox_email')->fetchAll();
check('one email is queued to the enquirer, Reply-To the office', count($mail) === 1 && $mail[0]['to_email'] === 'sam@visitor.example' && $mail[0]['reply_to'] === 'info@vitalcare.uk');
check('the subject is fixed, the body is the reply plus the signature', $mail[0]['subject'] === 'Re: your enquiry to Vitalcare Training Hub' && str_starts_with($mail[0]['body_text'], "Hello Sam,\nYes, we can.") && str_contains($mail[0]['body_text'], 'Vitalcare Training Hub Ltd'));
check('the reply is kept in the history as a reply', array_column($b['notes'], 'kind') === ['reply', 'note']);
check('an empty reply is refused', $call('POST', "/api/admin/enquiries/$eid/reply", 'office', ['body' => ''])[0] === 422);
[$s, $b] = $call('PATCH', "/api/admin/enquiries/$eid", 'office', ['status' => 'closed']);
check('close records when', $s === 200 && $b['enquiry']['status'] === 'closed' && $b['enquiry']['closedAt'] !== null);
check('reopen clears it', $call('PATCH', "/api/admin/enquiries/$eid", 'office', ['status' => 'in_progress'])[1]['enquiry']['closedAt'] === null);
check('a reply to a missing enquiry is 404', $call('POST', '/api/admin/enquiries/' . Db::uuid() . '/reply', 'office', ['body' => 'Hello'])[0] === 404);
$fu(['subjectType' => 'enquiry', 'subjectId' => $eid, 'title' => 'Send dates', 'dueOn' => '2026-11-01']);
check('enquiry detail shows its follow-ups', count($call('GET', "/api/admin/enquiries/$eid", 'office')[1]['followUps']) === 1);

// ---- dashboard ----
(new ContactService($pdo, 'info@vitalcare.uk'))->store(['name' => 'New One', 'email' => 'new@visitor.example', 'phone' => null, 'message' => str_repeat('Long message. ', 40)], null);
$course = make_course($pdo);
$pdo->prepare("INSERT INTO training_sessions (id, course_id, starts_at, ends_at, venue) VALUES (?,?, '2026-11-04 09:00:00', '2026-11-04 12:00:00', 'Room'), (?,?, '2026-12-20 09:00:00', '2026-12-20 12:00:00', 'Room')")
    ->execute([Db::uuid(), $course, Db::uuid(), $course]);
[$s, $b] = $call('GET', '/api/admin/dashboard', 'office');
check('dashboard: follow-ups overdue and due today', $s === 200 && $b['followUps']['overdue'] === 1 && $b['followUps']['dueToday'] === 1, json_encode($b['followUps']));
check('dashboard: new enquiries with a short preview', $b['enquiries']['new'] === 1 && mb_strlen($b['enquiries']['items'][0]['message']) <= 160);
check('dashboard: sessions in the next 14 days only', count($b['sessions']['items']) === 1 && $b['sessions']['items'][0]['startsAt'] === '2026-11-04T09:00');
check('dashboard: today is the London date', $b['today'] === '2026-10-31');

// ---- search ----
check('search needs 2 characters', $call('GET', '/api/admin/search', 'office', null, ['q' => 'a'])[0] === 422);
[$s, $b] = $call('GET', '/api/admin/search', 'office', null, ['q' => 'oak']);
check('search finds organisations', $s === 200 && count($b['organisations']) === 1 && $b['people'] === []);
check('search finds people by email', count($call('GET', '/api/admin/search', 'office', null, ['q' => 'PAT@EXAMPLE'])[1]['people']) === 1);
check('search treats _ as a literal', $call('GET', '/api/admin/search', 'office', null, ['q' => '__'])[1]['people'] === []);
