# Verification record

What has actually been run and seen. Anything not listed is NOT TESTED.

## Slice 1: identity and authorisation core (30 Oct 2026, local only)

| Check | Result |
|---|---|
| `pnpm check` (format, lint, typecheck, 12 frontend tests, PHP lint, 184 PHP assertions, build) | PASS, exit 0 |
| PHP suites on a throwaway local MariaDB 13 (port 33175, `.local-test/`), fresh database per suite | `000-jwt` 38, `010-session-resolver` 24, `020-authz` 82, `030-api` 21, `040-schema` 19: 184 passed, 0 failed |
| Mutation check on the token check | removing the `azp` test fails 4 tests; removing the algorithm test fails 1 (the other algorithm attacks are still caught by the signature check). Original restored, green |
| Mutation check on "no row, no access" | letting a second Clerk identity relink a linked email fails 3 tests |
| Mutation check on roles | counting `manager` as staff fails 5 tests |
| HTTP smoke with `php -S` on 3175, no private config | `/api/health` 200 and `authConfigured:false`; `/api/me` 503 with and without a token; unknown route 404; security headers present |
| `gitleaks dir` on docs, src, backend, scripts, public, .github, .agent | no leaks found |
| First commit scan (`gitleaks git --pre-commit --staged`) | no leaks found |

## Slice 1 front end and slice 2 foundations (30 Oct 2026, local only)

| Check | Result |
|---|---|
| `pnpm check` (content checks, lint, typecheck, 45 frontend tests, PHP lint, 184 PHP assertions, build) | PASS, exit 0 |
| Claim register enforcement | `claim("cpd_accreditation")` and `claim("cstf_alignment")` fail typecheck (two `@ts-expect-error` directives are accepted); `node scripts/claims.mjs --check` fails if the generated file is stale; PROVEN needs text, evidence and owner; REQUIRED must have empty text |
| Copy check (`scripts/check-copy.mjs`) | PASS on 24 files and 20 claims: no em-dash, banned word, hollow phrase, old-system trace or American spelling. It found three real em-dashes in starter files, now fixed |
| Sign-in form against the real `@clerk/react` v6 types | typechecks (`signIn.create`, `emailCode.sendCode`, `emailCode.verifyCode`, `finalize`). 13 tests with Clerk mocked: no password or social buttons, focus moves between steps, wrong code, unreachable service, no finalise unless the sign-in is complete, service error text never shown to the person |
| Account page | 8 tests: details shown, bearer header sent, 403 `no_access` shown as a message not an error, retry works, malformed response is an error never a user, signed-out redirect makes no API call, loading, sign out |
| Browser check (Chrome, local dev server) | home and sign-in render; fonts self-hosted and loaded (DM Sans 400/500/600, DM Serif Display 400); 0 requests to other hosts; no horizontal overflow at 373 px (checked in a 375 px-wide frame, because the window resize did not change the viewport) |
| Built `dist/` audit | no host other than documentation links; no trace of the earlier template or systems; 1.1 MB; the four logo files are byte-identical to the supplied ones (SHA-256 compared) |
| PWA icons | regenerated from the supplied round logo by `scripts/make-icons.sh`; the starter's placeholder icons are gone |

## Secrets, mail and the contact form, live (30 Oct 2026)

