<?php

declare(strict_types=1);

// Renewals view and reminder emails: stages 60/30/7, once per certificate and stage, never for renewed,
// revoked or opted-out records, optional copy to the organisation contact.
use Vitalcare\Certificates\CertificateCode;
use Vitalcare\Certificates\Renewals;
use Vitalcare\Support\Db;

/** @var PDO $pdo */
$staff = make_user($pdo, 'office@example.com', 'admin');
$today = '2026-10-31';
$now = new DateTimeImmutable("$today 10:00:00", new DateTimeZone('UTC'));
$at = fn (string $day) => new DateTimeImmutable("$day 10:00:00", new DateTimeZone('UTC'));
$call = cms_api($pdo, function () use (&$now) { return $now; });
$add = fn (int $days) => (new DateTimeImmutable($today))->modify("+$days days")->format('Y-m-d');

$course = make_course($pdo, ['title' => 'Fire Safety']);
$other = make_course($pdo, ['title' => 'First Aid']);
$cert = function (string $person, ?string $expires, array $o = []) use ($pdo, $course, $staff): string {
    $id = Db::uuid();
    $pdo->prepare("INSERT INTO certificates (id, code, person_id, course_id, learner_name, course_title, completed_on, expires_on, status, approved_at, approved_by, revoked_at, revoked_by, revoke_reason)
                   VALUES (?,?,?,?,?, 'Fire Safety', ?, ?, ?, CURRENT_TIMESTAMP(3), ?, ?, ?, ?)")
        ->execute([$id, CertificateCode::generate(), $person, $o['course'] ?? $course, $o['name'] ?? 'Pat Jones', $o['completed'] ?? '2025-11-01', $expires,
            $o['status'] ?? 'approved', $staff, $o['revoked'] ?? null, isset($o['revoked']) ? $staff : null, isset($o['revoked']) ? 'Error' : null]);
    return $id;
};

// ---- stage choice ----
foreach ([[61, null], [60, 60], [31, 60], [30, 30], [8, 30], [7, 7], [0, 7], [-1, null]] as [$days, $stage]) {
    check("stage for $days days left is " . json_encode($stage), Renewals::stageFor($days) === $stage);
}

// ---- people and certificates ----
$org = make_org($pdo, 'Oak House');
$pdo->exec("UPDATE organisations SET contact_email = 'manager@oak.example', reminder_copy = 1 WHERE id = '$org'");
$pat = make_person($pdo, ['first' => 'Pat', 'last' => 'Jones', 'email' => 'pat@example.org', 'org' => $org]);
$c60 = $cert($pat, $add(60));                                        // exactly 60 days: stage 60, to Pat and the organisation
$sam = make_person($pdo, ['first' => 'Sam', 'last' => 'Lee', 'email' => 'sam@example.org']);
$c20 = $cert($sam, $add(20), ['name' => 'Sam Lee']);                 // first seen at 20 days: stage 30 only
$noEmail = make_person($pdo, ['first' => 'Nia', 'last' => 'Noemail', 'email' => null]);
$cNo = $cert($noEmail, $add(5), ['name' => 'Nia Noemail']);           // stage 7, nobody to email: recorded, 0 recipients
$opt = make_person($pdo, ['first' => 'Olu', 'last' => 'Optout', 'email' => 'olu@example.org', 'optOut' => 1]);
$cert($opt, $add(10), ['name' => 'Olu Optout']);
$ren = make_person($pdo, ['first' => 'Rae', 'last' => 'Renewed', 'email' => 'rae@example.org']);
$cert($ren, $add(15), ['name' => 'Rae Renewed']);                     // superseded by the renewal below
$cert($ren, $add(380), ['name' => 'Rae Renewed', 'completed' => '2026-10-20']);
$rev = make_person($pdo, ['first' => 'Rex', 'last' => 'Revoked', 'email' => 'rex@example.org']);
$cert($rev, $add(10), ['name' => 'Rex Revoked', 'status' => 'withdrawn', 'revoked' => '2026-10-01 10:00:00']);
$far = make_person($pdo, ['first' => 'Fay', 'last' => 'Far', 'email' => 'fay@example.org']);
$cert($far, $add(61), ['name' => 'Fay Far']);
$cert($far, null, ['name' => 'Fay Far', 'course' => $other]);         // no expiry: never reminded
$gone = make_person($pdo, ['first' => 'Gus', 'last' => 'Gone', 'email' => 'gus@example.org']);
$cert($gone, $add(-1), ['name' => 'Gus Gone']);                       // already expired: no reminder

$r = (new Renewals($pdo, $now))->queueReminders('info@vitalcare.uk');
$out = $pdo->query('SELECT to_email, reply_to, subject, body_text FROM outbox_email ORDER BY to_email')->fetchAll();
$to = array_column($out, 'to_email');
check('first run: Pat, the organisation copy, Sam, and the opted-out person handled', $r['certificates'] === 4 && $r['emails'] === 3, json_encode($r));
check('emails go to the right people', $to === ['manager@oak.example', 'pat@example.org', 'sam@example.org'], json_encode($to));
check('replies go to the office', count(array_filter($out, fn ($m) => $m['reply_to'] === 'info@vitalcare.uk')) === 3);
$byTo = array_column($out, null, 'to_email');
check('the person email names the course, the code and the date', str_contains($byTo['pat@example.org']['subject'], 'Fire Safety') && str_contains($byTo['pat@example.org']['body_text'], '30 December 2026') && preg_match('/[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}/', $byTo['pat@example.org']['body_text']) === 1);
check('the organisation email names the person', str_contains($byTo['manager@oak.example']['subject'], 'Pat Jones'));
$stages = $pdo->query('SELECT certificate_id, stage, recipients FROM certificate_reminders')->fetchAll();
$byCert = array_column($stages, null, 'certificate_id');
check('60 days left records stage 60 with 2 recipients', (int) $byCert[$c60]['stage'] === 60 && (int) $byCert[$c60]['recipients'] === 2);
check('20 days left records stage 30 (no stale 60)', (int) $byCert[$c20]['stage'] === 30 && !in_array(['certificate_id' => $c20, 'stage' => 60], array_map(fn ($x) => ['certificate_id' => $x['certificate_id'], 'stage' => (int) $x['stage']], $stages), true));
check('no email address: stage recorded with 0 recipients, so it is not retried', (int) $byCert[$cNo]['stage'] === 7 && (int) $byCert[$cNo]['recipients'] === 0);
check('the opted-out person gets nothing', !in_array('olu@example.org', $to, true));
check('a renewed certificate gets nothing', !in_array('rae@example.org', $to, true));
check('a revoked certificate gets nothing', !in_array('rex@example.org', $to, true));
check('61 days, no expiry and already expired get nothing', !in_array('fay@example.org', $to, true) && !in_array('gus@example.org', $to, true));

// Idempotency: the same day again, and many runs, queue nothing new.
for ($i = 0; $i < 3; $i++) {
    $again = (new Renewals($pdo, $now))->queueReminders('info@vitalcare.uk');
    check("re-run $i queues nothing", $again['certificates'] === 0 && $again['emails'] === 0);
}
check('still 3 emails in the outbox', (int) $pdo->query('SELECT COUNT(*) FROM outbox_email')->fetchColumn() === 3);

// Time passes: Pat reaches 30 days, then 7; each stage once.
$r = (new Renewals($pdo, $at($add(30))))->queueReminders('info@vitalcare.uk');
check('at 30 days left Pat gets the 30-day reminder (and the organisation copy)', $r['emails'] >= 2 && (int) $pdo->query("SELECT COUNT(*) FROM certificate_reminders WHERE certificate_id = '$c60' AND stage = 30")->fetchColumn() === 1);
(new Renewals($pdo, $at($add(31))))->queueReminders('info@vitalcare.uk');
check('the next day nothing more for Pat', (int) $pdo->query("SELECT COUNT(*) FROM certificate_reminders WHERE certificate_id = '$c60'")->fetchColumn() === 2);
(new Renewals($pdo, $at($add(53))))->queueReminders('info@vitalcare.uk');
check('at 7 days left the 7-day reminder', (int) $pdo->query("SELECT COUNT(*) FROM certificate_reminders WHERE certificate_id = '$c60' AND stage = 7")->fetchColumn() === 1);
(new Renewals($pdo, $at($add(60))))->queueReminders('info@vitalcare.uk');
(new Renewals($pdo, $at($add(61))))->queueReminders('info@vitalcare.uk');
check('three stages in total for one certificate, never more', (int) $pdo->query("SELECT COUNT(*) FROM certificate_reminders WHERE certificate_id = '$c60'")->fetchColumn() === 3);
check('Pat received exactly three reminders', (int) $pdo->query("SELECT COUNT(*) FROM outbox_email WHERE to_email = 'pat@example.org'")->fetchColumn() === 3);

// Organisation copy off, organisation disabled, or contact = person: no copy.
$pdo->exec("UPDATE organisations SET reminder_copy = 0 WHERE id = '$org'");
$p2 = make_person($pdo, ['first' => 'Ola', 'last' => 'Two', 'email' => 'ola@example.org', 'org' => $org]);
$cert($p2, $add(50), ['name' => 'Ola Two']);
(new Renewals($pdo, $now))->queueReminders('info@vitalcare.uk');
check('with reminder copy off the organisation is not emailed', (int) $pdo->query("SELECT COUNT(*) FROM outbox_email WHERE subject LIKE '%Ola Two%'")->fetchColumn() === 0);

// Concurrency: a second scheduler while the lock is held does nothing.
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
global $port, $name, $user, $pass;
$other = Vitalcare\Support\Db::connect(['dsn' => "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", 'user' => $user, 'password' => $pass]);
$other->query("SELECT GET_LOCK('vitalcare_reminders', 0)");
check('a run while another holds the lock is skipped', (new Renewals($pdo, $now))->queueReminders('info@vitalcare.uk')['skipped'] === true);
$other->query("SELECT RELEASE_LOCK('vitalcare_reminders')");

// ---- the renewals view ----
$now = new DateTimeImmutable("$today 10:00:00", new DateTimeZone('UTC'));
[$s, $b] = $call('GET', '/api/admin/renewals', 'office', null, ['window' => '30']);
$names = array_column($b['items'], 'personName');
check('30-day window: soonest first, current certificates only', $s === 200 && $names === ['Nia Noemail', 'Olu Optout', 'Sam Lee'], json_encode($names));
check('the view shows which reminders were sent', array_column($b['items'], 'remindersSent', 'personName')['Sam Lee'] === [30]);
check('the view shows days left', array_column($b['items'], 'daysLeft', 'personName')['Sam Lee'] === 20);
$n60 = array_column($call('GET', '/api/admin/renewals', 'office', null, ['window' => '60'])[1]['items'], 'personName');
check('60-day window adds Ola and Pat', in_array('Pat Jones', $n60, true) && in_array('Ola Two', $n60, true) && count($n60) === 5);
check('90-day window adds the 61-day one', count($call('GET', '/api/admin/renewals', 'office', null, ['window' => '90'])[1]['items']) === 6);
$exp = array_column($call('GET', '/api/admin/renewals', 'office', null, ['window' => 'expired'])[1]['items'], 'personName');
check('expired window shows the lapsed, not the renewed or revoked', $exp === ['Gus Gone'], json_encode($exp));
check('an unknown window is 422', $call('GET', '/api/admin/renewals', 'office', null, ['window' => '45'])[0] === 422);
[$s, $b] = $call('GET', '/api/admin/dashboard', 'office');
check('dashboard counts current certificates expiring in 30 days and expired in the last 90', $s === 200 && $b['certificates']['expiring30'] === 3 && $b['certificates']['expired90'] === 1, json_encode($b['certificates']));
