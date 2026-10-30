# Current task

**Goal:** Plans approved (decisions D-006, D-008, D-010 to D-012 approved 30 Oct 2026). Slice 1 (identity and authorisation core) built locally. No external change made; every E-item in `docs/FOUNDATION-PLAN.md` still needs its own named go.
**Phase:** 05 Build (local only) · **Slice:** 1
**Status:** PARTIAL

## Changed this session (all inside this folder, plus one kit file)
- Slice 1: `backend/src` (Jwt, HttpError, Db, Role, User, Authz, SessionResolver, Migrator, Config, Api, Bootstrap), `public/index.php`, `bin/migrate.php`, `bin/seed-super-admin.php`, `migrations/001-users.sql`, test harness and 5 suites (184 assertions), `scripts/test-db.sh`, CI MariaDB service, `pnpm check` now includes the PHP checks. Local git repo created; first commit made.
- Scaffolded the project from the kit (`slug vitalcare-hub`, ports 5175/3175); `pnpm format` once; baseline `pnpm check` green.
- `docs/`: DISCOVERY, PRD, ARCHITECTURE, DECISIONS, ROADMAP, FOUNDATION-PLAN, RESTRUCTURE-PLAN, DESIGN, DEPLOYMENT, PROJECT-MEMORY, AUTHORISATION-MAP (162 policies + 9 storage + 13 rules), SCHEMA-MAP (70 tables), `claims.yml`, `history/` (source inventories, labelled history).
- `.gitignore` now tracks `backend/migrations/*.sql`; composer metadata; FTPS deploy files removed; `scripts/deploy-rsync.sh` added and tested on throwaway folders.
- Outside this folder: one row added to `~/Developer/build-kit/App-Kit/PORTS.md` (approved).
- Source project untouched.

## Checks run
| Command / check | Result |
|---|---|
| `pnpm check` (format, lint, typecheck, 12 frontend tests, PHP lint, 184 PHP assertions, build) | PASS, exit 0, seen 30 Oct 2026 |
| Mutation checks (azp, algorithm, relink, manager-as-staff) | each caught by the tests, originals restored |
| HTTP smoke of `/api/*` with `php -S` | PASS (unconfigured mode) |
| `gitleaks dir` on docs, src, backend, scripts, public, .github, .agent | PASS: no leaks found (no git repo yet, so history not scanned) |
| `scripts/deploy-rsync.sh` on temp folders | PASS: dry run changes nothing, `.htaccess` untouched, no deletes, entry files last |
| gitignore rule in a throwaway repo | PASS: migrations tracked, `.env` and dumps ignored |
| Semgrep, GitHub Actions run, real Clerk token, web PHP version | NOT RUN (no GitHub repo, no server change) |

## External changes made
None. (Read-only: SSH reads of the three Vitalcare folders, cPanel API listings, Clerk config read, Cloudflare zone read, Companies House read.)

## Blockers
- Owner decisions D-006 to D-012 in `docs/DECISIONS.md`, especially D-010 (tenant model) before slice 2.
- Owner-supplied: SMTP mailbox/sender, Turnstile, Semgrep token, accreditation proof, Harni's consent, payment details and VAT, retention periods.
- Web PHP version for the domain is UNKNOWN until `/api/health` runs.

## Next
Finish slice 1 locally: the sign-in screen (Clerk email code) and a signed-in "who am I" page; slice 2 design tokens, self-hosted fonts and the claim-register build check. E1 to E18 wait for named approvals.

## Next-session prompt
```
Continue in /Users/realmrgee/Developer/Projects/vitalcare-hub. Read AGENTS.md, .agent/PROJECT.yml and this file.
Check `git status` and `git log -3` before trusting this snapshot (the folder may not be a git repo yet).
Next task: slice 1 front end (sign-in with Clerk email code, who-am-I page), then slice 2. Acceptance: pnpm check green, 375 px layout checked, unknown email shows a no-access screen.
Pending approvals: E1 to E18 in docs/FOUNDATION-PLAN.md, D-006 to D-012. No deploy or deletion is implied by this handoff.
```
