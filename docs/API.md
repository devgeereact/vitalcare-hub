# API: Vitalcare Training Hub

Base: `/api`. JSON in and out (except the PDF and CSV downloads). Errors: `{"error":{"code":"...","message":"..."}}`, plus `fields` on a 422; no stack traces, no internals. Every response is `Cache-Control: no-store`.

## Public

| Method | Path | Auth | Response | Errors | Status |
|---|---|---|---|---|---|
| GET | `/api/health` | none | `{"status":"ok","php":"<version>","authConfigured":true\|false,"release":"<12 hex or unknown>"}` | 405 | DONE locally; deployed |
| GET | `/api/verify/{code}` | none | `{"status":"valid\|expired","certificate":{code,learnerName,courseTitle,hours,completedOn,expiresOn,issuer}}`, or `{"status":"revoked","certificate":{code,courseTitle,completedOn,revokedOn,issuer}}` (no name, no reason), or `{"status":"not_issued"}` | 429 `rate_limited` (30 per 5 minutes per caller) with `Retry-After`; 503 | DONE locally |
| POST | `/api/contact` | none, Turnstile token | body `{name,email,phone?,message,turnstileToken,website}`; `201 {"received":true}` | 413; 422 with `fields`; 403 `verification_failed`; 429 (5 per hour); 503 if Turnstile is not configured | DONE locally |
| GET | `/api/courses` | none | `{"courses":[PublicCourse]}`, published only, in `sort_order` then title | 429 (120 per 5 minutes per caller); 503 | DONE locally (branch `feature/cms`) |
| GET | `/api/courses/{slug}` | none | `{"course":PublicCourse}` | 404 for unknown **and** unpublished (same answer); 429; 503 | DONE locally (branch) |

`PublicCourse` = `{slug,title,summary,durationText,hours,format,pricePence,priceNote,assessmentMethod,renewalMonths,accreditationNote}`. Nothing else (no id, no draft fields, no timestamps).

Verify rules: a code is 12 characters from a 31-character alphabet (no 0, 1, I, L or O) and is random. Unknown, malformed and draft codes all return the same `{"status":"not_issued"}`. Revoked means `status = 'withdrawn'` with `revoked_at` set. `expires_on` is the last day of validity; dates compare as Europe/London calendar dates.

## Signed in (CMS, D-018)

All need `Authorization: Bearer <Clerk session token>`. 401 for no or an invalid token; **403 `no_access`** for a valid identity with no active `admin` or `super_admin` row (unknown email, disabled user, or any other role); **403 `forbidden`** for an `admin` calling an owner-only route (marked **S**). Bodies are JSON objects up to 64 KB. Dates are `YYYY-MM-DD`; session times are Europe/London local `YYYY-MM-DDTHH:MM`. Lists take `?q=` (search text) and `?page=` (50 per page) and answer `{"items":[...],"total":n,"page":p}`.

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `/api/me` | A, S | `{"user":{id,email,displayName,role,organisationId}}` |
| GET | `/api/admin/dashboard` | A, S | `{followUps:{overdue,dueToday,items},certificates:{expiring30,expired90,items},enquiries:{new,items},sessions:{items}}` |
| GET | `/api/admin/search?q=` | A, S | up to 10 each of `people`, `organisations`, `certificates` (by name, email, phone or code); `q` is 2 to 100 characters |
| GET, POST | `/api/admin/people` | A, S | filter `?organisationId=`, `?status=active\|archived` |
| GET, PATCH | `/api/admin/people/{id}` | A, S | detail includes organisation, attendance history, certificates, notes, follow-ups |
| GET, POST | `/api/admin/organisations` | A, S | |
| GET, PATCH | `/api/admin/organisations/{id}` | A, S | detail includes staff with training status per course (in date, due in 30 days, expired), notes, follow-ups |
| GET, POST | `/api/admin/{people\|organisations}/{id}/notes` | A, S | notes are never edited or deleted |
| GET | `/api/admin/courses` | A, S | all courses, drafts included |
| POST | `/api/admin/courses` | **S** | refuses CQC approval wording (422) |
| GET | `/api/admin/courses/{id}` | A, S | |
| PATCH | `/api/admin/courses/{id}` | **S** | `published` true sets `published_at` |
| GET, POST | `/api/admin/sessions` | A, S | `?when=upcoming\|past\|all` |
| GET, PATCH | `/api/admin/sessions/{id}` | A, S | detail includes attendees and any certificate issued for each |
| POST | `/api/admin/sessions/{id}/attendees` | A, S | `{personId}`; 409 `full` at capacity, 409 `already_booked` |
| PATCH | `/api/admin/attendees/{id}` | A, S | `{attendance?, result?}` |
| GET | `/api/admin/certificates` | A, S | `?status=valid\|expired\|revoked` |
| POST | `/api/admin/certificates` | A, S from attendance; **S** by hand | `{attendeeId}`: attendance must be `attended` and result `pass` or `not_assessed`, else 409 `not_eligible`; 409 `already_issued`. `{personId,courseId,completedOn,reason}`: owner only, reason 10 to 500 characters. Expiry is computed on the server |
| GET | `/api/admin/certificates/{id}` | A, S | |
| POST | `/api/admin/certificates/{id}/revoke` | **S** | `{reason}` 10 to 500 characters; 409 `not_approved` if already revoked |
| GET | `/api/admin/certificates/{id}/pdf` | A, S | `application/pdf` attachment; 409 for a revoked certificate |
| GET | `/api/admin/renewals?window=30\|60\|90\|expired` | A, S | the newest certificate per person and course only; reminder stages already sent |
| GET, POST | `/api/admin/follow-ups` | A, S | `?scope=open\|mine\|done`, `?subjectType=&subjectId=` |
| PATCH | `/api/admin/follow-ups/{id}` | A, S | `{title?,dueOn?,assigneeId?,done?}` |
| GET | `/api/admin/enquiries` | A, S | `?status=new\|in_progress\|closed` |
| GET, PATCH | `/api/admin/enquiries/{id}` | A, S | `{status?,assigneeId?}`; detail includes notes and replies |
| POST | `/api/admin/enquiries/{id}/notes` | A, S | internal note |
| POST | `/api/admin/enquiries/{id}/reply` | A, S | `{body}`: queues one email to the enquirer (Reply-To the office), records it as a reply, moves `new` to `in_progress` |
| GET | `/api/admin/staff` | A, S | staff names and ids for assignee pickers (no emails for `admin`) |
| GET | `/api/admin/export/people.csv` | **S** | CSV attachment, formula-injection safe |
| GET | `/api/admin/export/certificates.csv` | **S** | same |
| GET | `/api/admin/audit` | **S** | newest first; ids, actions and field names only |
| GET, POST | `/api/admin/users` | **S** | staff accounts; role `admin` or `super_admin` only |
| PATCH | `/api/admin/users/{id}` | **S** | `{displayName?,role?,status?}`; the last active owner cannot be demoted or disabled |

Rules: validate every input server-side; actor, role and organisation come from the verified session and the `users` row, never the request. Every write records an `audit_log` row with field names only. The `Bearer` scheme is case-insensitive.
