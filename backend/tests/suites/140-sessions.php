<?php

declare(strict_types=1);

// Sessions and attendance: London times, venue or link, capacity, booking rules, results.
use Vitalcare\Cms\LondonTime;
use Vitalcare\Support\Db;

/** @var PDO $pdo */
make_user($pdo, 'office@example.com', 'admin');
$now = new DateTimeImmutable('2026-10-31 10:00:00', new DateTimeZone('UTC'));
$call = cms_api($pdo, fn () => $now);
$course = make_course($pdo);

// ---- London time conversions ----
check('winter: 09:30 London is 09:30 UTC', LondonTime::toUtc('2026-11-05T09:30') === '2026-11-05 09:30:00');
check('summer: 09:30 London is 08:30 UTC', LondonTime::toUtc('2026-07-05T09:30') === '2026-07-05 08:30:00');
check('a time skipped when the clocks go forward is refused', LondonTime::toUtc('2027-03-28T01:30') === null);
check('an impossible date is refused', LondonTime::toUtc('2026-02-30T09:00') === null && LondonTime::toUtc('2026-11-05 09:30') === null);
check('round trip', LondonTime::fromUtc('2026-07-05 08:30:00') === '2026-07-05T09:30');
check('the London date of 23:30 UTC in summer is the next day', LondonTime::dateOf('2026-06-30 23:30:00') === '2026-07-01');

// ---- sessions ----
$new = ['courseId' => $course, 'startsAt' => '2026-11-05T09:30', 'endsAt' => '2026-11-05T12:30', 'venue' => 'Lewisham office', 'trainerName' => 'A Trainer', 'capacity' => 2];
[$s, $b] = $call('POST', '/api/admin/sessions', 'office', $new);
check('create a session', $s === 201, json_encode($b));
$sid = $b['session']['id'];
check('times come back in London time', $b['session']['startsAt'] === '2026-11-05T09:30' && $b['session']['endsAt'] === '2026-11-05T12:30');
check('the session carries its course title and a booked count', $b['session']['courseTitle'] === 'Basic Life Support' && $b['session']['booked'] === 0);
[$s, $b] = $call('POST', '/api/admin/sessions', 'office', ['courseId' => $course, 'startsAt' => '2026-11-05T12:30', 'endsAt' => '2026-11-05T09:30']);
check('end before start, and no venue or link, are both refused', $s === 422 && isset($b['error']['fields']['endsAt'], $b['error']['fields']['venue']), json_encode($b));
[$s, $b] = $call('POST', '/api/admin/sessions', 'office', ['courseId' => $course, 'startsAt' => '2026-11-06T09:30', 'endsAt' => '2026-11-06T10:30', 'onlineLink' => 'http://insecure.example/meet']);
check('an online link must be https', $s === 422 && isset($b['error']['fields']['onlineLink']));
check('an online session with an https link is fine', $call('POST', '/api/admin/sessions', 'office', ['courseId' => $course, 'startsAt' => '2026-11-06T09:30', 'endsAt' => '2026-11-06T10:30', 'onlineLink' => 'https://meet.example/abc'])[0] === 201);
check('a session for a missing course is 422', $call('POST', '/api/admin/sessions', 'office', ['courseId' => Db::uuid()] + $new)[0] === 422);
[$s, $b] = $call('PATCH', "/api/admin/sessions/$sid", 'office', ['endsAt' => '2026-11-05T09:00']);
check('a partial update is checked against the stored start', $s === 422 && isset($b['error']['fields']['endsAt']));
check('capacity outside 1 to 500 is refused', $call('PATCH', "/api/admin/sessions/$sid", 'office', ['capacity' => 0])[0] === 422);
[$s, $b] = $call('GET', '/api/admin/sessions', 'office');
check('upcoming sessions are listed soonest first', $s === 200 && $b['total'] === 2 && $b['items'][0]['id'] === $sid);
$pdo->prepare("INSERT INTO training_sessions (id, course_id, starts_at, ends_at, venue) VALUES (?,?, '2026-09-01 09:00:00', '2026-09-01 12:00:00', 'Old room')")->execute([Db::uuid(), $course]);
check('past sessions are separate', $call('GET', '/api/admin/sessions', 'office', null, ['when' => 'past'])[1]['total'] === 1);

