# Roadmap

Each slice is thin and end to end. One slice at a time. Each ends with `pnpm check`, PHP checks and its listed tests green, then a checkpoint. Nothing reaches the server without its own approval. Re-scoped 31 Oct 2026 (D-018): the learning-platform slices are gone; the CMS replaces them.

| # | Slice | Acceptance criteria | Status |
|---|---|---|---|
| 0 | Foundation | repo, CI, backup with restore test, database and users, private config, `.htaccess` merged, `/api/health` live and reporting the real PHP version | PARTIAL (see `docs/FOUNDATION-PLAN.md`) |
| 1 | Identity and authorisation core | `Jwt.php` with its tests; `users` table and first-sign-in linking; `Authz`; unknown email gets 403 everywhere; super admin seed; sign-in screen (email code). Tests: token tamper, expiry, wrong `azp`, unknown user, role escalation attempt | DONE locally; a real email-code sign-in is NOT TESTED |
| 2 | Front door (R1) | design tokens, fonts, logos, shell, claim-register build check; verify endpoint and page; contact form with Turnstile and outbox email; SMTP sender; course list and detail **from the database**; legal pages; replaces the coming-soon page | PARTIAL: legal pages and launch (E17) remain |
| 3 | CMS core (R2) | only `admin` and `super_admin` sign in; people, organisations, courses (owner-only edits, published flag), sessions and attendance; audit log with no personal data; search; dashboard | branch `feature/cms` |
| 4 | Certificates and renewals (R2) | issue from attendance or by hand with a reason; server-side expiry incl. month-end; PDF; revoke with reason; verify shows Valid, Expired, Revoked, Not issued; renewals view (30, 60, 90 days, expired); reminders at 60, 30, 7 days through the outbox, idempotent per certificate and stage | branch `feature/cms` |
| 5 | Office tools (R2) | notes and follow-ups on people and organisations; enquiries inbox (status, assignee, notes, reply by email); CSV export (formula-injection safe, owner only); staff accounts (owner only) | branch `feature/cms` |

Status of slices 3 to 5 is kept in `.agent/CURRENT-TASK.md` and `docs/AUDIT.md` while the branch is open.

## Later / deferred (decision per item)

An employer or learner portal (the role values are kept), orders and invoices, calendar `.ics` files, payroll, learning paths, web push, AI helpers (staff only, no personal data). Each needs its own decision.

## Order logic

Slices 1 and 2 give the public a true site and a working verify page with no learner accounts. Slices 3 to 5 give the office its record. The public course pages read the CMS, so the site can launch only after the owner has entered and published the course facts.
