<?php

declare(strict_types=1);

// Courses: owner-only writes, the CQC wording guard, publishing, and the public pages that read them.
use Vitalcare\Cms\Specs;
use Vitalcare\Support\Db;

/** @var PDO $pdo */
make_user($pdo, 'office@example.com', 'admin');
make_user($pdo, 'owner@example.com', 'super_admin');
$t = 1_793_000_000;
$call = cms_api($pdo, function () use (&$t) { return (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('UTC')); });

$course = [
    'slug' => 'basic-life-support', 'title' => 'Basic Life Support', 'summary' => "Adult basic life support.\nIncludes the recovery position.",
    'durationText' => 'Half a day', 'hours' => 3.5, 'format' => 'in_person', 'pricePence' => 4500, 'priceNote' => 'per person',
    'assessmentMethod' => 'Practical observation', 'renewalMonths' => 12, 'accreditationNote' => '', 'published' => false,
];
[$s, $b] = $call('POST', '/api/admin/courses', 'owner', $course);
check('owner creates a course', $s === 201, json_encode($b));
$id = $b['course']['id'];
check('a draft has no published time', $b['course']['published'] === false && $b['course']['publishedAt'] === null);
check('numbers come back as numbers', $b['course']['hours'] === 3.5 && $b['course']['pricePence'] === 4500 && $b['course']['renewalMonths'] === 12);
check('admin cannot create a course (owner statements)', $call('POST', '/api/admin/courses', 'office', $course + ['slug' => 'other'])[0] === 403);
check('admin cannot edit a course', $call('PATCH', "/api/admin/courses/$id", 'office', ['title' => 'Changed'])[0] === 403);
check('admin can read courses', $call('GET', "/api/admin/courses/$id", 'office')[0] === 200);
[$s, $b] = $call('POST', '/api/admin/courses', 'owner', ['slug' => 'basic-life-support', 'title' => 'Dup', 'summary' => 'x']);
check('a used slug is refused on that field', $s === 422 && isset($b['error']['fields']['slug']));
[$s, $b] = $call('POST', '/api/admin/courses', 'owner', ['slug' => 'Bad Slug', 'title' => '', 'summary' => '', 'renewalMonths' => 0, 'hours' => -1, 'format' => 'video', 'pricePence' => -5]);
check('every bad field has its own message', $s === 422 && count(array_intersect(['slug', 'title', 'summary', 'renewalMonths', 'hours', 'format', 'pricePence'], array_keys($b['error']['fields']))) === 7, json_encode($b));

// CQC wording guard (D-018 item 9).
foreach (['CQC approved training', 'This course is CQC-compliant.', 'Endorsed by the CQC', 'Care Quality Commission accredited', 'cqc ready', 'Approved by Care Quality Commission'] as $bad) {
    [$s, $b] = $call('PATCH', "/api/admin/courses/$id", 'owner', ['summary' => $bad]);
    check('refused: ' . $bad, $s === 422 && isset($b['error']['fields']['summary']));
}
check('the guard covers the accreditation note too', $call('PATCH', "/api/admin/courses/$id", 'owner', ['accreditationNote' => 'CQC registered provider'])[0] === 422);
foreach (['Helps you meet the expectations an inspector may look for.', 'Covers the topics in the Care Certificate standard 12.'] as $fine) {
    check('allowed: ' . $fine, !Specs::cqcProblem($fine));
}

// Publishing.
check('nothing is public before publishing', $call('GET', '/api/courses')[1] === ['courses' => []]);
check('a draft course page is 404', $call('GET', '/api/courses/basic-life-support')[0] === 404);
[$s, $b] = $call('PATCH', "/api/admin/courses/$id", 'owner', ['published' => true]);
check('publishing sets published_at', $s === 200 && $b['course']['published'] === true && $b['course']['publishedAt'] !== null);
$first = $b['course']['publishedAt'];
$t += 3600;
$call('PATCH', "/api/admin/courses/$id", 'owner', ['title' => 'Basic Life Support (adult)']);
check('editing a published course keeps its first published time', $call('GET', "/api/admin/courses/$id", 'owner')[1]['course']['publishedAt'] === $first);

[$s, $b] = $call('GET', '/api/courses');
check('the public list shows the published course', $s === 200 && count($b['courses']) === 1);
$pub = $b['courses'][0];
check('the public shape is the owner facts only, in a fixed order', array_keys($pub) === Specs::PUBLIC_COURSE, json_encode(array_keys($pub)));
check('no id, draft flag or timestamps in public', !isset($pub['id']) && !isset($pub['published']) && !isset($pub['publishedAt']) && !isset($pub['sortOrder']));
check('the copy is what the owner entered (database, not code)', $pub['title'] === 'Basic Life Support (adult)' && $pub['summary'] === $course['summary']);
[$s, $b] = $call('GET', '/api/courses/basic-life-support');
check('the public course page answers by slug', $s === 200 && $b['course']['slug'] === 'basic-life-support');
check('an unknown slug is the same 404', $call('GET', '/api/courses/no-such-course')[1] === $call('GET', '/api/courses/basic-life-support-x')[1]);
check('a hostile slug is 404', $call('GET', '/api/courses/' . rawurlencode("' OR 1=1 --"))[0] === 404);
check('POST to the public list is 405', $call('POST', '/api/courses')[0] === 405);
make_course($pdo, ['slug' => 'hidden-draft', 'published' => 0]);
check('other drafts stay hidden', count($call('GET', '/api/courses')[1]['courses']) === 1 && $call('GET', '/api/courses/hidden-draft')[0] === 404);
$call('PATCH', "/api/admin/courses/$id", 'owner', ['published' => false]);
check('unpublishing hides it again', $call('GET', '/api/courses/basic-life-support')[0] === 404);

// Rate limit on the public endpoints: 120 per 5 minutes per caller.
$t = intdiv($t, 300) * 300 + 300;
$last = null;
for ($i = 0; $i < 120; $i++) {
    $last = $call('GET', '/api/courses', null, null, [], '198.51.100.40');
}
check('120 public reads are allowed', $last[0] === 200);
[$s, $b, $h] = $call('GET', '/api/courses/basic-life-support', null, null, [], '198.51.100.40');
check('the 121st is 429 with Retry-After', $s === 429 && (int) ($h['Retry-After'] ?? 0) > 0);
check('another caller is not limited', $call('GET', '/api/courses', null, null, [], '198.51.100.41')[0] === 200);
check('no database is 503', (new Vitalcare\Http\Api(fn () => [], null))->handle('GET', '/api/courses', null)[0] === 503);
check('course audit entries carry field names only', !str_contains(json_encode($pdo->query('SELECT * FROM audit_log')->fetchAll()), 'Life Support'));