| Check | Evidence |
|---|---|
| Owner's first attempt | the two secrets were identical (same text pasted twice, 20 characters, not in Cloudflare's format); SMTP login failed with `535`; nothing was exposed. The config tool now refuses both mistakes (75 assertions) |
| Mail sender | the owner had no mailbox password to give, so I created a dedicated sender mailbox `noreply@vitalcare.uk` (50 MB quota) and generated its password **on the server**; it was written to the config through the config tool and never printed. `info@` is unchanged. A real TLS login and two messages were accepted by the mail server (to the office mailbox and to the owner's Gmail). Delivery to the inboxes is for the owner to confirm |
| Turnstile secret | fetched via the Cloudflare API, piped into the config tool; Cloudflare answered a bogus token with `invalid-input-response`, which means it recognised the secret |
| **End to end, live** | Chrome on `local.vitalcare.uk`: real widget token, form submitted, "Message sent"; the live API verified the token with Cloudflare; one enquiry stored (caller as a hash) and one outbox row; the **2-minute cron sent it by itself** at 14:14:14 UTC (`outbox.log`: `sent=1 retry=0 failed=0`); the visitor was the Reply-To. My test rows and probe script were then removed, leaving the database empty apart from the super admin |

Still NOT TESTED: delivery of the test mails to real inboxes (SPF, DKIM and DMARC alignment as seen by Gmail), the 02:20 backup's first scheduled run, a real sign-in, and the `Authorization` header reaching PHP.

## local-live and the secrets (30 Oct 2026, after the owner said "done")

| Check | Evidence |
|---|---|
| Server config after "done" | `turnstile.secret` NOT set and `smtp.password` NOT set (no new config backup was made, so nothing reached the server); the rest intact. The hosts entry for `local.vitalcare.uk` exists, so `local-live setup` did work |
| local-live | started by me; `https://local.vitalcare.uk/` returns 200; `/api/health` through it reports PHP 8.4.27 and `authConfigured: true` |
| Sign-in page on that origin | Chrome: Clerk loaded (`Clerk.loaded` true), the form shows "Send code", no console errors. No email address was entered (that would send the owner a code). A real sign-in is still NOT TESTED |
| Contact page on that origin | the form renders and Turnstile's script loaded; the submit button became enabled, which means the new widget issued a token on `local.vitalcare.uk`. Submitting is NOT TESTED: the server has no Turnstile secret, so the API answers 503 by design |

## Mail, config, clean-up and CI (30 Oct 2026)

| Check | Evidence |
|---|---|
| PHP version through the web | account default is 8.5.12 **without `pdo_mysql`** (the owner chose 8.5). A generated `api/.htaccess` pins PHP 8.4 for the API folder only: `/api/health` then reports `8.4.27` and `authConfigured: true`; `/api/verify/...` is a real database-backed 200 and `/api/me` is 401 |
| Turnstile | widget created via the Cloudflare API; the secret was not returned to me; its presence in the config is NOT yet true |
| Config tool | 64 assertions; four mutations caught (value echoed, allowlist removed, loose file mode, rejected value echoed). On the server: 0600, one backup, database section intact, secrets reported only as set or not set |
| SMTP client and outbox | 39 and 30 assertions with a scriptable fake server including a self-signed TLS one; six mutations caught (header-injection check, TLS verification, no-AUTH send, back-off, single-sender lock, raw error text). Two of my own tests were too weak at first (TLS verification mutation survived; the fake server died on a failed handshake) and were fixed; I also stopped logging exception messages |
| Mail on the server | NOT TESTED against the real mail server: the mailbox password is not yet set. The certificate of the server's SMTP host verified OK with `openssl` |
| Migration 005 | pinned backup first; applied; checksum equals the repository file; second run applies nothing |
| Cron | crontab changes are limited to our marked block and the one dead line; each proven by comparing with a saved copy |
| Clean-up | see D-016; old database restored with exact row counts in all 83 tables before it was deleted; the first restore attempt had stopped at 11 tables because of `DEFINER` clauses, which is why the restore step exists |
| Semgrep | local: 1,460 rules on 84 files, 0 findings. CI now scans with community rules when no token is set: 288 rules on 92 files, 0 findings |
| **CI red, then fixed** | pushes `c918f61` and `f70cee8` FAILED CI (two `deploy-api` tests): GNU rsync exits 3 on a dry run of a single file into a missing folder, which macOS rsync does not. I had reported `c918f61` as green without checking. Fixed by syncing that file as a directory with a filter (confirmed on the server's rsync 3.1.3) |

## Backend deployment (30 Oct 2026)

| Step | Evidence |
|---|---|
| Tools | `merge-htaccess.py` 19 tests; `deploy-api.sh` 34 tests with mutation checks (foreign `index.php` overwrite, `--delete`, mkdir in dry run all caught); in `pnpm check` and CI |
| API deploy | dry run listed 25 files; real deploy; 20 PHP files pass `php -l` under 8.4; six sampled checksums equal the repository; the shim holds no secrets |
| `.htaccess` | live checksum equalled the E5 backup immediately before; merge is additive (14 lines) after the origin lock, lock block hash identical, idempotent; applied atomically |
| Through Cloudflare | `/` still the coming-soon page (200); `/platform` SPA fallback (200); `/api/health` 200 JSON with `no-store`; `/api/nope` 404; direct-to-origin without `CF-RAY` 403 for `/` and `/api/health`; the stale docroot `error_log` returns 403; private-looking URLs return only the coming-soon page (no PHP, no config) |
| Fails closed | with the web PHP lacking `pdo_mysql`: `/api/me`, `/api/verify/...` and `/api/contact` all 503 |
| Migrations | pinned pre-migration backup first; 4 applied, second run none; checksums equal the repo; 7 tables; runtime user denied DROP, ALTER, CREATE, TRUNCATE, DROP DATABASE; restore of a fresh backup into a throwaway database: 7 tables, 4 migrations, identical checksums, constraint still refuses a bad role |
| Super admin | 1 row, `super_admin`, active, no Clerk id; a second seed attempt refused |
| Cron | crontab minus our block byte-identical to the saved copy; script run under `env -i` with `PATH=/usr/bin:/bin`: complete, checksums OK |
| CI on `132b0c0` | PASS; the log shows 298 PHP, 39 backup, 19 merge and 33 deploy assertions |

Defects found and fixed along the way: the deploy script created folders during a dry run (now tested); the backup script's first server run failed silently and could be overridden by another site's `~/.my.cnf` (both fixed earlier).

Still NOT TESTED: the web PHP version fix; the Authorization header actually reaching PHP (needs a real Clerk token); a real email-code sign-in; Turnstile server-side; the first scheduled cron run (due 02:20); the CSP; the single-page app on the domain.

## Server foundation (30 Oct 2026)

| Step | Evidence |
|---|---|
| E6 private directories | `ls -ld`: every folder `drwx------`; `vitalcare-private` is a sibling of the docroot |
| E7 database and users | cPanel API: database with exactly the two users; both connect (PHP 8.4, PDO mysql); `SHOW GRANTS` as designed; runtime user denied `CREATE TABLE`; `prod.php` and `backup.cnf` are `-rw-------`; secrets never printed; the old database untouched |
| E8 backup script (no cron yet) | `backup.sh` on the server equals the repository file (SHA-256 prefix match); first backup COMPLETE, `sha256sum -c` OK, files 0600 in a 0700 folder, no leftovers; copied to the owner's Mac and restored into a throwaway database (0 tables, because the database is empty) |
| Backup script tests | `backend/tests/backup.test.sh`: 39 assertions, now part of `pnpm check`; six mutations (truncated dump, table-count check, pinned backups pruned, partial folders left, loose credentials accepted, no lock) plus the option-file mutation are all caught |
| Defects found on the way | (1) the first server run failed **silently**: the dump error was discarded; it now prints the tool's error. (2) `mariadb-dump` connected as **another site's database user** because the account's `~/.my.cnf` overrides `--defaults-extra-file`; fixed with `--defaults-file` and covered by a test. (3) The server's default `php` is 8.5 without `pdo_mysql`. (4) Two of my first mutation tests were too weak and let mutants survive; fixed |
| CI on `152a95b` | PASS: all steps, 298 PHP assertions |

Not verified: a restore with real data; the nightly cron; the CI step that installs `mariadb-client` for the backup tests (added after that run, not yet run on GitHub).

## Verify, rate limiting and contact (30 Oct 2026, local only)

| Check | Result |
|---|---|
| PHP suites | 298 assertions, 0 failed: `050-verify` 50, `060-rate-limit` 14, `070-contact` 50, plus the earlier 184 |
| Frontend tests | 75 passed (8 files), including the verify and contact pages |
| `pnpm check` | PASS, exit 0 |
| Mutation checks | a draft or withdrawn certificate treated as issued fails 4 tests; UTC date instead of London date fails the British Summer Time test; removing the honeypot fails 2; Turnstile failing open when unconfigured fails 1; limiter off by one fails the suite. Originals restored, green |
| Real stack, local (React + Vite proxy + PHP built-in server + MariaDB 13, **synthetic data only**) | `/api/verify/` returned valid, expired and not issued correctly; a draft and an unknown code returned the identical not-issued answer; the three states rendered in Chrome with no horizontal overflow; the contact form loaded Cloudflare's script with its documented always-pass test key, issued a token, submitted through the page, stored one enquiry and queued exactly one email to the office with the visitor as Reply-To; rate-limit buckets are hashes |
| A test mistake found on the way | my hand-typed synthetic codes contained I, L and O, which the alphabet excludes, so the API correctly called them malformed |

Not verified: Turnstile server-side against Cloudflare (local bypass was used on the server); real email sending (queued only); the pages at a true 375 px viewport (checked at 300 px frames); screen reader and keyboard pass; CSP in a browser.

## GitHub Actions (30 Oct 2026)

| Check | Result |
|---|---|
| CI, run 41056672715 on `9287530`, ubuntu-latest, public repo | PASS, every step ran: pnpm install, content checks (3 proven, 17 required claims; copy ok), lint, typecheck, 45 frontend tests (5 files), 184 PHP assertions (5 suites) against the `mariadb:11.4` service container, build, PHP syntax check, gitleaks on git history and on `dist/`: no leaks |
| Why it had not run before | while private, GitHub refused to start jobs: "recent account payments have failed or your spending limit needs to be increased" |
| Semgrep | job completes but skips the scan (no token): NOT RUN as a scan |

## Not tested

Real Clerk tokens and JWKS from `clerk.vitalcare.uk`, and a real email-code sign-in (needs the live issuer, an allowed origin and an existing user; no Clerk users may be created); the sign-in form's appearance (it needs a Clerk key; only its logic is tested); the web PHP version on the server; the service worker; the CSP in a real browser; Semgrep: the workflow runs but its own log says `SEMGREP_APP_TOKEN is not set; skipping the scan`, so **no Semgrep scan has happened** (needs the owner's token, E4); Semgrep; backups and restore; any deployment; keyboard path and screen-reader pass; axe; 375 px on the sign-in form itself.