// ---- attendance ----
$a = make_person($pdo, ['first' => 'Ann', 'last' => 'Able']);
$b2 = make_person($pdo, ['first' => 'Bob', 'last' => 'Best']);
$c = make_person($pdo, ['first' => 'Cat', 'last' => 'Cole']);
$archived = make_person($pdo, ['first' => 'Old', 'last' => 'Record', 'status' => 'archived']);
check('book Ann', $call('POST', "/api/admin/sessions/$sid/attendees", 'office', ['personId' => $a])[0] === 201);
[$s, $b] = $call('POST', "/api/admin/sessions/$sid/attendees", 'office', ['personId' => $a]);
check('booking twice is 409 already_booked', $s === 409 && $b['error']['code'] === 'already_booked');
check('book Bob', $call('POST', "/api/admin/sessions/$sid/attendees", 'office', ['personId' => $b2])[0] === 201);
[$s, $b] = $call('POST', "/api/admin/sessions/$sid/attendees", 'office', ['personId' => $c]);
check('a full session is 409 full', $s === 409 && $b['error']['code'] === 'full');
check('an archived person cannot be booked', $call('POST', "/api/admin/sessions/$sid/attendees", 'office', ['personId' => $archived])[0] === 422);
check('booking on a missing session is 404', $call('POST', '/api/admin/sessions/' . Db::uuid() . '/attendees', 'office', ['personId' => $c])[0] === 404);

[$s, $b] = $call('GET', "/api/admin/sessions/$sid", 'office');
$att = array_column($b['attendees'], null, 'personName');
check('the session lists its attendees', count($b['attendees']) === 2 && $b['session']['booked'] === 2);
$bobId = $att['Bob Best']['id'];
[$s, $b] = $call('PATCH', "/api/admin/attendees/$bobId", 'office', ['attendance' => 'cancelled']);
check('a cancellation frees a place', $s === 200 && $b['session']['booked'] === 1);
check('Cat can now be booked', $call('POST', "/api/admin/sessions/$sid/attendees", 'office', ['personId' => $c])[0] === 201);
check('re-booking a cancelled attendee reuses the row once there is room', $call('PATCH', "/api/admin/sessions/$sid", 'office', ['capacity' => 3])[0] === 200
    && $call('POST', "/api/admin/sessions/$sid/attendees", 'office', ['personId' => $b2])[0] === 201
    && (int) $pdo->query("SELECT COUNT(*) FROM session_attendees WHERE person_id = '$b2'")->fetchColumn() === 1);

$annId = $att['Ann Able']['id'];
[$s, $b] = $call('PATCH', "/api/admin/attendees/$annId", 'office', ['result' => 'pass']);
check('a result needs attendance first', $s === 422 && isset($b['error']['fields']['result']));
[$s, $b] = $call('PATCH', "/api/admin/attendees/$annId", 'office', ['attendance' => 'attended', 'result' => 'pass']);
$ann = array_column($b['attendees'], null, 'personName')['Ann Able'];
check('mark attended with a pass', $s === 200 && $ann['attendance'] === 'attended' && $ann['result'] === 'pass');
check('marked_by is the signed-in user', $pdo->query("SELECT u.email FROM session_attendees a JOIN users u ON u.id = a.marked_by WHERE a.id = '$annId'")->fetchColumn() === 'office@example.com');
check('an unknown result is refused', $call('PATCH', "/api/admin/attendees/$annId", 'office', ['result' => 'merit'])[0] === 422);
check('a missing attendee is 404', $call('PATCH', '/api/admin/attendees/' . Db::uuid(), 'office', ['attendance' => 'attended'])[0] === 404);
check('a cancelled session takes no bookings', $call('PATCH', "/api/admin/sessions/$sid", 'office', ['status' => 'cancelled'])[0] === 200
    && $call('POST', "/api/admin/sessions/$sid/attendees", 'office', ['personId' => make_person($pdo, ['first' => 'Dan', 'last' => 'Dee'])])[1]['error']['code'] === 'cancelled');
