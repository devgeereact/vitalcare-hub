# Foundation plan and external-change list

Status: PLANNED. **Nothing below has been done.** Each numbered item is a write outside this folder. Each needs its own "go" naming the item. A "go" covers exactly that item and nothing else. After each step I show the evidence (command and result, never a secret).

## Targets

| Thing | Value |
|---|---|
| Domain | vitalcare.uk (Cloudflare zone active, Free plan, Full strict, Always HTTPS on) |
| Docroot | `~/vitalcare.uk` on the cPanel account (shared with unrelated sites: every command names this path only) |
| Private tree | `~/vitalcare-private/{app,config,storage,tmp}` (new, mode 0700) |
| Backups | `~/vitalcare-backups/` (exists; holds the previous site's files, kept) with new `db/` and `files/` |
| Repo | `devgeereact/vitalcare-hub`, public since 30 Oct 2026 (D-013), default branch `main`; the earlier project's repo is untouched |
| Clerk | the existing production application and instance, key name `VITE_CLERK_PUBLISHABLE_KEY` |
| Ports (local) | frontend 5175, API 3175, local MariaDB for tests (throwaway) |
| Cost | none new. No paid service or plan change |

## Local work (no approval needed, in this folder)

L1 scaffold, install, baseline `pnpm check` (DONE: green, 12 tests). L2 planning documents (this set). L3 `.gitignore` fix so migrations are tracked, composer platform pin, CI and deploy scripts adapted to rsync. L4 first slices.

## External changes: approve item by item

| # | Change | Exact target | Evidence I will show | Rollback |
|---|---|---|---|---|
| E1 | **DONE 30 Oct 2026.** Created the private GitHub repo and pushed `main` (no force, no history from the old repo) | `devgeereact/vitalcare-hub` | `gh repo view`: PRIVATE, default branch `main`; `git ls-remote` head `fda4bd1` equals local; 3 commits, 1 branch, 0 workflow runs | repo can be deleted by you |
| E2 | **DONE 30 Oct 2026.** Actions is enabled on that repo only. While the repo was private GitHub refused to start jobs (account payment or spending-limit message); after the owner chose to make it public (D-013) jobs start. First CI run green: run 41056672715 on commit `9287530`. (History: new repos default to Actions ON; I had switched it off right after creation and back on for this item.) | `repos/devgeereact/vitalcare-hub/actions/permissions` | API response | disable again |
| E3 | **DONE 30 Oct 2026.** Set the public repository variable `VITE_CLERK_PUBLISHABLE_KEY` (the `pk_live_` value you gave) | that repo, Variables | `gh variable list` shows the one variable; `gh secret list` shows none | delete the variable |
| E4 | Repository secret `SEMGREP_APP_TOKEN`: **you** enter it in GitHub; I never see it | that repo, Secrets | secret name in the list | delete |
| E5 | **DONE 30 Oct 2026.** Pre-change backup of the live docroot files and `.htaccess` | `~/vitalcare-backups/pre-release-20261030-121438/` (mode 0700; `files/` plus `MANIFEST.sha256`) | 16 live files, 16 backed up, all 16 identical by SHA-256; the origin-lock block is present in the saved `.htaccess`. Live docroot was only read | none needed |
| E6 | **DONE 30 Oct 2026.** Created the private directories | `~/vitalcare-private/{app,config,storage,tmp,logs}` and `~/vitalcare-backups/{db,files}`, all mode 0700 | `ls -ld` shows `drwx------` on each; `vitalcare-private` is a sibling of the docroot, not inside it | remove the empty folders |
| E7 | **DONE 30 Oct 2026.** Created the MariaDB database `<account>_vch` and two users `<account>_vch_mig` (ALL on that database only) and `<account>_vch_run` (SELECT, INSERT, UPDATE, DELETE). Passwords were generated on the server, passed to the cPanel API on stdin and written straight to two 0600 files (`config/prod.php`, `config/backup.cnf`); never printed | the cPanel account | cPanel API lists the database with exactly those two users; both connect; `SHOW GRANTS` as expected; the runtime user is **denied** `CREATE TABLE`; config files are `-rw-------`; the old database was not touched | drop the new empty database and users |
| E8 | **DONE 30 Oct 2026.** `backup.sh` installed; verified backups; copied off-server and restored into a throwaway database **with the real schema** (7 tables, 4 migrations, identical checksums, CHECK constraints survive). Nightly cron installed at 02:20 server time (EDT): saved a copy of the crontab, appended one marked block, proved the other 20 lines byte-identical; ran the script under an empty environment as cron would | `~/vitalcare-private/app/bin/`, the user crontab | backup listing (3 automatic plus 1 pinned), `sha256sum -c`, restore test, crontab identity proof | remove our marked block (a copy of the previous crontab is in `~/vitalcare-private/logs/`) |
| E9 | **DONE 30 Oct 2026.** Merged our routing into the live `.htaccess` with `scripts/merge-htaccess.py` (refuses unless the origin lock is present; 19 tests). The diff was additive, 14 lines, inserted directly after the origin lock; the lock block hash is identical before and after; the live file's checksum matched the E5 backup immediately before the change; applied by atomic `mv`. **No CSP added yet**: it is added with the single-page app at launch and checked in a real browser | the docroot `.htaccess` only | public `/` still the coming-soon page; `/api/health` reaches PHP through Cloudflare; direct-to-origin requests without `CF-RAY` still return 403 | restore the saved copy from the E5 backup |
| E10 | **NOT DONE ON PURPOSE** (see D-015): the static site is not uploaded; the live coming-soon page stays until launch. (Note: `public/robots.txt` is `Disallow: /` until launch is approved; uploading it replaces the live file, which currently allows crawling.) Only the API shim was written (under E11). Upload the built site and the API shim with `rsync`
| E11 | **DONE 30 Oct 2026 for the code; web PHP version still wrong.** `scripts/deploy-api.sh` (dry run default; never deletes; refuses to overwrite a file it did not write; 34 tests) copied the API into the private tree and wrote the 5-line shim into the docroot `api/`. All 20 PHP files pass a syntax check under PHP 8.4; the deployed code, called directly under PHP 8.4, connected to the database (`authConfigured: true`). `config/prod.php` was written at E7. **Through the web the API answers on PHP 8.5.12, which has no `pdo_mysql`, so DB-backed routes return 503 until the owner selects PHP 8.4 for the domain in cPanel** | the private app tree and the docroot `api/index.php` | file checksums equal the repository; `/api/health` through Cloudflare | remove the shim |
| E12 | **DONE 30 Oct 2026.** Pinned pre-migration backup first, then `migrate.php` as the migration user applied 001 to 004; a second run applied nothing. Rehearsal: every migration runs on every local test run (MariaDB 13) and in CI (MariaDB 11.4, the server's major version) | the new database | the four recorded checksums equal the repository files; 7 tables; CHECK constraints present; runtime user can read and write but is denied DROP, ALTER, CREATE, TRUNCATE and DROP DATABASE; restore test passed | restore the pinned backup |
| E13 | **DONE 30 Oct 2026.** One `super_admin` row for the owner's address (no Clerk id until the first verified sign-in); a repeat run was refused. No other users exist | new database | `users` has exactly 1 row; other tables empty | delete the row |
| E14 | Cloudflare Turnstile widget for the contact form (free); site key is public, the secret goes into `config/prod.php` by you | Cloudflare account, zone `vitalcare.uk` | widget listing (key name only) | delete the widget |
| E15 | Cloudflare DNS for local-live: `local.vitalcare.uk` pointing to `127.0.0.1`, DNS only | zone `vitalcare.uk` | `dig` result | delete the record |
| E16 | Clerk: add `https://local.vitalcare.uk` and `https://vitalcare.uk` as allowed origins if not already present; later set sign-up to restricted and turn on bot protection after the first real users exist (D-011) | production instance only | config read-back | revert the setting |
| E17 | Replace the coming-soon page by the first release | covered by E10 | public URL shows the new build ID | rollback as E10 |
| E18 | Cutover housekeeping (separate approval, later): rename the old repo to `Vitalcare-Training-Hub-legacy`; optionally rename the new repo; retire the old project | GitHub | `gh repo view` | rename back |

Things only you do (I list, never act): delete the dead crontab line for `api/cron.php`; delete `~/vc_cron.log`, `~/vitalcare.uk/api/` stub and the stale `~/vitalcare.uk/error_log` after the new site is live; decide about `~/vitalcare-cs-staging` and the old database `<account>_vitalcare_uk` (take a cPanel backup download first); enter the SMTP mailbox password and Turnstile secret into the private config; enter `SEMGREP_APP_TOKEN` in GitHub; rotate or retire the old Supabase project's published passwords.

## Backups (FULL tier)

Three rolling verified backups plus one pinned pre-migration backup, outside the web root, order create then verify then mark complete then prune. A backup counts only after a restore into a scratch database has been tested (E8). Same-server backups do not survive losing the hosting account, so a copy of each weekly dump is downloaded to this Mac under `.local-state/backups/` on request (that folder is git-ignored). Backups are not yet in place: **NOT TESTED**.

## Rollback

Site: re-upload the previous build and the saved `.htaccess` from E5. Database: restore the pinned pre-migration backup. Config: remove `config/prod.php` and the API answers 503. Repo: nothing to roll back on the server.

## Foundation acceptance blockers today

- The web PHP version for the domain is UNKNOWN until `/api/health` runs. The server's default command-line `php` is currently 8.5 **without `pdo_mysql`** (it was 8.4 earlier today); PHP 8.2, 8.3 and 8.4 under `/opt/alt/` have it. The owner should select **PHP 8.4** for the domain in cPanel (Select PHP Version or MultiPHP). Cron and CLI tools must call `/opt/alt/php84/usr/bin/php` explicitly.
- No SMTP mailbox password, Turnstile keys or Semgrep token yet (all owner-supplied).
- Per-course approvals, accreditation proof and the certificate approver wording are `REQUIRED` in `docs/claims.yml`.
