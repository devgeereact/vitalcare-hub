# Roadmap

Each slice is thin and end to end. One slice at a time. Each ends with `pnpm check`, PHP checks and its listed tests green, then a checkpoint. Nothing reaches the server without its own approval.

| # | Slice | Acceptance criteria | Status |
|---|---|---|---|
| 0 | Foundation | `docs/FOUNDATION-ACCEPTANCE` gate closed: repo, CI, backup with restore test, database and users, private config, `.htaccess` merged, `/api/health` live and reporting the real PHP version | PLANNED |
| 1 | Identity and authorisation core | `Jwt.php` ported with its tests; `users` table and first-sign-in linking; `Authz` with the five roles; unknown email gets 403 everywhere; super admin seed; sign-in screen (email code) and a "who am I" screen. Tests: token tamper, expiry, wrong `azp`, unknown user, role escalation attempt | PARTIAL: Jwt, resolver, Authz, `/api/health`, `/api/me`, migration 001 and 184 PHP assertions are DONE locally; the sign-in and account screens are built and tested with Clerk mocked; a real email-code sign-in is NOT TESTED; nothing is deployed |
| 2 | Front door (R1) | **Started (local):** design tokens, self-hosted fonts, logos, shell, claim-register build check, PWA icons. **Done locally:** certificate verify endpoint and page, rate limiter, contact form (queue only) and page. **Still to do:** SMTP outbox sender, maintenance cron, public pages with proven-only copy; claim-register build check; course list and detail from the database; contact form with Turnstile and outbox email; certificate verify page and endpoint with three states and rate limit; legal pages; replaces the coming-soon page | PARTIAL |
| 3 | Learner core (R2) | Enrol, lessons, progress, assessments with server-side grading and no answer-key leak, completion, certificate approval and PDF, expiry reminders by cron, in-app notifications by polling | PLANNED |
| 4 | Delivery (R3) | Sessions online and in person, bookings, attendance, trainer view, calendar and `.ics`, announcements, blog | PLANNED |
| 5 | Employers and orders (R4) | Organisation register (in date, due in 30 days, lapsed), group booking, orders by bank transfer with server pricing, coupons, invoices, exports, audit viewer | PLANNED |

## Later / deferred (decision per item)

Payroll, forums and Q&A, departments, learning paths, plan billing, library and files, learner reviews, AI authoring helper (staff only, no personal data), web push.

## Order logic

Slices 1 and 2 give the public a true site and a working verify page with no learner accounts. Slice 3 is the product. Slice 5 waits for payment details and VAT status, which only the owner can supply.
