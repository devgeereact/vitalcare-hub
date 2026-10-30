# Release plan: the first public release (E17)

Status: PLANNED. **Nothing in this plan has been done.** The backend, database, mail and cron are already live and tested (see `docs/VERIFICATION.md`); the public site at vitalcare.uk is still the earlier coming-soon page. This plan replaces it. Every step needs the owner's go; approving this plan approves the list, not the execution.

## Go / no-go gates (all must be true)

| # | Gate | State today |
|---|---|---|
| 1 | Every public page uses only `PROVEN` claims (`docs/claims.yml`), and the build check passes | Mechanism DONE; **content not written**: only Home, Check a certificate, Contact, Sign in, Account and 404 exist |
| 2 | At least the first approved courses exist with facts the owner approved (title, audience, format, duration, assessment, price, renewal) | NOT STARTED: needs the owner's data |
| 3 | A real email-code sign-in works on the live origin and links the super admin | NOT TESTED (can be tested now through local-live) |
| 4 | The `Authorization` header reaches PHP (proved by the sign-in above) | NOT TESTED |
| 5 | The CSP is tested in a real browser with sign-in and the contact form working under it | NOT STARTED |
| 6 | Privacy, cookie and terms pages drafted from the real data flows, and reviewed by the owner (and a solicitor for refunds) | NOT STARTED |
| 7 | Backups: the 02:20 run has produced a verified backup on its own, and a restore with data has been rehearsed | First scheduled run NOT yet seen; restore with real schema DONE |
| 8 | Accessibility checks on every page and the three journeys at 375 px: axe, keyboard, screen-reader pass | NOT STARTED |
| 9 | Rollback rehearsed | NOT STARTED |

If gate 1 or 2 is not met the release does not happen: shipping a thinner site than the current one would be a regression. An acceptable smaller first release is Home, certificate check, contact and the legal pages, **only if** the owner decides that beats the current page.

## Steps (each needs a named go)

1. **Freeze and build.** `pnpm check` green on the release commit; `pnpm build` with `VITE_CLERK_PUBLISHABLE_KEY` and `VITE_TURNSTILE_SITE_KEY` (both public). Note the commit and the build ID.
2. **Backup.** Pinned pre-release backup of the database (`backup.sh --pin`), and a copy of the live docroot (including `.htaccess`) to `~/vitalcare-backups/pre-release-<date>/`. Verify both.
3. **`.htaccess`.** Add the CSP and security headers with `scripts/merge-htaccess.py` (a diff is shown first; the origin lock stays byte-identical and first). The CSP must name `clerk.vitalcare.uk`, `cdn.protect.clerk.com`, `*.client.protect.clerk.com` (observed on several ports), `challenges.cloudflare.com`, `img.clerk.com` and `clerk-telemetry.com`; self-hosted fonts need no font host. Test in a real browser **before** applying (sign-in and contact form under the CSP).
4. **Upload.** `scripts/deploy-rsync.sh` dry run (checksum) shown to the owner, then `--go`: no `--delete`, `/.htaccess` excluded, entry files last.
5. **`robots.txt`.** Decide and state: the repository file says `Disallow: /`. At launch it must allow crawling of the public pages and still disallow `/app` and `/sign-in`; replace it only with the owner's go.
6. **Smoke tests** (below), then announce nothing until they pass.
7. **Clerk.** After the first real users exist: close public sign-up and enable bot protection (a Clerk write, D-011).

## Smoke tests (on https://vitalcare.uk after upload)

| Test | Expected |
|---|---|
| `/` , `/verify`, `/contact`, `/sign-in` | 200 and the new pages, not the holding page |
| Deep link `/verify/ABCDEFGHJKMN` | the verify page, `not issued` (no certificates yet) |
| `/api/health` | `{"status":"ok","php":"8.4.x","authConfigured":true}` |
| Contact form with a real submission | "Message sent"; one enquiry; one email at `info@` within about 2 minutes |
| Sign-in with the owner's address | reaches `/app`, role Super administrator |
| An unknown email signs in | the no-access message, no data |
| Response headers | CSP present, `nosniff`, no `Server` details beyond the host's |
| `curl` direct to the origin without `CF-RAY` | 403 (origin lock) |
| Service worker | installs; the API is never cached; an update shows the prompt |
| Lighthouse or axe on the public pages | no serious accessibility violations |

## Rollback

| If | Do |
|---|---|
| The site is broken | re-upload the previous build from the pre-release copy (`rsync` of that folder, no delete), restoring the saved `.htaccess` |
| Only the headers or CSP are wrong | restore the saved `.htaccess` from the pre-release copy |
| The database is affected | restore the pinned pre-release backup only after the owner approves; never drop or truncate live data without that |
| Mail misbehaves | remove the outbox cron line from our marked block (a crontab copy is saved before every change) |

## After release

Monitor `~/vitalcare-private/logs/` (`outbox.log`, `backup.log`, `php-error.log`); confirm the next 02:20 backup; check the Gmail DMARC reports; set up the owner's weekly off-server backup copy habit; retire the old project only with a separate approval (E18).

## What I will not do without a named go

Upload the site, replace `robots.txt`, change Clerk settings, or retire the old repository and its Cloudflare widget.
