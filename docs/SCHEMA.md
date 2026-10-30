# Database schema: Vitalcare Training Hub

Changes only through numbered migration files in `backend/migrations/`; applied migrations are never edited (the runner stores each file's SHA-256 and refuses a changed one). The full source-to-target plan is `docs/SCHEMA-MAP.md`.

| Table | Purpose | Owner/tenant column | Notes |
|---|---|---|---|
| `schema_migrations` | applied files and checksums | none | created by the runner |
| `organisations` | employers (customers) | none | `status` active or disabled |
| `certificates` | issued certificates (immutable snapshots of name, course title and hours) | `learner_id` | public only when `status = 'approved'`; unique random `code` |
| `enquiries` | contact-form messages | none | caller stored as a SHA-256 only |
| `outbox_email` | queued outgoing email | none | status queued, sent or failed; a cron sender is NOT yet built |
| `rate_limits` | fixed-window counters for public endpoints | none | bucket is a SHA-256 of name and caller; purged by a maintenance cron (NOT yet built) |
| `users` | who may use the system, and as what | `organisation_id` | role CHECK (5 roles); unique `clerk_user_id`; unique case-insensitive email; ids are UUIDv7 |

## Migration log

| # | File | Applied to | Date | Reversible? |
|---|---|---|---|---|
| 1 | `001-users.sql` | throwaway local test databases only (every test run) | 2026-10-30 | yes (drop the two tables; no data) |
| 2 | `002-certificates.sql` | same | 2026-10-30 | yes (drop the table; no data) |
| 3 | `003-enquiries.sql` | same | 2026-10-30 | yes (drop two tables) |
| 4 | `004-rate-limits.sql` | same | 2026-10-30 | yes (drop the table) |

Nothing has been applied to any server database.
