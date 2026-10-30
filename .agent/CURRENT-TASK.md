# Current task

**Goal:** Phases 0 to 3 of the Route B rebuild (inventory, purpose, brand, architecture) are written. Awaiting owner answers and the first named "go" before any external change.
**Phase:** 03 Foundation (planning) · **Slice:** none started
**Status:** PARTIAL

## Changed this session (all inside this folder, plus one kit file)
- Scaffolded the project from the kit (`slug vitalcare-hub`, ports 5175/3175); `pnpm format` once; baseline `pnpm check` green.
- `docs/`: DISCOVERY, PRD, ARCHITECTURE, DECISIONS, ROADMAP, FOUNDATION-PLAN, RESTRUCTURE-PLAN, DESIGN, DEPLOYMENT, PROJECT-MEMORY, AUTHORISATION-MAP (162 policies + 9 storage + 13 rules), SCHEMA-MAP (70 tables), `claims.yml`, `history/` (source inventories, labelled history).
- `.gitignore` now tracks `backend/migrations/*.sql`; composer metadata; FTPS deploy files removed; `scripts/deploy-rsync.sh` added and tested on throwaway folders.
- Outside this folder: one row added to `~/Developer/build-kit/App-Kit/PORTS.md` (approved).
- Source project untouched.

## Checks run
| Command / check | Result |
|---|---|
| `pnpm check` (format, lint, typecheck, 12 tests, build) | PASS, seen twice on 30 Oct 2026, the second time after all doc and script changes |
| `gitleaks dir` on docs, src, backend, scripts, public, .github, .agent | PASS: no leaks found (no git repo yet, so history not scanned) |
| `scripts/deploy-rsync.sh` on temp folders | PASS: dry run changes nothing, `.htaccess` untouched, no deletes, entry files last |
| gitignore rule in a throwaway repo | PASS: migrations tracked, `.env` and dumps ignored |
| PHP checks, Semgrep, gitleaks | NOT RUN (no PHP code yet; no git repo yet) |

## External changes made
None. (Read-only: SSH reads of the three Vitalcare folders, cPanel API listings, Clerk config read, Cloudflare zone read, Companies House read.)

## Blockers
- Owner decisions D-006 to D-012 in `docs/DECISIONS.md`, especially D-010 (tenant model) before slice 2.
- Owner-supplied: SMTP mailbox/sender, Turnstile, Semgrep token, accreditation proof, Harni's consent, payment details and VAT, retention periods.
- Web PHP version for the domain is UNKNOWN until `/api/health` runs.

## Next
Local `git init` and first commit (needs owner confirmation), then slice 1 (identity and authorisation core) written and tested locally against a throwaway MariaDB, while E1 to E16 in `docs/FOUNDATION-PLAN.md` wait for named approvals.

## Next-session prompt
```
Continue in /Users/realmrgee/Developer/Projects/vitalcare-hub. Read AGENTS.md, .agent/PROJECT.yml and this file.
Check `git status` and `git log -3` before trusting this snapshot (the folder may not be a git repo yet).
Next task: slice 1, identity and authorisation core. Acceptance: Jwt tests pass; unknown email gets 403; five roles in Authz; pnpm check and PHP tests green on a throwaway local MariaDB.
Pending approvals: E1 to E18 in docs/FOUNDATION-PLAN.md, D-006 to D-012. No deploy or deletion is implied by this handoff.
```
