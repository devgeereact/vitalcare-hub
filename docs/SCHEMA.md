# Database schema: Vitalcare Training Hub

Changes only through numbered migration files in `backend/migrations/`; applied migrations are never edited (the runner stores each file's SHA-256 and refuses a changed one). Migrations must stay backward compatible with the code still running (add, do not drop or rename), because the deploy gate migrates before it switches code. The earlier source-to-target plan is `docs/SCHEMA-MAP.md` (its learning tables are retired by D-018).

| Table | Purpose | Owner/tenant column | Notes |
|---|---|---|---|
| `schema_migrations` | applied files and checksums | none | created by the runner |
| `users` | staff who may sign in, and as what | `organisation_id` | role CHECK keeps 5 values (D-018: only `admin` and `super_admin` are let in by the API); unique `clerk_user_id`; unique case-insensitive email; ids are UUIDv7 |
| `organisations` | care homes and employers | none | `status` active or disabled; 006 adds `kind` (care_home, employer, other), contact name, contact email, phone, address, `reminder_copy` (copy renewal reminders to the contact) |
| `people` | learners (they never sign in) | `organisation_id` (nullable) | first and last name, optional email (case-insensitive index, not unique: a shared work address is possible), phone, job title, `reminders_opt_out`, `status` active or archived, `created_by` |
| `courses` | the public course facts, entered by the owner | none | unique `slug` (lowercase words and hyphens), title, summary, `duration_text`, `hours`, `format` (in_person, online, blended), `price_pence` and `price_note`, `assessment_method`, `renewal_months` (1 to 120, or null for no expiry), `accreditation_note`, `published` and `published_at`, `sort_order`. Public endpoints read only `published = 1` rows |
| `training_sessions` | a delivery of a course | `course_id` | `starts_at`/`ends_at` stored UTC (the API speaks Europe/London local time), venue or online link, `trainer_name`, `capacity` (1 to 500), `status` scheduled, cancelled, completed |
| `session_attendees` | who is booked on a session and what happened | `session_id`, `person_id` (unique together) | `attendance` booked, attended, no_show, cancelled; `result` pass, fail, not_assessed or null; `marked_at`, `marked_by` |
| `certificates` | issued certificates (immutable snapshots of name, course title and hours) | `person_id` (CMS) or `learner_id` (legacy, now nullable) | public only when `status = 'approved'`; unique random `code`; 008 adds `person_id`, `course_id`, `attendee_id` (unique: one certificate per attendance), `issued_on`, `issue_reason` (by-hand issues), `revoked_at`, `revoked_by`, `revoke_reason`. Revoked is stored as `status = 'withdrawn'` with `revoked_at` set. `expires_on` is computed on the server and is the last day of validity |
| `certificate_reminders` | one row per certificate and reminder stage (60, 30, 7) | `certificate_id` | primary key (certificate, stage) makes reminders idempotent; `recipients` counts the emails queued |
| `notes` | note history on a person, organisation or enquiry | `subject_type`, `subject_id` | `kind` note or reply (an email reply to an enquiry); never edited or deleted by the API |
| `follow_ups` | a task with a due date | `subject_type`, `subject_id` | title, `due_on`, `assignee_id`, `done_at`, `done_by`, `created_by` |
| `enquiries` | contact-form messages | none | caller stored as a SHA-256 only; 009 adds `assignee_id`, `closed_at` and allows `in_progress` and `closed` (old values kept, D-018 item 10) |
| `outbox_email` | queued outgoing email | none | status queued, sent or failed; sent by `bin/send-outbox.php` with back-off |
| `rate_limits` | fixed-window counters for public endpoints | none | bucket is a SHA-256 of name and caller |
| `audit_log` | who changed what | `actor_id` | action, entity type and id, and the **names** of changed fields only. No personal data, no values |

## Migration log

| # | File | Applied to | Date | Reversible? |
|---|---|---|---|---|
| 1 | `001-users.sql` | throwaway local test databases; production (E12) | 2026-10-30 | yes (drop the two tables; no data) |
| 2 | `002-certificates.sql` | same | 2026-10-30 | yes (drop the table; no data) |
| 3 | `003-enquiries.sql` | same | 2026-10-30 | yes (drop two tables) |
| 4 | `004-rate-limits.sql` | same | 2026-10-30 | yes (drop the table) |
| 5 | `005-outbox-backoff.sql` | same | 2026-10-30 | yes (drop the column and key) |
| 6 | `006-people.sql` | throwaway local test databases only | 2026-10-31 | yes while empty (drop `people`, drop the added organisation columns) |
| 7 | `007-courses-sessions.sql` | same | 2026-10-31 | yes while empty (drop three tables) |
| 8 | `008-certificates-cms.sql` | same | 2026-10-31 | yes while no CMS certificate exists (drop the added columns; `learner_id` back to NOT NULL only if every row has one) |
| 9 | `009-office.sql` | same | 2026-10-31 | yes while empty (drop `notes`, `follow_ups`, the added enquiry columns; the wider status check is harmless) |
| 10 | `010-reminders-audit.sql` | same | 2026-10-31 | yes (drop two tables) |

Migrations 006 to 010 have **not** been applied to any server database. Applying them needs a pinned backup and the owner's named go (the deploy gate does both when `activate-api` runs).
