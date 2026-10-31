<?php

declare(strict_types=1);

namespace Vitalcare\Support;

use Vitalcare\Certificates\CertificateIssuer;
use Vitalcare\Certificates\Expiry;
use Vitalcare\Cms\Audit;
use Vitalcare\Cms\LondonTime;

/**
 * LOCAL DEMO DATA ONLY (bin/seed-demo.php). Fills an empty local database with made-up care homes, people,
 * courses, sessions, certificates, notes, follow-ups and enquiries so the CMS can be tried without real data.
 *
 * It refuses (refusal()) unless the database is on this machine, its name contains "demo", "test" or "local",
 * and it is empty, or --reset was given (which first deletes every row, schema kept). Every name, address and
 * email address here is invented; email addresses use example.org, phone numbers the Ofcom drama range.
 * Course text says DEMO and claims no accreditation. Never run against a real database.
 */
final class DemoSeed
{
    /** Tables whose rows make a database "not empty". */
    private const DATA_TABLES = ['users', 'organisations', 'people', 'courses', 'training_sessions', 'certificates', 'enquiries', 'notes', 'follow_ups', 'audit_log'];

    public const OWNER_EMAIL = 'owner+clerk_test@example.com';
    public const OFFICE_EMAIL = 'office+clerk_test@example.com';

