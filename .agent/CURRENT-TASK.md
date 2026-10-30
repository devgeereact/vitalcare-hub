# Current task

**Goal:** Plans approved (decisions D-006, D-008, D-010 to D-012 approved 30 Oct 2026). Slice 1 (identity and authorisation core) built locally. No external change made; every E-item in `docs/FOUNDATION-PLAN.md` still needs its own named go.
**Phase:** 05 Build (local only) · **Slice:** 1 (done locally) and 2 (started)
**Status:** PARTIAL

## Changed this session (all inside this folder, plus one kit file)
- Slice 1: `backend/src` (Jwt, HttpError, Db, Role, User, Authz, SessionResolver, Migrator, Config, Api, Bootstrap), `public/index.php`, `bin/migrate.php`, `bin/seed-super-admin.php`, `migrations/001-users.sql`, test harness and 5 suites (184 assertions), `scripts/test-db.sh`, CI MariaDB service, `pnpm check` now includes the PHP checks. Local git repo created; first commit made.
- Scaffolded the project from the kit (`slug vitalcare-hub`, ports 5175/3175); `pnpm format` once; baseline `pnpm check` green.
- `docs/`: DISCOVERY, PRD, ARCHITECTURE, DECISIONS, ROADMAP, FOUNDATION-PLAN, RESTRUCTURE-PLAN, DESIGN, DEPLOYMENT, PROJECT-MEMORY, AUTHORISATION-MAP (162 policies + 9 storage + 13 rules), SCHEMA-MAP (70 tables), `claims.yml`, `history/` (source inventories, labelled history).
- `.gitignore` now tracks `backend/migrations/*.sql`; composer metadata; FTPS deploy files removed; `scripts/deploy-rsync.sh` added and tested on throwaway folders.
- Outside this folder: one row added to `~/Developer/build-kit/App-Kit/PORTS.md` (approved).
- Source project untouched.

- Front end: Clinical Ledger tokens, self-hosted DM Sans/DM Serif Display (`@fontsource`), supplied logos (SHA-256 identical), PWA icons regenerated from the round logo, shell (skip link, header, footer with the Ltd line), Home, Sign in (Clerk email code), Account, Not found. Claim register enforced at compile time (`scripts/claims.mjs`, `src/generated/claims.ts`) plus `scripts/check-copy.mjs`. Dependencies added: `@clerk/react`, `react-router-dom`, fontsource fonts; dev: `yaml`, jsdom, Testing Library.

- Verify, rate limiting and contact: migrations 002 to 004; `CertificateCode`, `CertificateVerifier`, `RateLimiter`, `Turnstile`, `ContactService`; routes `GET /api/verify/{code}` and `POST /api/contact`; `/verify`, `/verify/:code` and `/contact` pages; Turnstile widget; suites 050, 060, 070.

## Checks run
| Command / check | Result |
|---|---|
| `pnpm check` (content checks, lint, typecheck, 45 frontend tests, PHP lint, 184 PHP assertions, build) | PASS, exit 0, seen 30 Oct 2026 |
| Browser check at 375 px (framed), fonts, external requests | PASS: no overflow, 0 external requests; sign-in form appearance NOT TESTED (needs a Clerk key) |
| Mutation checks (azp, algorithm, relink, manager-as-staff) | each caught by the tests, originals restored |
| HTTP smoke of `/api/*` with `php -S` | PASS (unconfigured mode) |
| `gitleaks dir` on docs, src, backend, scripts, public, .github, .agent | PASS: no leaks found (no git repo yet, so history not scanned) |
| `scripts/deploy-rsync.sh` on temp folders | PASS: dry run changes nothing, `.htaccess` untouched, no deletes, entry files last |
| gitignore rule in a throwaway repo | PASS: migrations tracked, `.env` and dumps ignored |
| Semgrep, GitHub Actions run, real Clerk token, web PHP version | NOT RUN (no GitHub repo, no server change) |

