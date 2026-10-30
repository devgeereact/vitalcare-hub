# Database schema: Vitalcare Training Hub

Changes only through numbered migration files in `backend/migrations/`; applied migrations are never edited (the runner stores each file's SHA-256 and refuses a changed one). The full source-to-target plan is `docs/SCHEMA-MAP.md`.

| Table | Purpose | Owner/tenant column | Notes |
|---|---|---|---|
| `schema_migrations` | applied files and checksums | none | created by the runner |
| `organisations` | employers (customers) | none | `status` active or disabled |
| `users` | who may use the system, and as what | `organisation_id` | role CHECK (5 roles); unique `clerk_user_id`; unique case-insensitive email; ids are UUIDv7 |

## Migration log

| # | File | Applied to | Date | Reversible? |
|---|---|---|---|---|
| 1 | `001-users.sql` | throwaway local test databases only (every test run) | 2026-10-30 | yes (drop the two tables; no data) |

Nothing has been applied to any server database.
