# Audit record

One audit file for this project. Every audit, check or review adds a dated section at the top; nothing is rewritten. Open work lives in `.agent/CURRENT-TASK.md`.

## 31 October 2026: CMS build (D-018), branch `feature/cms`, local only

- **Scope:** re-scope from a learning platform to a CMS for the owner (`super_admin`) and office staff (`admin`). Docs, migrations 006 to 010, backend, front end, PDF. Nothing pushed, deployed or applied to any server database; no Clerk, Cloudflare or GitHub setting touched.
- **Database used:** the project's throwaway local MariaDB (`scripts/test-db.sh`, 127.0.0.1:33175, databases `vitalcare_test*` only). One extra throwaway database `vitalcare_test_browser` was created for the browser check and dropped afterwards.

### Evidence (DONE, seen 31 Oct 2026)

| Check | Result |
|---|---|
| `pnpm check` before every commit (content checks, Biome, typecheck, Vitest, PHP lint, PHP suites, backup, deploy-gate and script tests, build) | PASS, exit 0, before each of the commits on the branch |
| PHP suites | 1,294 assertions in 20 suites (was 452 in 11). New: 105 schema (48), 110 permissions route by route (480), 120 people and organisations (39), 130 courses and public pages (36), 140 sessions (33), 150 certificates, expiry, verify, PDF (81), 160 renewals and reminders (39), 170 office (39), 180 CSV, exports, audit, staff accounts (44); 030 updated |
| Front-end tests | 114 tests in 12 files (was 75): courses parsing and pages, admin gate and owner-only nav, form conversion and field errors, verify Revoked |
| Permissions | every `/api/admin` route: anonymous 401; unknown email, trainer, manager, learner and disabled admin 403 `no_access`; admin 403 `forbidden` on the six owner-only permissions; owner let through; a coverage check fails if a new route has no case |
| Mutation checks (`.local-test/mutate.py`, local only) | 24 of 24 mutations caught, sources byte-identical afterwards: course writes, revoke and export opened to admin; trainer let into the CMS; unknown permission allowed; CMS gate removed; by-hand issue without the owner check; route permission skipped; expired on the last day; withdrawn always revoked; revoked answer leaks the name; no month-end clamp; expiry off by a month; expiry from the issue date; a fail certified; reminder marker ignored; stage boundary moved; renewed certificates reminded; opt-out ignored; CSV prefix removed; CSV leading-space formula missed; drafts public; public rate limit removed; audit storing values |
| HTTP smoke through `public/index.php` with `php -S` against a throwaway database | `/api/courses` lists published only; a draft slug is 404; `/api/verify/<revoked>` answers `revoked` with no name; `/api/admin/people` without a token is 401 |
| Real browser (desktop app browser pane) at 375 px against Vite and the throwaway API | course list and detail show the database facts; verify shows Revoked with no name; no horizontal overflow; nav has no learner sign-in, footer has "Staff sign in"; `/admin` without a Clerk key says sign-in is not set up |
| Certificate PDF | rendered by macOS (`sips`) to PNG and inspected: layout correct, A4 landscape; xref offsets, stream length and escaping checked by tests |
| gitleaks pre-commit hook | ran on every commit, no leaks |

### NOT TESTED

- A real Clerk email-code sign-in into `/admin` and every CMS screen in a browser (needs a Clerk key locally or local-live). The screens are covered by unit tests of the shared parts and the API by suites 110 to 180 only.
- Downloads (PDF, CSV) through the real front controller over HTTP with a token (the `RawBody` path in `public/index.php`); the API side is tested.
- Reminder emails actually delivered by SMTP; the queueing is tested, the sender was tested before (suite 100).
- Migrations 006 to 010 on a copy of the production database (only fresh throwaway databases). MariaDB on the server is 11.4; locally Homebrew's MariaDB.
- PDF in Adobe Reader, Chrome and on paper; non-Latin names print as "?" (WinAnsi fonts).
- axe or screen-reader runs on the CMS screens; keyboard use checked by structure only (labels, buttons, native controls).
- Load: lists page at 50 rows; the organisation and course pickers in forms load only the first 50 of each.
- Semgrep (no token), CI on GitHub (nothing pushed).

### Decisions where the docs and the request pulled apart (safer option taken; D-018 "chosen" items)

Owner-only course edits, by-hand certificates, revocation, exports, audit log and staff accounts; expiry from the training date; Revoked shown publicly without name or reason; PDF drawn on request (replaces D-007's stored PDF); trainer, manager and learner roles kept in the schema but refused at sign-in; enquiry statuses widened without rewriting rows; CQC approval wording refused in course text.

## 30 October 2026: release route

- Scope: deploy route alignment with the kit (SSH, no FTP).
- Finding: `deploy.yml` stays manual with owner approval (D-017) until the cutover replaces the live Vitalcare site; turning on deploy-on-push is part of the cutover.
- Result: no change made.