## External changes made
- **E1 (approved: "go ahead with recomended")**, 30 Oct 2026: created private GitHub repo `devgeereact/vitalcare-hub`; switched Actions OFF for it (new repos default to ON); pushed `main` (3 commits, head `fda4bd1`). No other external change.
- **E5 (approved: "Yes")**, 30 Oct 2026: copied `~/vitalcare.uk` to `~/vitalcare-backups/pre-release-20261030-121438/files/` (16 files, SHA-256 identical, manifest saved). One scratch file I created in the server home during the dry run (`~/.vc_e5_target_tmp`) was removed in the same step.
- **E3 (approved: "yes")**: repository variable `VITE_CLERK_PUBLISHABLE_KEY` set (public key).
- **E2 (approved: "yes")**: Actions enabled on `devgeereact/vitalcare-hub`. Dependabot opened one PR (TypeScript 6 to 7, a major): left open, not merged.
- **Visibility (approved: "A", D-013)**: repository made PUBLIC after pushing the scrub commits; tip has no personal or server details, **history still does** (permanent). Then added `workflow_dispatch` to `ci.yml` and pushed (`9287530`); first CI run PASSED (41056672715).
- **Push (approved: "push")**: `main` now at `6dcbf5a`.
- **E6 (approved: "Approved")**, 30 Oct 2026: created `~/vitalcare-private/{app,config,storage,tmp,logs}` and `~/vitalcare-backups/{db,files}`, all 0700.
- **E7 (approved)**: created MariaDB database `<account>_vch` with users `_mig` (all rights on it) and `_run` (data only); credentials generated on the server and written to two 0600 files; never printed. Old database untouched.
- **E8 part 1 (approved)**: uploaded `backup.sh` to `~/vitalcare-private/app/bin/`; one backup run by hand and verified; copied to this Mac and restored into a throwaway database. **Crontab NOT changed** (asked for a separate go).
- **Push (approved)**: `152a95b`; CI green.
- **Owner delegated the judgement calls ("recommend the best practice and go ahead")**, 30 Oct 2026. Done under that: push of `132b0c0`; E11 (API code and shim); E9 (`.htaccess` merge); E12 (pinned backup, then migrations 001 to 004); E13 (one super admin row); E8 cron (marked block appended; crontab saved first; other lines proven identical). **Not done on purpose:** E10 static site (D-015), E14 Turnstile, E15 DNS, E16 Clerk, E17 launch, E18 cutover.
- **Owner answers 30 Oct (PHP 8.5 chosen; "get in cloudflare and do that"; "info@vitalcare.uk"; "Go ahead"; "you can delete what not needed")**: PHP pinned to 8.4 for the API folder (the 8.5 build has no database driver); new Turnstile widget created; config tool and SMTP sender built and deployed (migration 005 after a pinned backup); outbox cron added inside our block; non-secret SMTP and origin settings applied; the retired items in D-016 deleted after verified backups. **Waiting on the owner:** `turnstile.secret` and `smtp.password` (via `scripts/server-config-set.sh secret ...`), `local-live setup` (needs the Mac password). Semgrep token not needed for a scan now.
- **Secrets (owner had no values; asked me to create and set them)**, 30 Oct 2026: created mailbox `noreply@vitalcare.uk` with a server-generated password (config only); set the Turnstile secret from Cloudflare (it passed through the session; disclosed). Tested end to end live; test rows removed. The config tool now refuses a malformed Turnstile secret and duplicate secrets.
- Read-only reads: ( SSH reads of the three Vitalcare folders, cPanel API listings, Clerk config read, Cloudflare zone read, Companies House read.)

## Unpushed
Only this record is local; every push needs the owner's go.

## Blockers
- Semgrep needs `SEMGREP_APP_TOKEN` (owner enters it, E4); until then the scan is skipped. CI otherwise passes.
- Owner decisions D-006 to D-012 in `docs/DECISIONS.md`, especially D-010 (tenant model) before slice 2.
- Owner-supplied: SMTP mailbox/sender, Turnstile, Semgrep token, accreditation proof, Harni's consent, payment details and VAT, retention periods.
- Web PHP version for the domain is UNKNOWN until `/api/health` runs.

## Next
Slice 2 public pages (course list and detail, standards, contact form, verify page) need the approved course facts and proof from the owner; until then build the contact form and verify page shells against the API. E1 to E18 wait for named approvals (owner replied "Go ahead" without naming one; asked again for E1 and E5 by name).

## Next-session prompt
```
Continue in /Users/realmrgee/Developer/Projects/vitalcare-hub. Read AGENTS.md, .agent/PROJECT.yml and this file.
Check `git status` and `git log -3` before trusting this snapshot (the folder may not be a git repo yet).
Next task: slice 1 front end (sign-in with Clerk email code, who-am-I page), then slice 2. Acceptance: pnpm check green, 375 px layout checked, unknown email shows a no-access screen.
Pending approvals: E1 to E18 in docs/FOUNDATION-PLAN.md, D-006 to D-012. No deploy or deletion is implied by this handoff.
```