    /**
     * Why seeding must not happen, or null when it may. Pure, so it is tested directly.
     *
     * @param string $host the database host from the DSN
     * @param ?int $port the port from the DSN, if any
     */
    public static function refusal(string $host, ?int $port, string $database, bool $empty, bool $reset): ?string
    {
        if (!in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true)) {
            return 'Refusing: the database must be on this machine (127.0.0.1 or localhost).';
        }
        if ($port === 3308) {
            return 'Refusing: port 3308 is never a demo database.';
        }
        if (preg_match('/(demo|test|local)/i', $database) !== 1) {
            return 'Refusing: the database name must contain "demo", "test" or "local".';
        }
        if (!$empty && !$reset) {
            return 'Refusing: the database already has data. Use --reset to wipe this demo database and seed again.';
        }
        return null;
    }

    /** @return array{host:string,port:?int} */
    public static function dsnHost(string $dsn): array
    {
        $host = preg_match('/(?:^|[:;])host=([^;]+)/', $dsn, $m) === 1 ? trim($m[1]) : '';
        $port = preg_match('/(?:^|[:;])port=(\d+)/', $dsn, $p) === 1 ? (int) $p[1] : null;
        return ['host' => $host, 'port' => $port];
    }

    public static function isEmpty(\PDO $pdo): bool
    {
        foreach (self::DATA_TABLES as $t) {
            if ((int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() > 0) {
                return false;
            }
        }
        return true;
    }

    /** Deletes every row in every table except schema_migrations. The caller has already passed refusal(). */
    public static function wipe(\PDO $pdo): void
    {
        $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $t) {
                if ($t !== 'schema_migrations' && preg_match('/^[a-z_]+$/', (string) $t) === 1) {
                    $pdo->exec("DELETE FROM `$t`");
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private string $today;
    private string $owner = '';
    private string $office = '';

    public function __construct(private readonly \PDO $pdo, private readonly \DateTimeImmutable $now)
    {
        $this->today = LondonTime::today($now);
    }

    /** @return array<string,int> counts of what was created */
    public function run(): array
    {
        $this->owner = $this->user(self::OWNER_EMAIL, 'Demo Owner', 'super_admin');
        $this->office = $this->user(self::OFFICE_EMAIL, 'Demo Office', 'admin');
        Audit::log($this->pdo, $this->owner, 'user.create', 'user', $this->office, ['displayName', 'email', 'role']);

        $orgs = [
            $this->org('Willow Court Care Home (demo)', 'Amara Okafor', 'amara.okafor@example.org', '01632 960101', '1 Example Lane, Demoton, DE1 0AA', true),
            $this->org('Riverside House Nursing Home (demo)', 'Graham Pike', 'graham.pike@example.org', '01632 960202', '22 River Walk, Demoton, DE2 0BB', false),
            $this->org('Elm Grove Residential Care (demo)', 'Siân Hughes', 'sian.hughes@example.org', '01632 960303', '7 Elm Grove, Sampleford, SF3 0CC', true),
        ];

        // [first, last, organisation index or null, job title, email (null = none), opt out, status]
        $peopleSpec = [
            ['Łukasz', 'Kowalski', 0, 'Care Assistant', 'lukasz.kowalski@example.org', false, 'active'],
            ['Ọlá', 'Adébáyọ̀', 0, 'Senior Carer', 'ola.adebayo@example.org', false, 'active'],
            ['Thị Lan', 'Nguyễn', 0, 'Care Assistant', 'lan.nguyen@example.org', false, 'active'],
            ['Siobhán', "O'Connor", 0, 'Deputy Manager', 'siobhan.oconnor@example.org', false, 'active'],
            ['Grace', 'Thompson', 0, 'Night Carer', null, false, 'active'],
            ['Samuel', 'Osei', 0, 'Care Assistant', 'samuel.osei@example.org', false, 'active'],
            ['Priya', 'Patel', 1, 'Registered Nurse', 'priya.patel@example.org', false, 'active'],
            ['Kwame', 'Mensah', 1, 'Healthcare Assistant', 'kwame.mensah@example.org', false, 'active'],
            ['José', 'Álvarez', 1, 'Healthcare Assistant', 'jose.alvarez@example.org', false, 'active'],
            ['Chloé', 'Dubois', 1, 'Activities Coordinator', 'chloe.dubois@example.org', true, 'active'],
            ['Mehmet', 'Yılmaz', 1, 'Kitchen Assistant', 'mehmet.yilmaz@example.org', false, 'active'],
            ['Fatima', 'Al-Sayed', 1, 'Registered Nurse', 'fatima.alsayed@example.org', false, 'active'],
            ['Aoife', 'Ní Bhriain', 2, 'Senior Carer', 'aoife.nibhriain@example.org', false, 'active'],
            ['Björn', 'Lindqvist', 2, 'Maintenance', null, false, 'active'],
            ['Anja', 'Müller', 2, 'Care Assistant', 'anja.muller@example.org', false, 'active'],
            ['Tomasz', 'Wiśniewski', 2, 'Care Assistant', 'tomasz.wisniewski@example.org', false, 'active'],
            ['Hannah', 'Clarke', 2, 'Team Leader', 'hannah.clarke@example.org', false, 'active'],
            ['Daniel', 'Evans', 2, 'Care Assistant', 'daniel.evans@example.org', false, 'active'],
            ['Maria', 'Santos', 2, 'Domestic', 'maria.santos@example.org', false, 'archived'],
            ['Rhys', 'Llewellyn', null, 'Agency Carer', 'rhys.llewellyn@example.org', false, 'active'],
            ['Zoë', 'Brontë-Smith', null, 'Personal Assistant', 'zoe.bronte-smith@example.org', false, 'active'],
            ['Ivana', 'Horvat', null, 'Support Worker', 'ivana.horvat@example.org', false, 'active'],
            ['Олена', 'Шевченко', null, 'Support Worker', 'olena.shevchenko@example.org', false, 'active'],
            ['Emily', 'Watson', null, 'Student Nurse', null, false, 'active'],
            ['Oliver', 'Bennett', null, 'Volunteer', 'oliver.bennett@example.org', false, 'active'],
        ];
        $p = [];
        foreach ($peopleSpec as [$first, $last, $orgIx, $job, $email, $optOut, $status]) {
            $p[$first] = $this->person($first, $last, $orgIx === null ? null : $orgs[$orgIx], $job, $email, $optOut, $status);
        }

        $note = 'DEMO data for local testing only. No accreditation is claimed for this course.';
        $bls = $this->course('basic-life-support-demo', 'Basic Life Support (demo)', 'DEMO course for local testing. Adult basic life support and use of an automated external defibrillator, practised on manikins in small groups.', 'Half day (3 hours)', 3.0, 'in_person', 4500, 'Per person, demo price', 'DEMO: practical demonstration and a short written check', 12, $note, true, 10);
        $mh = $this->course('moving-and-handling-demo', 'Moving and Handling People (demo)', 'DEMO course for local testing. Safe moving and handling of people, using slide sheets, hoists and standing aids, with a risk assessment exercise.', 'One day (6 hours)', 6.0, 'in_person', 6500, 'Per person, demo price', 'DEMO: observed practical tasks', 12, $note, true, 20);
        $sg = $this->course('safeguarding-adults-demo', 'Safeguarding Adults (demo)', 'DEMO course for local testing. Recognising and reporting abuse and neglect, and what to do when someone tells you something worrying.', '2.5 hours, live online', 2.5, 'online', 3000, 'Per person, demo price', 'DEMO: scenario discussion and a short quiz', 24, $note, true, 30);
        $ipc = $this->course('infection-prevention-demo', 'Infection Prevention and Control (demo)', 'DEMO course for local testing. Hand hygiene, personal protective equipment and cleaning routines, with a short online module before the classroom part.', '2 hours', 2.0, 'blended', 2500, 'Per person, demo price', 'DEMO: short written check', 12, $note, true, 40);
        $med = $this->course('medication-awareness-demo', 'Medication Awareness (demo)', 'DEMO course for local testing. The basics of supporting people with their medicines: records, storage, and when to ask for help.', 'Half day (4 hours)', 4.0, 'in_person', 5500, 'Per person, demo price', 'DEMO: workbook', null, $note, true, 50);
        $this->course('dementia-awareness-demo', 'Dementia Awareness (demo draft)', 'DEMO draft, not published. Placeholder text to show how an unpublished course looks in the office CMS.', 'Half day', 3.5, 'in_person', null, '', '', 12, $note, false, 60);

        $issuer = new CertificateIssuer($this->pdo, $this->now);
        $certs = 0;
        // Past sessions whose dates are chosen so the certificates land in each renewal state.
        $past = [
            // [course, renewal months, days until expiry (negative = already expired), trainer, [[person, attendance, result]]]
            [$bls, 12, -30, [['Łukasz', 'attended', 'pass'], ['Grace', 'attended', 'pass']]],
            [$bls, 12, 20, [['Ọlá', 'attended', 'pass'], ['Priya', 'attended', 'pass']]],
            [$bls, 12, 45, [['Thị Lan', 'attended', 'pass'], ['Kwame', 'no_show', null]]],
            [$mh, 12, 80, [['Siobhán', 'attended', 'pass'], ['José', 'attended', 'fail'], ['Chloé', 'attended', 'pass']]],
        ];
        foreach ($past as [$course, $months, $untilExpiry, $who]) {
            $completed = self::completedFor(LondonTime::addDays($this->today, $untilExpiry), $months);
            $sid = $this->session($course, $completed . 'T09:30', 3, 'completed', 'Training room 1, Demoton (demo)');
            foreach ($who as [$name, $att, $res]) {
                $aid = $this->attendee($sid, $p[$name], $att, $res);
                if ($att === 'attended' && $res !== 'fail') {
                    $issuer->fromAttendance($aid, $this->owner);
                    $certs++;
                }
            }
        }
        // Recent sessions: valid certificates, one with no expiry date (no renewal period).
        $recent = [
            [$sg, -20, [['Mehmet', 'attended', 'pass'], ['Aoife', 'attended', 'not_assessed'], ['Fatima', 'attended', 'pass'], ['Björn', 'attended', 'pass']]],
            [$med, -40, [['Anja', 'attended', 'pass'], ['Tomasz', 'attended', 'pass']]],
            [$ipc, -60, [['Hannah', 'attended', 'pass'], ['Daniel', 'attended', 'pass']]],
        ];
        $revokeMe = null;
        foreach ($recent as [$course, $daysAgo, $who]) {
            $sid = $this->session($course, LondonTime::addDays($this->today, $daysAgo) . 'T13:00', 2, 'completed', 'Elm Grove Residential Care, lounge (demo)');
            foreach ($who as [$name, $att, $res]) {
                $cid = $issuer->fromAttendance($this->attendee($sid, $p[$name], $att, $res), $this->owner);
                $certs++;
                if ($name === 'Hannah') {
                    $revokeMe = $cid;
                }
            }
        }
        if ($revokeMe !== null) {
            $issuer->revoke($revokeMe, 'DEMO: issued in error against the wrong course record; reissue after checking the register.', $this->owner);
        }
        // By hand, with a reason (owner only in the CMS).
        $issuer->manual($p['Rhys'], $bls, LondonTime::addDays($this->today, -100),
            'DEMO: training delivered at an employer site before the CMS was in use; attendance sheet checked.', $this->owner);
        $certs++;

        // Upcoming sessions (the Today page shows the next 14 days).
        $s1 = $this->session($bls, LondonTime::addDays($this->today, 3) . 'T09:30', 3, 'scheduled', 'Willow Court Care Home, training room (demo)', 10);
        foreach (['Łukasz', 'Grace', 'Samuel'] as $n) {
            $this->attendee($s1, $p[$n], 'booked', null);
        }
        $s2 = $this->session($mh, LondonTime::addDays($this->today, 9) . 'T09:00', 6, 'scheduled', 'Training room 1, Demoton (demo)', 8);
        foreach (['Ivana', 'Олена', 'Zoë'] as $n) {
            $this->attendee($s2, $p[$n], 'booked', null);
        }
        $s3 = $this->session($sg, LondonTime::addDays($this->today, 21) . 'T18:00', 2.5, 'scheduled', null, 20, 'https://example.org/demo-online-room');
        foreach (['Emily', 'Oliver'] as $n) {
            $this->attendee($s3, $p[$n], 'booked', null);
        }
        $this->session($ipc, LondonTime::addDays($this->today, 12) . 'T10:00', 2, 'cancelled', 'Riverside House Nursing Home (demo)');

        // Enquiries in each status.
        $e1 = $this->enquiry('Marta Nowak (demo)', 'marta.nowak@example.org', '01632 960404', 'Hello, do you run Basic Life Support at our home for 8 staff? Evenings suit us best. Dziękuję!', 'new', null, -1);
        $this->enquiry('Kofi Boateng (demo)', 'kofi.boateng@example.org', null, 'Could you send prices for moving and handling refreshers for 12 people next month?', 'new', null, -0.2);
        $e3 = $this->enquiry('Đặng Minh (demo)', 'minh.dang@example.org', '01632 960505', 'We need safeguarding training for new starters. What dates do you have?', 'in_progress', $this->office, -4);
        $e4 = $this->enquiry('Eilidh MacLeod (demo)', 'eilidh.macleod@example.org', null, 'Is the medication awareness course suitable for domiciliary carers?', 'closed', $this->office, -12);

        // Notes (append-only) and follow-ups in every state.
        $this->note('person', $p['Łukasz'], 'note', 'DEMO note: certificate expired; booked onto the next Basic Life Support session.', $this->office, -2);
        $this->note('organisation', $orgs[0], 'note', 'DEMO note: manager asked for a training matrix every quarter.', $this->owner, -7);
        $this->note('enquiry', $e3, 'note', 'DEMO note: called back, waiting for numbers.', $this->office, -3);
        $this->note('enquiry', $e4, 'reply', 'DEMO reply: yes, it covers home care settings too. Dates are on the course page.', $this->office, -11);
        $this->followUp('organisation', $orgs[1], 'DEMO: chase Riverside for staff list', -3, $this->office, false);
        $this->followUp('enquiry', $e3, 'DEMO: send safeguarding dates to Đặng Minh', 0, $this->office, false);
        $this->followUp('person', $p['Ọlá'], 'DEMO: book Ọlá onto a renewal session', 10, $this->owner, false);
        $this->followUp('organisation', $orgs[2], 'DEMO: confirm lounge booking at Elm Grove', -5, $this->office, true);
        $this->followUp('enquiry', $e1, 'DEMO: reply to Marta about evening sessions', 2, null, false);

        Audit::log($this->pdo, $this->owner, 'organisation.update', 'organisation', $orgs[0], ['reminderCopy']);
        Audit::log($this->pdo, $this->office, 'person.update', 'person', $p['Chloé'], ['remindersOptOut']);
        Audit::log($this->pdo, $this->office, 'enquiry.update', 'enquiry', $e4, ['status']);

        return ['users' => 2, 'organisations' => count($orgs), 'people' => count($p), 'courses' => 6, 'certificates' => $certs];
    }

    /** The completion date that makes a course with this renewal period expire on $expiresOn. */
    public static function completedFor(string $expiresOn, int $months): string
    {
        $d = (new \DateTimeImmutable($expiresOn . ' 12:00:00', new \DateTimeZone('UTC')))->modify("-$months months")->format('Y-m-d');
        // Month-end clamping can shift by a day or two; nudge until Expiry agrees (or give the closest).
        for ($i = 0; $i < 4 && Expiry::compute($d, $months) !== $expiresOn; $i++) {
            $d = LondonTime::addDays($d, Expiry::compute($d, $months) < $expiresOn ? 1 : -1);
        }
        return $d;
    }

    private function user(string $email, string $name, string $role): string
    {
        $id = Db::uuid();
        $this->pdo->prepare('INSERT INTO users (id, email, display_name, role) VALUES (?,?,?,?)')->execute([$id, $email, $name, $role]);
        return $id;
    }

    private function org(string $name, string $contact, string $email, string $phone, string $address, bool $copy): string
    {
        $id = Db::uuid();
        $this->pdo->prepare("INSERT INTO organisations (id, name, kind, contact_name, contact_email, phone, address, reminder_copy) VALUES (?,?, 'care_home', ?,?,?,?,?)")
            ->execute([$id, $name, $contact, $email, $phone, $address, $copy ? 1 : 0]);
        Audit::log($this->pdo, $this->office, 'organisation.create', 'organisation', $id, ['contactEmail', 'contactName', 'kind', 'name']);
        return $id;
    }

    private function person(string $first, string $last, ?string $org, string $job, ?string $email, bool $optOut, string $status): string
    {
        $id = Db::uuid();
        $this->pdo->prepare('INSERT INTO people (id, first_name, last_name, email, job_title, organisation_id, reminders_opt_out, status, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $first, $last, $email, $job, $org, $optOut ? 1 : 0, $status, $this->office]);
        return $id;
    }

    private function course(string $slug, string $title, string $summary, string $duration, float $hours, string $format, ?int $pence, string $priceNote, string $assessment, ?int $renewal, string $accreditation, bool $published, int $sort): string
    {
        $id = Db::uuid();
        $this->pdo->prepare(
            'INSERT INTO courses (id, slug, title, summary, duration_text, hours, format, price_pence, price_note, assessment_method, renewal_months, accreditation_note, published, published_at, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([$id, $slug, $title, $summary, $duration, $hours, $format, $pence, $priceNote, $assessment, $renewal, $accreditation,
            $published ? 1 : 0, $published ? $this->now->format('Y-m-d H:i:s.v') : null, $sort]);
        Audit::log($this->pdo, $this->owner, 'course.create', 'course', $id, ['published', 'slug', 'summary', 'title']);
        return $id;
    }

    private function session(string $course, string $startsLondon, float $hours, string $status, ?string $venue, int $capacity = 12, ?string $link = null): string
    {
        $start = LondonTime::toUtc($startsLondon) ?? throw new \RuntimeException('bad demo time');
        $end = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))->modify('+' . (int) round($hours * 60) . ' minutes')->format('Y-m-d H:i:s');
        $id = Db::uuid();
        $this->pdo->prepare('INSERT INTO training_sessions (id, course_id, starts_at, ends_at, venue, online_link, trainer_name, capacity, status) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $course, $start, $end, $venue, $link, 'Demo Trainer', $capacity, $status]);
        return $id;
    }

    private function attendee(string $session, string $person, string $attendance, ?string $result): string
    {
        $id = Db::uuid();
        $marked = $attendance === 'booked' ? null : $this->now->format('Y-m-d H:i:s.v');
        $this->pdo->prepare('INSERT INTO session_attendees (id, session_id, person_id, attendance, result, marked_at, marked_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id, $session, $person, $attendance, $result, $marked, $marked === null ? null : $this->office]);
        return $id;
    }

    private function at(float $daysAgo): string
    {
        return $this->now->modify(sprintf('%+d minutes', (int) round($daysAgo * 1440)))->format('Y-m-d H:i:s.v');
    }

    private function enquiry(string $name, string $email, ?string $phone, string $message, string $status, ?string $assignee, float $days): string
    {
        $id = Db::uuid();
        $this->pdo->prepare('INSERT INTO enquiries (id, name, email, phone, message, status, assignee_id, closed_at, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $name, $email, $phone, $message, $status, $assignee, $status === 'closed' ? $this->at($days + 1) : null, $this->at($days)]);
        return $id;
    }

    private function note(string $type, string $subject, string $kind, string $body, string $author, float $days): void
    {
        $this->pdo->prepare('INSERT INTO notes (id, subject_type, subject_id, kind, body, author_id, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([Db::uuid(), $type, $subject, $kind, $body, $author, $this->at($days)]);
    }

    private function followUp(string $type, string $subject, string $title, int $dueInDays, ?string $assignee, bool $done): void
    {
        $this->pdo->prepare('INSERT INTO follow_ups (id, subject_type, subject_id, title, due_on, assignee_id, done_at, done_by, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([Db::uuid(), $type, $subject, $title, LondonTime::addDays($this->today, $dueInDays), $assignee,
                $done ? $this->at(-1) : null, $done ? $this->owner : null, $this->owner]);
    }
}
