<?php

declare(strict_types=1);

namespace Vitalcare\Cms;

use Vitalcare\Auth\Authz;
use Vitalcare\Auth\User;
use Vitalcare\Certificates\CertificateCode;
use Vitalcare\Certificates\CertificateIssuer;
use Vitalcare\Certificates\CertificatePdf;
use Vitalcare\Certificates\CertificateState;
use Vitalcare\Certificates\Renewals;
use Vitalcare\Http\HttpError;
use Vitalcare\Http\RawBody;
use Vitalcare\Support\Db;

/**
 * Every /api/admin route (D-018). The caller has already proved the user may use the CMS; each route
 * then names exactly one permission from Authz::PERMISSIONS, checked before anything is read or written.
 */
final class AdminApi
{
    private const ID = '([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})';
    private const PER_PAGE = 50;
    private const CMS_USER = "status = 'active' AND role IN ('admin', 'super_admin')";

    /** @param array<string,string> $query */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly User $user,
        private readonly Authz $z,
        private readonly \DateTimeImmutable $now,
        private readonly string $officeEmail,
        private readonly ?string $body,
        private readonly array $query,
    ) {
    }

    /** @return list<array{0:string,1:string,2:string,3:callable}> method, path pattern, permission, handler */
    public function routes(): array
    {
        $id = self::ID;
        return [
            ['GET', '#^/dashboard$#', 'cms.read', fn () => $this->dashboard()],
            ['GET', '#^/search$#', 'cms.read', fn () => $this->search()],
            ['GET', '#^/staff$#', 'cms.read', fn () => $this->staff()],
            ['GET', '#^/people$#', 'cms.read', fn () => $this->listPeople()],
            ['POST', '#^/people$#', 'people.write', fn () => $this->createPerson()],
            ['GET', "#^/people/$id$#", 'cms.read', fn (string $i) => $this->getPerson($i)],
            ['PATCH', "#^/people/$id$#", 'people.write', fn (string $i) => $this->updatePerson($i)],
            ['GET', "#^/people/$id/notes$#", 'cms.read', fn (string $i) => $this->listNotes('person', $i)],
            ['POST', "#^/people/$id/notes$#", 'notes.write', fn (string $i) => $this->addNote('person', $i)],
            ['GET', '#^/organisations$#', 'cms.read', fn () => $this->listOrganisations()],
            ['POST', '#^/organisations$#', 'organisations.write', fn () => $this->createOrganisation()],
            ['GET', "#^/organisations/$id$#", 'cms.read', fn (string $i) => $this->getOrganisation($i)],
            ['PATCH', "#^/organisations/$id$#", 'organisations.write', fn (string $i) => $this->updateOrganisation($i)],
            ['GET', "#^/organisations/$id/notes$#", 'cms.read', fn (string $i) => $this->listNotes('organisation', $i)],
            ['POST', "#^/organisations/$id/notes$#", 'notes.write', fn (string $i) => $this->addNote('organisation', $i)],
            ['GET', '#^/courses$#', 'cms.read', fn () => $this->listCourses()],
            ['POST', '#^/courses$#', 'courses.write', fn () => $this->createCourse()],
            ['GET', "#^/courses/$id$#", 'cms.read', fn (string $i) => $this->getCourse($i)],
            ['PATCH', "#^/courses/$id$#", 'courses.write', fn (string $i) => $this->updateCourse($i)],
            ['GET', '#^/sessions$#', 'cms.read', fn () => $this->listSessions()],
            ['POST', '#^/sessions$#', 'sessions.write', fn () => $this->createSession()],
            ['GET', "#^/sessions/$id$#", 'cms.read', fn (string $i) => $this->getSession($i)],
            ['PATCH', "#^/sessions/$id$#", 'sessions.write', fn (string $i) => $this->updateSession($i)],
            ['POST', "#^/sessions/$id/attendees$#", 'attendance.write', fn (string $i) => $this->addAttendee($i)],
            ['PATCH', "#^/attendees/$id$#", 'attendance.write', fn (string $i) => $this->updateAttendee($i)],
            ['GET', '#^/certificates$#', 'cms.read', fn () => $this->listCertificates()],
            ['POST', '#^/certificates$#', 'certificates.issue', fn () => $this->issueCertificate()],
            ['GET', "#^/certificates/$id$#", 'cms.read', fn (string $i) => [200, ['certificate' => $this->certificate($i)]]],
            ['POST', "#^/certificates/$id/revoke$#", 'certificates.revoke', fn (string $i) => $this->revokeCertificate($i)],
            ['GET', "#^/certificates/$id/pdf$#", 'cms.read', fn (string $i) => $this->certificatePdf($i)],
            ['GET', '#^/renewals$#', 'cms.read', fn () => $this->renewals()],
            ['GET', '#^/follow-ups$#', 'cms.read', fn () => $this->listFollowUps()],
            ['POST', '#^/follow-ups$#', 'followups.write', fn () => $this->createFollowUp()],
            ['PATCH', "#^/follow-ups/$id$#", 'followups.write', fn (string $i) => $this->updateFollowUp($i)],
            ['GET', '#^/enquiries$#', 'cms.read', fn () => $this->listEnquiries()],
            ['GET', "#^/enquiries/$id$#", 'cms.read', fn (string $i) => $this->getEnquiry($i)],
            ['PATCH', "#^/enquiries/$id$#", 'enquiries.write', fn (string $i) => $this->updateEnquiry($i)],
            ['POST', "#^/enquiries/$id/notes$#", 'enquiries.write', fn (string $i) => $this->addNote('enquiry', $i)],
            ['POST', "#^/enquiries/$id/reply$#", 'enquiries.write', fn (string $i) => $this->replyToEnquiry($i)],
            ['GET', '#^/export/people\.csv$#', 'export', fn () => $this->exportPeople()],
            ['GET', '#^/export/certificates\.csv$#', 'export', fn () => $this->exportCertificates()],
            ['GET', '#^/audit$#', 'audit.read', fn () => $this->auditLog()],
            ['GET', '#^/users$#', 'users.manage', fn () => $this->listUsers()],
            ['POST', '#^/users$#', 'users.manage', fn () => $this->createUser()],
            ['PATCH', "#^/users/$id$#", 'users.manage', fn (string $i) => $this->updateUser($i)],
        ];
    }

    /** @return array{0:int,1:array<string,mixed>|RawBody,2?:array<string,string>} */
    public function handle(string $method, string $path): array
    {
        $allowed = [];
        foreach ($this->routes() as [$m, $pattern, $permission, $fn]) {
            if (preg_match($pattern, $path, $match) !== 1) {
                continue;
            }
            if ($m !== $method) {
                $allowed[] = $m;
                continue;
            }
            $this->z->require($this->z->can($permission));
            return $fn(...array_slice($match, 1));
        }
        if ($allowed !== []) {
            throw new HttpError(405, 'method_not_allowed', 'Method not allowed', [], ['Allow' => implode(', ', $allowed)]);
        }
        throw HttpError::notFound();
    }

    // ---------------------------------------------------------------- helpers

    private function input(): Input
    {
        return Input::json($this->body);
    }

    private function q(): string
    {
        $q = trim((string) ($this->query['q'] ?? ''));
        return mb_strlen($q) > 100 ? mb_substr($q, 0, 100) : $q;
    }

    private function today(): string
    {
        return LondonTime::today($this->now);
    }

    private function nowUtc(): string
    {
        return $this->now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param list<mixed> $params
     * @return array{0:int,1:array<string,mixed>}
     */
    private function page(string $select, string $from, string $where, array $params, string $order, callable $map): array
    {
        $page = max(1, min(10000, (int) ($this->query['page'] ?? 1)));
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM $from WHERE $where");
        $count->execute($params);
        $st = $this->pdo->prepare("SELECT $select FROM $from WHERE $where ORDER BY $order LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE));
        $st->execute($params);
        return [200, ['items' => array_map($map, $st->fetchAll()), 'total' => (int) $count->fetchColumn(), 'page' => $page]];
    }

    /** @param list<mixed> $params @return list<array<string,mixed>> */
    private function rows(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @param list<string> $fields */
    private function log(string $action, string $type, ?string $id, array $fields = []): void
    {
        Audit::log($this->pdo, $this->user->id, $action, $type, $id, $fields);
    }

    private static function stamp(?string $utc): ?string
    {
        return $utc === null ? null : LondonTime::fromUtc($utc);
    }

    // ---------------------------------------------------------------- people

    private function personOut(array $r): array
    {
        return Record::out(Specs::PERSON, $r) + ['organisationName' => $r['organisation_name'] ?? null];
    }

    private function listPeople(): array
    {
        $where = ['1=1'];
        $p = [];
        if (($q = $this->q()) !== '') {
            $where[] = "(CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR p.email_lower LIKE ? OR p.phone LIKE ?)";
            array_push($p, Record::like($q), Record::like(strtolower($q)), Record::like($q));
        }
        if (is_string($this->query['organisationId'] ?? null)) {
            $where[] = 'p.organisation_id = ?';
            $p[] = $this->query['organisationId'];
        }
        if (in_array($this->query['status'] ?? '', ['active', 'archived'], true)) {
            $where[] = 'p.status = ?';
            $p[] = $this->query['status'];
        }
        return $this->page('p.*, o.name AS organisation_name', 'people p LEFT JOIN organisations o ON o.id = p.organisation_id',
            implode(' AND ', $where), $p, 'p.last_name, p.first_name, p.id', fn (array $r) => $this->personOut($r));
    }

    private function createPerson(): array
    {
        $in = $this->input();
        $v = Record::read(Specs::PERSON, $in, false);
        Specs::refs($this->pdo, $in, $v, ['organisation_id' => ['organisationId', 'organisations']]);
        $in->check();
        $id = Db::uuid();
        Record::insert($this->pdo, 'people', ['id' => $id, 'created_by' => $this->user->id] + $v);
        $this->log('person.create', 'person', $id, Record::names(Specs::PERSON, $v));
        return [201, ['person' => $this->personRow($id)]];
    }

    private function updatePerson(string $id): array
    {
        Specs::notFoundUnless(Record::exists($this->pdo, 'people', $id));
        $in = $this->input();
        $v = Record::read(Specs::PERSON, $in, true);
        Specs::refs($this->pdo, $in, $v, ['organisation_id' => ['organisationId', 'organisations']]);
        $in->check();
        Record::update($this->pdo, 'people', $id, $v);
        $this->log('person.update', 'person', $id, Record::names(Specs::PERSON, $v));
        return [200, ['person' => $this->personRow($id)]];
    }

    private function personRow(string $id): array
    {
        return $this->personOut(Record::find($this->pdo,
            'SELECT p.*, o.name AS organisation_name FROM people p LEFT JOIN organisations o ON o.id = p.organisation_id WHERE p.id = ?', [$id]));
    }

    private function getPerson(string $id): array
    {
        $person = $this->personRow($id);
        $attendance = array_map(fn (array $r) => [
            'attendeeId' => $r['id'], 'sessionId' => $r['session_id'], 'courseTitle' => $r['title'],
            'startsAt' => self::stamp($r['starts_at']), 'attendance' => $r['attendance'], 'result' => $r['result'],
            'certificateId' => $r['certificate_id'],
        ], $this->rows(
            'SELECT a.id, a.session_id, a.attendance, a.result, s.starts_at, c.title, (SELECT x.id FROM certificates x WHERE x.attendee_id = a.id) AS certificate_id
             FROM session_attendees a JOIN training_sessions s ON s.id = a.session_id JOIN courses c ON c.id = s.course_id
             WHERE a.person_id = ? ORDER BY s.starts_at DESC',
            [$id],
        ));
        $certificates = array_map(fn (array $r) => $this->certificateOut($r), $this->rows('SELECT * FROM certificates WHERE person_id = ? ORDER BY completed_on DESC', [$id]));
        return [200, [
            'person' => $person,
            'attendance' => $attendance,
            'certificates' => $certificates,
            'notes' => $this->notes('person', $id),
            'followUps' => $this->followUps('f.subject_type = ? AND f.subject_id = ?', ['person', $id]),
        ]];
    }

    // ---------------------------------------------------------------- organisations

    private function listOrganisations(): array
    {
        $where = '1=1';
        $p = [];
        if (($q = $this->q()) !== '') {
            $where = '(o.name LIKE ? OR o.contact_email LIKE ?)';
            $p = [Record::like($q), Record::like(strtolower($q))];
        }
        return $this->page("o.*, (SELECT COUNT(*) FROM people p WHERE p.organisation_id = o.id AND p.status = 'active') AS people_count",
            'organisations o', $where, $p, 'o.name, o.id',
            fn (array $r) => Record::out(Specs::ORGANISATION, $r) + ['peopleCount' => (int) $r['people_count']]);
    }

    private function createOrganisation(): array
    {
        $in = $this->input();
        $v = Record::read(Specs::ORGANISATION, $in, false);
        $in->check();
        $id = Db::uuid();
        Record::insert($this->pdo, 'organisations', ['id' => $id] + $v);
        $this->log('organisation.create', 'organisation', $id, Record::names(Specs::ORGANISATION, $v));
        return [201, ['organisation' => $this->organisationRow($id)]];
    }

    private function updateOrganisation(string $id): array
    {
        Specs::notFoundUnless(Record::exists($this->pdo, 'organisations', $id));
        $in = $this->input();
        $v = Record::read(Specs::ORGANISATION, $in, true);
        $in->check();
        Record::update($this->pdo, 'organisations', $id, $v);
        $this->log('organisation.update', 'organisation', $id, Record::names(Specs::ORGANISATION, $v));
        return [200, ['organisation' => $this->organisationRow($id)]];
    }

    private function organisationRow(string $id): array
    {
        return Record::out(Specs::ORGANISATION, Record::find($this->pdo, 'SELECT * FROM organisations WHERE id = ?', [$id]));
    }

    /** Staff with their current certificate per course: in_date, due_soon (30 days) or expired. */
    private function getOrganisation(string $id): array
    {
        $org = $this->organisationRow($id);
        $today = $this->today();
        $soon = LondonTime::addDays($today, 30);
        $rows = $this->rows(
            'SELECT p.id, p.first_name, p.last_name, p.job_title, p.status, c.id AS cid, c.code, c.course_title, c.expires_on
             FROM people p LEFT JOIN certificates c ON c.person_id = p.id AND ' . Renewals::CURRENT . '
             WHERE p.organisation_id = ? ORDER BY p.last_name, p.first_name, c.course_title',
            [$id],
        );
        $rank = ['none' => 0, 'in_date' => 1, 'due_soon' => 2, 'expired' => 3];
        $staff = [];
        foreach ($rows as $r) {
            $staff[$r['id']] ??= ['id' => $r['id'], 'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'jobTitle' => $r['job_title'],
                'status' => $r['status'], 'summary' => 'none', 'certificates' => []];
            if ($r['cid'] === null) {
                continue;
            }
            $e = $r['expires_on'];
            $state = $e !== null && $e < $today ? 'expired' : ($e !== null && $e <= $soon ? 'due_soon' : 'in_date');
            $staff[$r['id']]['certificates'][] = ['id' => $r['cid'], 'code' => CertificateCode::display((string) $r['code']),
                'courseTitle' => $r['course_title'], 'expiresOn' => $e, 'state' => $state];
            if ($rank[$state] > $rank[$staff[$r['id']]['summary']]) {
                $staff[$r['id']]['summary'] = $state;
            }
        }
        return [200, [
            'organisation' => $org,
            'staff' => array_values($staff),
            'notes' => $this->notes('organisation', $id),
            'followUps' => $this->followUps('f.subject_type = ? AND f.subject_id = ?', ['organisation', $id]),
        ]];
    }

    // ---------------------------------------------------------------- courses (writes are owner only)

    private function listCourses(): array
    {
        $where = '1=1';
        $p = [];
        if (($q = $this->q()) !== '') {
            $where = '(title LIKE ? OR slug LIKE ?)';
            $p = [Record::like($q), Record::like(strtolower($q))];
        }
        return $this->page('*', 'courses', $where, $p, 'sort_order, title, id', fn (array $r) => Record::out(Specs::COURSE, $r));
    }

    private function getCourse(string $id): array
    {
        return [200, ['course' => Record::out(Specs::COURSE, Record::find($this->pdo, 'SELECT * FROM courses WHERE id = ?', [$id]))]];
    }

    private function createCourse(): array
    {
        $in = $this->input();
        $v = Record::read(Specs::COURSE, $in, false);
        Specs::guardCourseCopy($in, $v);
        $this->slugFree($in, $v, null);
        $in->check();
        $id = Db::uuid();
        $v['published_at'] = $v['published'] === 1 ? $this->now->format('Y-m-d H:i:s.v') : null;
        Record::insert($this->pdo, 'courses', ['id' => $id] + $v);
        $this->log('course.create', 'course', $id, Record::names(Specs::COURSE, $v));
        return [201, $this->getCourse($id)[1]];
    }

    private function updateCourse(string $id): array
    {
        $old = Record::find($this->pdo, 'SELECT published FROM courses WHERE id = ?', [$id]);
        $in = $this->input();
        $v = Record::read(Specs::COURSE, $in, true);
        Specs::guardCourseCopy($in, $v);
        $this->slugFree($in, $v, $id);
        $in->check();
        if (($v['published'] ?? null) === 1 && (int) $old['published'] === 0) {
            $v['published_at'] = $this->now->format('Y-m-d H:i:s.v');
        }
        Record::update($this->pdo, 'courses', $id, $v);
        $this->log('course.update', 'course', $id, Record::names(Specs::COURSE, $v));
        return [200, $this->getCourse($id)[1]];
    }

    private function slugFree(Input $in, array $v, ?string $id): void
    {
        if (!isset($v['slug'])) {
            return;
        }
        // (A UUID column never equals '', so the "not this course" clause is only added for an update.)
        $st = $this->pdo->prepare('SELECT id FROM courses WHERE slug = ?' . ($id !== null ? ' AND id <> ?' : ''));
        $st->execute($id !== null ? [$v['slug'], $id] : [$v['slug']]);
        if ($st->fetchColumn() !== false) {
            $in->error('slug', 'Another course already uses this web address.');
        }
    }

    // ---------------------------------------------------------------- sessions and attendance

    private const SESSION_SELECT = "s.*, c.title AS course_title,
        (SELECT COUNT(*) FROM session_attendees a WHERE a.session_id = s.id AND a.attendance <> 'cancelled') AS booked";

    private function sessionOut(array $r): array
    {
        return Record::out(Specs::SESSION, $r) + ['courseTitle' => $r['course_title'], 'booked' => (int) $r['booked']];
    }

    private function listSessions(): array
    {
        $when = $this->query['when'] ?? 'upcoming';
        [$where, $order] = match ($when) {
            'past' => ['s.starts_at < ?', 's.starts_at DESC'],
            'all' => ['? IS NOT NULL', 's.starts_at DESC'],
            default => ['s.ends_at >= ?', 's.starts_at ASC'],
        };
        return $this->page(self::SESSION_SELECT, 'training_sessions s JOIN courses c ON c.id = s.course_id', $where, [$this->nowUtc()],
            $order . ', s.id', fn (array $r) => $this->sessionOut($r));
    }

    private function sessionRow(string $id): array
    {
        return $this->sessionOut(Record::find($this->pdo, 'SELECT ' . self::SESSION_SELECT . ' FROM training_sessions s JOIN courses c ON c.id = s.course_id WHERE s.id = ?', [$id]));
    }

    /** Cross-field rules once the full set of values is known. */
    private function sessionRules(Input $in, array $merged): void
    {
        if (is_string($merged['starts_at'] ?? null) && is_string($merged['ends_at'] ?? null) && $merged['ends_at'] <= $merged['starts_at']) {
            $in->error('endsAt', 'The end must be after the start.');
        }
        if (($merged['venue'] ?? null) === null && ($merged['online_link'] ?? null) === null) {
            $in->error('venue', 'Enter a venue or an online link.');
        }
    }

    private function createSession(): array
    {
        $in = $this->input();
        $v = Record::read(Specs::SESSION, $in, false);
        Specs::refs($this->pdo, $in, $v, ['course_id' => ['courseId', 'courses']]);
        $this->sessionRules($in, $v);
        $in->check();
        $id = Db::uuid();
        Record::insert($this->pdo, 'training_sessions', ['id' => $id] + $v);
        $this->log('session.create', 'session', $id, Record::names(Specs::SESSION, $v));
        return [201, ['session' => $this->sessionRow($id)]];
    }

    private function updateSession(string $id): array
    {
        $old = Record::find($this->pdo, 'SELECT * FROM training_sessions WHERE id = ?', [$id]);
        $in = $this->input();
        $v = Record::read(Specs::SESSION, $in, true);
        Specs::refs($this->pdo, $in, $v, ['course_id' => ['courseId', 'courses']]);
        $this->sessionRules($in, $v + $old);
        $in->check();
        Record::update($this->pdo, 'training_sessions', $id, $v);
        $this->log('session.update', 'session', $id, Record::names(Specs::SESSION, $v));
        return [200, ['session' => $this->sessionRow($id)]];
    }

    private function getSession(string $id): array
    {
        $session = $this->sessionRow($id);
        $attendees = array_map(fn (array $r) => [
            'id' => $r['id'], 'personId' => $r['person_id'], 'personName' => trim($r['first_name'] . ' ' . $r['last_name']),
            'organisationName' => $r['organisation_name'], 'attendance' => $r['attendance'], 'result' => $r['result'],
            'certificateId' => $r['certificate_id'], 'certificateCode' => $r['code'] !== null ? CertificateCode::display((string) $r['code']) : null,
        ], $this->rows(
            'SELECT a.*, p.first_name, p.last_name, o.name AS organisation_name, x.id AS certificate_id, x.code
             FROM session_attendees a JOIN people p ON p.id = a.person_id LEFT JOIN organisations o ON o.id = p.organisation_id
             LEFT JOIN certificates x ON x.attendee_id = a.id
             WHERE a.session_id = ? ORDER BY p.last_name, p.first_name',
            [$id],
        ));
        return [200, ['session' => $session, 'attendees' => $attendees]];
    }

    private function addAttendee(string $sessionId): array
    {
        $in = $this->input();
        $personId = $in->value('personId', 'uuid', ['required' => true]);
        if (is_string($personId) && !Record::exists($this->pdo, 'people', $personId, "status = 'active'")) {
            $in->error('personId', 'Choose an active person from the list.');
        }
        $in->check();
        $this->pdo->beginTransaction();
        try {
            $s = $this->pdo->prepare('SELECT capacity, status FROM training_sessions WHERE id = ? FOR UPDATE');
            $s->execute([$sessionId]);
            $session = $s->fetch();
            if ($session === false) {
                throw HttpError::notFound();
            }
            if ($session['status'] === 'cancelled') {
                throw HttpError::conflict('cancelled', 'This session is cancelled.');
            }
            $existing = $this->rows('SELECT id, attendance FROM session_attendees WHERE session_id = ? AND person_id = ?', [$sessionId, $personId]);
            if ($existing !== [] && $existing[0]['attendance'] !== 'cancelled') {
                throw HttpError::conflict('already_booked', 'This person is already booked on this session.');
            }
            $booked = (int) $this->rows("SELECT COUNT(*) AS n FROM session_attendees WHERE session_id = ? AND attendance <> 'cancelled'", [$sessionId])[0]['n'];
            if ($booked >= (int) $session['capacity']) {
                throw HttpError::conflict('full', 'This session is full.');
            }
            if ($existing !== []) {
                $aid = (string) $existing[0]['id'];
                $this->pdo->prepare("UPDATE session_attendees SET attendance = 'booked', result = NULL, marked_at = NULL, marked_by = NULL WHERE id = ?")->execute([$aid]);
            } else {
                $aid = Db::uuid();
                $this->pdo->prepare('INSERT INTO session_attendees (id, session_id, person_id) VALUES (?,?,?)')->execute([$aid, $sessionId, $personId]);
            }
            $this->log('attendee.book', 'attendee', $aid, ['personId']);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return [201, $this->getSession($sessionId)[1]];
    }

    private function updateAttendee(string $id): array
    {
        $old = Record::find($this->pdo, "SELECT a.*, (SELECT x.id FROM certificates x WHERE x.attendee_id = a.id AND x.status = 'approved') AS certificate_id FROM session_attendees a WHERE a.id = ?", [$id]);
        $in = $this->input();
        $v = [];
        if ($in->has('attendance')) {
            $v['attendance'] = $in->value('attendance', 'enum', ['values' => ['booked', 'attended', 'no_show', 'cancelled'], 'required' => true]);
        }
        if ($in->has('result')) {
            $v['result'] = $in->value('result', 'enum', ['values' => ['pass', 'fail', 'not_assessed']]);
        }
        $merged = $v + $old;
        if ($merged['result'] !== null && $merged['attendance'] !== 'attended') {
            $in->error('result', 'Only someone who attended can have a result.');
        }
        $in->check();
        if ($old['certificate_id'] !== null && $v !== []) {
            throw HttpError::conflict('certificate_issued', 'A certificate has been issued for this attendance. Revoke it before changing the record.');
        }
        if ($v !== []) {
            $v['marked_at'] = $this->now->format('Y-m-d H:i:s.v');
            $v['marked_by'] = $this->user->id;
            Record::update($this->pdo, 'session_attendees', $id, $v);
            $this->log('attendee.mark', 'attendee', $id, array_values(array_intersect(['attendance', 'result'], array_keys($v))));
        }
        return [200, $this->getSession((string) $old['session_id'])[1]];
    }

    // ---------------------------------------------------------------- certificates and renewals

    private function certificateOut(array $r): array
    {
        $revoked = $r['revoked_at'] ?? null;
        return [
            'id' => $r['id'],
            'code' => CertificateCode::display((string) $r['code']),
            'personId' => $r['person_id'] ?? null,
            'personName' => $r['learner_name'],
            'courseId' => $r['course_id'] ?? null,
            'courseTitle' => $r['course_title'],
            'hours' => $r['course_hours'] !== null ? (float) $r['course_hours'] : null,
            'completedOn' => $r['completed_on'],
            'issuedOn' => $r['issued_on'] ?? null,
            'expiresOn' => $r['expires_on'],
            'state' => CertificateState::of((string) $r['status'], $revoked, $r['expires_on'], $this->today()),
            'byHand' => ($r['attendee_id'] ?? null) === null,
            'issueReason' => $r['issue_reason'] ?? null,
            'revokedOn' => $revoked !== null ? LondonTime::dateOf((string) $revoked) : null,
            'revokeReason' => $r['revoke_reason'] ?? null,
        ];
    }

    private function certificate(string $id): array
    {
        return $this->certificateOut(Record::find($this->pdo, 'SELECT * FROM certificates WHERE id = ?', [$id]));
    }

    private function listCertificates(): array
    {
        $today = $this->today();
        $where = ["status IN ('approved', 'withdrawn')"];
        $p = [];
        if (($q = $this->q()) !== '') {
            $code = CertificateCode::normalise($q);
            $where[] = '(learner_name LIKE ? OR course_title LIKE ? OR code = ?)';
            array_push($p, Record::like($q), Record::like($q), $code ?? '');
        }
        switch ($this->query['status'] ?? '') {
            case 'valid':
                $where[] = "status = 'approved' AND (expires_on IS NULL OR expires_on >= ?)";
                $p[] = $today;
                break;
            case 'expired':
                $where[] = "status = 'approved' AND expires_on < ?";
                $p[] = $today;
                break;
            case 'revoked':
                $where[] = "status = 'withdrawn' AND revoked_at IS NOT NULL";
                break;
        }
        return $this->page('*', 'certificates', implode(' AND ', $where), $p, 'completed_on DESC, id DESC', fn (array $r) => $this->certificateOut($r));
    }

    private function issueCertificate(): array
    {
        $in = $this->input();
        $issuer = new CertificateIssuer($this->pdo, $this->now);
        if ($in->has('attendeeId')) {
            $attendee = $in->value('attendeeId', 'uuid', ['required' => true]);
            $in->check();
            $id = $issuer->fromAttendance((string) $attendee, $this->user->id);
        } else {
            // By hand: the owner only (D-018 item 2), with a reason.
            $this->z->require($this->z->can('certificates.issue_manual'));
            $person = $in->value('personId', 'uuid', ['required' => true]);
            $course = $in->value('courseId', 'uuid', ['required' => true]);
            $completed = $in->value('completedOn', 'date', ['required' => true]);
            $reason = $in->value('reason', 'longtext', ['required' => true, 'min' => 10, 'max' => 500]);
            $in->check();
            $id = $issuer->manual((string) $person, (string) $course, (string) $completed, (string) $reason, $this->user->id);
        }
        return [201, ['certificate' => $this->certificate($id)]];
    }

    private function revokeCertificate(string $id): array
    {
        $in = $this->input();
        $reason = $in->value('reason', 'longtext', ['required' => true, 'min' => 10, 'max' => 500]);
        $in->check();
        (new CertificateIssuer($this->pdo, $this->now))->revoke($id, (string) $reason, $this->user->id);
        return [200, ['certificate' => $this->certificate($id)]];
    }

    private function certificatePdf(string $id): array
    {
        $r = Record::find($this->pdo, 'SELECT * FROM certificates WHERE id = ?', [$id]);
        if (CertificateState::of((string) $r['status'], $r['revoked_at'], $r['expires_on'], $this->today()) === CertificateState::REVOKED
            || $r['status'] !== 'approved') {
            throw HttpError::conflict('not_available', 'There is no certificate to download: it is revoked or not issued.');
        }
        $pdf = CertificatePdf::render([
            'code' => (string) $r['code'],
            'learnerName' => (string) $r['learner_name'],
            'courseTitle' => (string) $r['course_title'],
            'hours' => $r['course_hours'] !== null ? (float) $r['course_hours'] : null,
            'completedOn' => (string) $r['completed_on'],
            'issuedOn' => $r['issued_on'],
            'expiresOn' => $r['expires_on'],
        ]);
        $this->log('certificate.download', 'certificate', $id);
        return [200, new RawBody($pdf, 'application/pdf', 'certificate-' . $r['code'] . '.pdf')];
    }

    private function renewals(): array
    {
        $window = $this->query['window'] ?? '30';
        if (!in_array($window, ['30', '60', '90', 'expired'], true)) {
            throw HttpError::invalid('Choose 30, 60 or 90 days, or expired.');
        }
        return [200, ['window' => $window, 'items' => (new Renewals($this->pdo, $this->now))->view($window)]];
    }

    // ---------------------------------------------------------------- notes and follow-ups

    private const SUBJECT_TABLE = ['person' => 'people', 'organisation' => 'organisations', 'enquiry' => 'enquiries'];

    /** @return list<array<string,mixed>> newest first */
    private function notes(string $type, string $id): array
    {
        return array_map(fn (array $r) => [
            'id' => $r['id'], 'kind' => $r['kind'], 'body' => $r['body'], 'authorName' => $r['display_name'], 'createdAt' => self::stamp($r['created_at']),
        ], $this->rows(
            'SELECT n.id, n.kind, n.body, n.created_at, u.display_name FROM notes n JOIN users u ON u.id = n.author_id
             WHERE n.subject_type = ? AND n.subject_id = ? ORDER BY n.created_at DESC, n.id DESC LIMIT 200',
            [$type, $id],
        ));
    }

    private function listNotes(string $type, string $id): array
    {
        Specs::notFoundUnless(Record::exists($this->pdo, self::SUBJECT_TABLE[$type], $id));
        return [200, ['items' => $this->notes($type, $id)]];
    }

    private function addNote(string $type, string $id): array
    {
        Specs::notFoundUnless(Record::exists($this->pdo, self::SUBJECT_TABLE[$type], $id));
        $in = $this->input();
        $body = $in->value('body', 'longtext', ['required' => true, 'max' => 5000]);
        $in->check();
        $nid = Db::uuid();
        $this->pdo->prepare('INSERT INTO notes (id, subject_type, subject_id, kind, body, author_id) VALUES (?,?,?,?,?,?)')
            ->execute([$nid, $type, $id, 'note', $body, $this->user->id]);
        $this->log('note.create', $type, $id, ['body']);
        return [201, ['items' => $this->notes($type, $id)]];
    }

    /** @param list<mixed> $params @return list<array<string,mixed>> */
    private function followUps(string $where, array $params, int $limit = 200): array
    {
        $today = $this->today();
        return array_map(fn (array $r) => [
            'id' => $r['id'], 'subjectType' => $r['subject_type'], 'subjectId' => $r['subject_id'], 'subjectLabel' => $r['subject_label'],
            'title' => $r['title'], 'dueOn' => $r['due_on'], 'assigneeId' => $r['assignee_id'], 'assigneeName' => $r['assignee_name'],
            'done' => $r['done_at'] !== null, 'doneAt' => self::stamp($r['done_at']),
            'overdue' => $r['done_at'] === null && $r['due_on'] < $today,
        ], $this->rows(
            "SELECT f.*, a.display_name AS assignee_name,
               CASE f.subject_type
                 WHEN 'person' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM people WHERE id = f.subject_id)
                 WHEN 'organisation' THEN (SELECT name FROM organisations WHERE id = f.subject_id)
                 ELSE (SELECT name FROM enquiries WHERE id = f.subject_id) END AS subject_label
             FROM follow_ups f LEFT JOIN users a ON a.id = f.assignee_id
             WHERE $where ORDER BY f.done_at IS NOT NULL, f.due_on, f.id LIMIT $limit",
            $params,
        ));
    }

    private function listFollowUps(): array
    {
        $where = [];
        $p = [];
        switch ($this->query['scope'] ?? 'open') {
            case 'done':
                $where[] = 'f.done_at IS NOT NULL';
                break;
            case 'mine':
                $where[] = 'f.done_at IS NULL AND f.assignee_id = ?';
                $p[] = $this->user->id;
                break;
            default:
                $where[] = 'f.done_at IS NULL';
        }
        $type = $this->query['subjectType'] ?? null;
        if (is_string($type) && isset(self::SUBJECT_TABLE[$type]) && is_string($this->query['subjectId'] ?? null)) {
            $where[] = 'f.subject_type = ? AND f.subject_id = ?';
            array_push($p, $type, $this->query['subjectId']);
        }
        return [200, ['items' => $this->followUps(implode(' AND ', $where), $p)]];
    }

    private function assigneeCheck(Input $in, mixed $assignee): void
    {
        if (is_string($assignee) && !Record::exists($this->pdo, 'users', $assignee, self::CMS_USER)) {
            $in->error('assigneeId', 'Choose a member of staff from the list.');
        }
    }

    private function createFollowUp(): array
    {
        $in = $this->input();
        $type = $in->value('subjectType', 'enum', ['values' => array_keys(self::SUBJECT_TABLE), 'required' => true]);
        $subject = $in->value('subjectId', 'uuid', ['required' => true]);
        $title = $in->value('title', 'text', ['required' => true, 'max' => 200]);
        $due = $in->value('dueOn', 'date', ['required' => true]);
        $assignee = $in->value('assigneeId', 'uuid');
        if (is_string($type) && is_string($subject) && !Record::exists($this->pdo, self::SUBJECT_TABLE[$type], $subject)) {
            $in->error('subjectId', 'Choose a record from the list.');
        }
        $this->assigneeCheck($in, $assignee);
        $in->check();
        $id = Db::uuid();
        $this->pdo->prepare('INSERT INTO follow_ups (id, subject_type, subject_id, title, due_on, assignee_id, created_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id, $type, $subject, $title, $due, $assignee, $this->user->id]);
        $this->log('followup.create', 'follow_up', $id, ['assigneeId', 'dueOn', 'subjectId', 'title']);
        return [201, ['followUp' => $this->followUps('f.id = ?', [$id])[0]]];
    }

    private function updateFollowUp(string $id): array
    {
        Specs::notFoundUnless(Record::exists($this->pdo, 'follow_ups', $id));
        $in = $this->input();
        $v = [];
        if ($in->has('title')) {
            $v['title'] = $in->value('title', 'text', ['required' => true, 'max' => 200]);
        }
        if ($in->has('dueOn')) {
            $v['due_on'] = $in->value('dueOn', 'date', ['required' => true]);
        }
        if ($in->has('assigneeId')) {
            $v['assignee_id'] = $in->value('assigneeId', 'uuid');
            $this->assigneeCheck($in, $v['assignee_id']);
        }
        if ($in->has('done')) {
            $done = $in->value('done', 'bool', ['required' => true]);
            $v['done_at'] = $done === true ? $this->now->format('Y-m-d H:i:s.v') : null;
            $v['done_by'] = $done === true ? $this->user->id : null;
        }
        $in->check();
        Record::update($this->pdo, 'follow_ups', $id, $v);
        $names = ['title' => 'title', 'due_on' => 'dueOn', 'assignee_id' => 'assigneeId', 'done_at' => 'done'];
        $this->log('followup.update', 'follow_up', $id, array_values(array_intersect_key($names, $v)));
        return [200, ['followUp' => $this->followUps('f.id = ?', [$id])[0]]];
    }

    // ---------------------------------------------------------------- enquiries

    private const ENQUIRY_STATUS = "CASE WHEN e.status IN ('in_progress', 'read') THEN 'in_progress' WHEN e.status IN ('closed', 'answered', 'spam') THEN 'closed' ELSE 'new' END";

    private function enquiryOut(array $r): array
    {
        return [
            'id' => $r['id'], 'name' => $r['name'], 'email' => $r['email'], 'phone' => $r['phone'], 'message' => $r['message'],
            'status' => $r['api_status'], 'assigneeId' => $r['assignee_id'], 'assigneeName' => $r['assignee_name'],
            'createdAt' => self::stamp($r['created_at']), 'closedAt' => self::stamp($r['closed_at']),
        ];
    }

    private function listEnquiries(): array
    {
        $where = ['1=1'];
        $p = [];
        $status = $this->query['status'] ?? '';
        if (in_array($status, ['new', 'in_progress', 'closed'], true)) {
            $where[] = self::ENQUIRY_STATUS . ' = ?';
            $p[] = $status;
        }
        if (($q = $this->q()) !== '') {
            $where[] = '(e.name LIKE ? OR e.email LIKE ? OR e.message LIKE ?)';
            array_push($p, Record::like($q), Record::like($q), Record::like($q));
        }
        return $this->page('e.*, ' . self::ENQUIRY_STATUS . ' AS api_status, u.display_name AS assignee_name',
            'enquiries e LEFT JOIN users u ON u.id = e.assignee_id', implode(' AND ', $where), $p, 'e.created_at DESC, e.id',
            fn (array $r) => $this->enquiryOut($r));
    }

    private function enquiryRow(string $id): array
    {
        return $this->enquiryOut(Record::find($this->pdo, 'SELECT e.*, ' . self::ENQUIRY_STATUS . ' AS api_status, u.display_name AS assignee_name
            FROM enquiries e LEFT JOIN users u ON u.id = e.assignee_id WHERE e.id = ?', [$id]));
    }

    private function getEnquiry(string $id): array
    {
        return [200, [
            'enquiry' => $this->enquiryRow($id),
            'notes' => $this->notes('enquiry', $id),
            'followUps' => $this->followUps('f.subject_type = ? AND f.subject_id = ?', ['enquiry', $id]),
        ]];
    }

    private function updateEnquiry(string $id): array
    {
        $old = $this->enquiryRow($id);
        $in = $this->input();
        $v = [];
        if ($in->has('status')) {
            $v['status'] = $in->value('status', 'enum', ['values' => ['new', 'in_progress', 'closed'], 'required' => true]);
            if ($v['status'] !== $old['status']) {
                $v['closed_at'] = $v['status'] === 'closed' ? $this->now->format('Y-m-d H:i:s.v') : null;
            }
        }
        if ($in->has('assigneeId')) {
            $v['assignee_id'] = $in->value('assigneeId', 'uuid');
            $this->assigneeCheck($in, $v['assignee_id']);
        }
        $in->check();
        Record::update($this->pdo, 'enquiries', $id, $v);
        $this->log('enquiry.update', 'enquiry', $id, array_values(array_intersect_key(['status' => 'status', 'assignee_id' => 'assigneeId'], $v)));
        return $this->getEnquiry($id);
    }

    /** One email to the enquirer through the outbox, from the office, recorded as a reply note. */
    private function replyToEnquiry(string $id): array
    {
        $e = $this->enquiryRow($id);
        $in = $this->input();
        $body = $in->value('body', 'longtext', ['required' => true, 'max' => 10000]);
        $in->check();
        $text = $body . "\n\n--\nVitalcare Training Hub Ltd\nEmail info@vitalcare.uk. Telephone 020 8059 8757. Monday to Friday, 9am to 5pm.\n";
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO outbox_email (id, to_email, reply_to, subject, body_text) VALUES (?,?,?,?,?)')
                ->execute([Db::uuid(), $e['email'], $this->officeEmail, 'Re: your enquiry to Vitalcare Training Hub', $text]);
            $this->pdo->prepare('INSERT INTO notes (id, subject_type, subject_id, kind, body, author_id) VALUES (?,?,?,?,?,?)')
                ->execute([Db::uuid(), 'enquiry', $id, 'reply', $body, $this->user->id]);
            if ($e['status'] === 'new') {
                $this->pdo->prepare("UPDATE enquiries SET status = 'in_progress' WHERE id = ?")->execute([$id]);
            }
            $this->log('enquiry.reply', 'enquiry', $id, ['body']);
            $this->pdo->commit();
        } catch (\Throwable $ex) {
            $this->pdo->rollBack();
            throw $ex;
        }
        return [201, $this->getEnquiry($id)[1]];
    }

    // ---------------------------------------------------------------- dashboard, search, staff

    private function dashboard(): array
    {
        $today = $this->today();
        $renewals = new Renewals($this->pdo, $this->now);
        $due = $this->followUps('f.done_at IS NULL AND f.due_on <= ?', [$today], 20);
        $counts = $this->rows('SELECT SUM(due_on < ?) AS overdue, SUM(due_on = ?) AS today FROM follow_ups WHERE done_at IS NULL', [$today, $today])[0];
        $newEnquiries = $this->rows('SELECT e.*, ' . self::ENQUIRY_STATUS . " AS api_status, NULL AS assignee_name FROM enquiries e WHERE e.status = 'new' ORDER BY e.created_at DESC LIMIT 5");
        $newCount = (int) $this->rows("SELECT COUNT(*) AS n FROM enquiries WHERE status = 'new'")[0]['n'];
        $sessions = $this->rows(
            'SELECT ' . self::SESSION_SELECT . " FROM training_sessions s JOIN courses c ON c.id = s.course_id
             WHERE s.status = 'scheduled' AND s.ends_at >= ? AND s.starts_at < ? ORDER BY s.starts_at LIMIT 10",
            [$this->nowUtc(), $this->now->modify('+14 days')->format('Y-m-d H:i:s')],
        );
        return [200, [
            'today' => $today,
            'followUps' => ['overdue' => (int) $counts['overdue'], 'dueToday' => (int) $counts['today'], 'items' => $due],
            'certificates' => $renewals->counts() + ['items' => array_slice($renewals->view('30'), 0, 10)],
            'enquiries' => ['new' => $newCount, 'items' => array_map(function (array $r) {
                $o = $this->enquiryOut($r);
                $o['message'] = mb_substr((string) $o['message'], 0, 160);
                return $o;
            }, $newEnquiries)],
            'sessions' => ['items' => array_map(fn (array $r) => $this->sessionOut($r), $sessions)],
        ]];
    }

    private function search(): array
    {
        $q = $this->q();
        if (mb_strlen($q) < 2) {
            throw HttpError::invalid('Type at least 2 characters.');
        }
        $like = Record::like($q);
        $people = array_map(fn (array $r) => [
            'id' => $r['id'], 'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'email' => $r['email'], 'organisationName' => $r['organisation_name'], 'status' => $r['status'],
        ], $this->rows(
            "SELECT p.id, p.first_name, p.last_name, p.email, p.status, o.name AS organisation_name FROM people p LEFT JOIN organisations o ON o.id = p.organisation_id
             WHERE CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR p.email_lower LIKE ? OR p.phone LIKE ? ORDER BY p.last_name, p.first_name LIMIT 10",
            [$like, Record::like(strtolower($q)), $like],
        ));
        $organisations = array_map(fn (array $r) => ['id' => $r['id'], 'name' => $r['name'], 'kind' => $r['kind']],
            $this->rows('SELECT id, name, kind FROM organisations WHERE name LIKE ? OR contact_email LIKE ? ORDER BY name LIMIT 10', [$like, Record::like(strtolower($q))]));
        $code = CertificateCode::normalise($q);
        $certificates = array_map(fn (array $r) => $this->certificateOut($r), $this->rows(
            "SELECT * FROM certificates WHERE status IN ('approved', 'withdrawn') AND (code = ? OR learner_name LIKE ?) ORDER BY completed_on DESC LIMIT 10",
            [$code ?? '', $like],
        ));
        return [200, ['people' => $people, 'organisations' => $organisations, 'certificates' => $certificates]];
    }

    /** Names for assignee pickers. No email addresses. */
    private function staff(): array
    {
        return [200, ['items' => array_map(fn (array $r) => ['id' => $r['id'], 'displayName' => $r['display_name'] !== '' ? $r['display_name'] : 'Unnamed'],
            $this->rows('SELECT id, display_name FROM users WHERE ' . self::CMS_USER . ' ORDER BY display_name, id'))]];
    }

    // ---------------------------------------------------------------- owner only: exports, audit, staff accounts

    private function exportPeople(): array
    {
        $rows = $this->rows('SELECT p.*, o.name AS organisation_name FROM people p LEFT JOIN organisations o ON o.id = p.organisation_id ORDER BY p.last_name, p.first_name');
        $csv = Csv::build(
            ['First name', 'Last name', 'Email', 'Telephone', 'Job title', 'Organisation', 'Status', 'Reminders off', 'Added'],
            array_map(fn (array $r) => [$r['first_name'], $r['last_name'], $r['email'], $r['phone'], $r['job_title'], $r['organisation_name'],
                $r['status'], (int) $r['reminders_opt_out'] === 1, LondonTime::dateOf((string) $r['created_at'])], $rows),
        );
        $this->log('export.people', 'export', null);
        return [200, new RawBody($csv, 'text/csv; charset=utf-8', 'people-' . $this->today() . '.csv')];
    }

    private function exportCertificates(): array
    {
        $today = $this->today();
        $rows = $this->rows(
            "SELECT c.*, o.name AS organisation_name FROM certificates c LEFT JOIN people p ON p.id = c.person_id LEFT JOIN organisations o ON o.id = p.organisation_id
             WHERE c.status IN ('approved', 'withdrawn') ORDER BY c.completed_on DESC"
        );
        $csv = Csv::build(
            ['Code', 'Name', 'Organisation', 'Course', 'Hours', 'Completed on', 'Issued on', 'Valid until', 'State'],
            array_map(fn (array $r) => [CertificateCode::display((string) $r['code']), $r['learner_name'], $r['organisation_name'], $r['course_title'],
                $r['course_hours'], $r['completed_on'], $r['issued_on'], $r['expires_on'],
                CertificateState::of((string) $r['status'], $r['revoked_at'], $r['expires_on'], $today)], $rows),
        );
        $this->log('export.certificates', 'export', null);
        return [200, new RawBody($csv, 'text/csv; charset=utf-8', 'certificates-' . $today . '.csv')];
    }

    private function auditLog(): array
    {
        return $this->page('a.*, u.display_name', 'audit_log a LEFT JOIN users u ON u.id = a.actor_id', '1=1', [], 'a.id DESC', fn (array $r) => [
            'id' => (int) $r['id'], 'at' => self::stamp($r['at']), 'actorName' => $r['display_name'], 'action' => $r['action'],
            'entityType' => $r['entity_type'], 'entityId' => $r['entity_id'], 'fields' => $r['fields'] === '' ? [] : explode(',', (string) $r['fields']),
        ]);
    }

    private function userOut(array $r): array
    {
        return ['id' => $r['id'], 'email' => $r['email'], 'displayName' => $r['display_name'], 'role' => $r['role'], 'status' => $r['status'],
            'linked' => $r['clerk_user_id'] !== null, 'lastSignInAt' => self::stamp($r['last_sign_in_at'])];
    }

    private function listUsers(): array
    {
        return [200, ['items' => array_map(fn (array $r) => $this->userOut($r), $this->rows('SELECT * FROM users ORDER BY role DESC, display_name, email'))]];
    }

    private function createUser(): array
    {
        $in = $this->input();
        $email = $in->value('email', 'email', ['required' => true]);
        $name = $in->value('displayName', 'text', ['required' => true, 'max' => 190]);
        $role = $in->value('role', 'enum', ['values' => ['admin', 'super_admin'], 'required' => true]);
        if (is_string($email) && $this->rows('SELECT id FROM users WHERE email_lower = ?', [$email]) !== []) {
            $in->error('email', 'This email address already has an account.');
        }
        $in->check();
        $id = Db::uuid();
        $this->pdo->prepare('INSERT INTO users (id, email, display_name, role) VALUES (?,?,?,?)')->execute([$id, $email, $name, $role]);
        $this->log('user.create', 'user', $id, ['displayName', 'email', 'role']);
        return [201, ['user' => $this->userOut(Record::find($this->pdo, 'SELECT * FROM users WHERE id = ?', [$id]))]];
    }

    /** The last active owner can never be demoted or disabled, and nobody changes their own role or status. */
    private function updateUser(string $id): array
    {
        $old = Record::find($this->pdo, 'SELECT * FROM users WHERE id = ?', [$id]);
        $in = $this->input();
        $v = [];
        if ($in->has('displayName')) {
            $v['display_name'] = $in->value('displayName', 'text', ['required' => true, 'max' => 190]);
        }
        if ($in->has('role')) {
            $v['role'] = $in->value('role', 'enum', ['values' => ['admin', 'super_admin'], 'required' => true]);
        }
        if ($in->has('status')) {
            $v['status'] = $in->value('status', 'enum', ['values' => ['active', 'disabled'], 'required' => true]);
        }
        $in->check();
        $changesAccess = (isset($v['role']) && $v['role'] !== $old['role']) || (isset($v['status']) && $v['status'] !== $old['status']);
        if ($changesAccess && $id === $this->user->id) {
            throw HttpError::conflict('self', 'You cannot change your own role or status. Ask another owner.');
        }
        $stillOwner = ($v['role'] ?? $old['role']) === 'super_admin' && ($v['status'] ?? $old['status']) === 'active';
        if ($old['role'] === 'super_admin' && $old['status'] === 'active' && !$stillOwner) {
            $others = (int) $this->rows("SELECT COUNT(*) AS n FROM users WHERE role = 'super_admin' AND status = 'active' AND id <> ?", [$id])[0]['n'];
            if ($others === 0) {
                throw HttpError::conflict('last_owner', 'There must always be at least one active owner.');
            }
        }
        Record::update($this->pdo, 'users', $id, $v);
        $this->log('user.update', 'user', $id, array_values(array_intersect_key(['display_name' => 'displayName', 'role' => 'role', 'status' => 'status'], $v)));
        return [200, ['user' => $this->userOut(Record::find($this->pdo, 'SELECT * FROM users WHERE id = ?', [$id]))]];
    }
}
