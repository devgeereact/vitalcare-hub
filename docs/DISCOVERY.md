# Phase 0 — Inventory and truth (Route B rebuild of Vitalcare Training Hub)

Date: 30 Oct 2026. Mode: PREPARE_ONLY, read-only. SOURCE: `~/Developer/Projects/Vitalcare-Training-Hub` (not modified; `git status` shows only the pre-existing `supabase/.temp/cli-latest` change). DESTINATION: not created yet (name follows the brand in Phase 2).

Supporting files (back-end inventory, claims audit, policy inventory, route tables) are kept privately, not in this repository.

Status words: DONE (evidence) · PARTIAL · NOT TESTED · BLOCKED · PLANNED · ASSUMPTION.

---

## 1. Your brief, checked line by line

| Your statement | Finding | Status |
|---|---|---|
| One Vite + React 19 + TS app serves site and `/platform`, forked from pulse-ui-react | True. `src/routes/index.tsx`: marketing, auth, `/platform` in one router. `LICENSE.md` is the PulseUI Core licence (Codervent). | DONE |
| About 70 tables | 70 `CREATE TABLE` in public, none dropped. Only 56 are referenced by `src/`; 6 have no consumer anywhere. | DONE |
| 95 migrations | 95 files. Numbering gaps 043-045, two files prefixed 084, six seed/demo migrations. | DONE |
| 207 RLS policies | 205 `CREATE POLICY` statements and 216 `DROP POLICY`. Replaying them gives **171 effective policies** (162 public, 9 storage). Four tables have RLS on and no policy (deny-all by design). | DONE for the static count; **ASSUMPTION** that live matches (no live access) |
| 24 edge functions | 24 folders = `_shared` + **23 functions**. | DONE |
| About 190 direct `supabase.*` calls | 158 direct calls in 52 files, plus the `callRpc` wrapper (21 distinct RPCs). | DONE |
| Launch is blocked | True. `docs/LAUNCH-READINESS.md`: "not ready", six non-code blockers (published super_admin password not rotated, live DB state unconfirmed, email sending unconfirmed, payment details empty, clinical approval outstanding, no restore rehearsed). | DONE |
| Clerk app and production instance | production instance, publishable key matches yours. Email code is the only sign-in and verification strategy; password off; every OAuth provider off; session claim `email` = `{{user.primary_email_address}}`; domain `vitalcare.uk` with `clerk.` and `accounts.` frontends. Sign-up mode is **public**. Bot protection **off**. First/last name **disabled** (names must live in our database). Session token lifetime 60 s, maximum 7 days. | DONE (read via Clerk API) |
| the owner's address exists as a Clerk user | Not checked: listing users returns personal data and you already told me. | NOT TESTED |
| Cloudflare: five Clerk CNAMEs, grey cloud | Confirmed: `clerk`, `accounts`, `clkmail`, `clk._domainkey`, `clk2._domainkey`, all DNS-only. Zone active, Free plan, SSL **Full (strict)**, Always Use HTTPS on. Apex and `www` proxied to the origin server address. MX is Namecheap (`jellyfish.systems`); SPF, DKIM, DMARC (`p=quarantine`) present. | DONE |

## 2. Systems — EXISTS / NEW / UNKNOWN / BLOCKED

| System | State | Evidence |
|---|---|---|
| Source repo `devgeereact/Vitalcare-Training-Hub` | EXISTS, **public**, default branch `production`, many stale remote branches (dependabot ×11, dev, update/2026-10-08, chore/add-gsap-lenis) | `git remote -v`, `git branch -a` |
| New private GitHub repo | NEW | Not created. `gh` is logged in as `devgeereact` (scopes repo, workflow). |
| cPanel account `devgeereact` @ the shared cPanel host | EXISTS. **Shared with unrelated sites** (flightdeck, gakinz, egolimited, doyouknow and others). PHP CLI 8.4.27; extensions curl, fileinfo, gd, imagick, intl, mbstring, openssl, pdo_mysql, zip; **no `sodium`/`mysqli` listed** | SSH read-only |
| `~/vitalcare.uk` docroot (800 KB) | EXISTS. A **"Coming soon" holding page** (index.html 23 Oct), `sw.js`, `robots.txt`, `logos/`, six assets, an `api/uploads/course-media/banners/` stub and a stale `error_log` (July: `config.php` parse error). Every path returns 200 (SPA fallback); `/sitemap.xml` serves HTML though `robots.txt` points at it. | SSH + curl |
| Live `.htaccess` | EXISTS. Top block = **Cloudflare origin lock** (403 unless `CF-RAY` header present, except `/.well-known/`), then SPA rewrite, cache headers, deny rules. No CSP header yet. Must be merged, never overwritten. | SSH read |
| `~/vitalcare-cs-staging` | EXISTS. Copy of the same coming-soon page (704 KB), not wired to a domain I could see (UNKNOWN). | SSH |
| `~/vitalcare-backups` | EXISTS. `live-20261023-0447.tar.gz` (39.7 MB) plus unpacked copy: the **previous static site files**, with no database dump. | SSH |
| Server cron | EXISTS: `15 7 * * * php ~/vitalcare.uk/api/cron.php expiry_warnings`. **`cron.php` does not exist**; `~/vc_cron.log` shows "Could not open input file" every day. A dead job from an earlier PHP build. Needs your decision to remove. | SSH |
| MariaDB database / user for Vitalcare | **UNKNOWN / BLOCKED.** The default client login on this account belongs to another site and was denied. I did not look for other credential files. I need you to confirm whether a Vitalcare database exists, or approve creating one. | SSH |
| Live Supabase the old Supabase project (reference omitted) | EXISTS (per docs), **BLOCKED by instruction** (no data reads). Whether migrations 092-097 are applied is UNKNOWN. | `docs/LAUNCH-READINESS.md` §2.2 |
| Clerk | EXISTS (above) | API |
| Cloudflare zone | EXISTS (above) | API |
| Resend | UNKNOWN. Used by edge functions; no key inspected. New stack can use cPanel SMTP (see §6). | code |
| Zoom, Google (Drive, Calendar, Meet, OAuth), Gemini, OpenRouter, OpenWeather | EXISTS in code, UNKNOWN whether live credentials work. Recommended: cut all but Gemini. | `inv-backend.md` |
| Local: PHP 8.5.12, composer, MariaDB 13, node 24.22.1, pnpm, gitleaks, semgrep, gh, rsync | EXISTS. **No Docker, no Postgres** (so the Supabase local stack cannot be run; policies were replayed statically). `cpanel-deploy` is **not installed**; `local-live` is. Server PHP 8.4 vs local 8.5: composer platform must pin 8.4. | `which` |
| Ports | NEW: next free row is **75** (frontend 5175, API 3175). | `PORTS.md` |

## 3. Screens and routes (117 route entries)

18 public marketing, 20 auth/error, 78 under `/platform`, 1 public 404. Full table: `tbl_marketing.md`, `tbl_auth.md`, `tbl_platform.md`.

Result: **KEEP 74 · CUT 22 · UNKNOWN 14 · REBUILD 2.**

- **CUT (22):** all six `/auth/basic/*` pages (Acme Inc. template, forms do nothing, live in production), `/auth/cover/verify-email` and `password-reset-success` (Acme), four duplicate `/auth/cover/*` mounts, three `/error/*` template demos, and platform aliases (`courses/manage`, `my-sessions`, `holidays`, `inbox`, `inbox/:id`, `analytics/org`, `account/password`) plus the `ModuleComingSoon` catch-all. Clerk replaces every auth page.
- **REBUILD (2):** dashboard (992 lines, client weather key) and calendar (789 lines, bound to Zoom/Meet/AI).
- **UNKNOWN (14), need your scope decision:** payments and fees (plan billing with no processor), payroll, in-app webmail (1,199 lines), forums, Q&A wall, departments, learning paths, library, file manager, certificate verify inside the platform (duplicates the public one).
- **KEEP (core):** learners, courses and builder, lesson player, assessments and results, sessions, calendar, attendance, certificates and templates, compliance matrix, reports, analytics, notifications, announcements, store/orders/coupons, invoices, virtual training, 1:1, feedback, settings, audit, cohorts, staff, users, profile, blog admin, and the 18 public pages (copy needs rewriting).
- Roles in code: **7** (`super_admin, admin, manager, trainer, content_editor, learner, guest`), not 4 as `CLAUDE.md` says. Guards are client-side only; the data layer was the real control.
- `ImportLearnersDialog` is built but never wired (learner CSV import).

## 4. Data: tables, policies, functions

70 tables. Proposed disposition (pending your scope answers in Phase 1):

| Group | Tables | Proposed |
|---|---|---|
| Identity and org | profiles, organisations, departments(?), department_members(?), department_tasks(?) | REBUILD (profiles → `users` keyed by `clerk_user_id`; departments UNKNOWN) |
| Catalogue and learning | courses, course_categories, modules, lessons, course_faqs, course_prerequisites, course_resources, course_reviews, learning_paths(?), learning_path_courses(?), cohorts, cohort_members | KEEP / REBUILD |
| Assessment | assessments, questions, question_options, assessment_attempts, attempt_answers (no consumer in `src`; used by SQL grading) | KEEP, grading logic moves to PHP |
| Enrolment and progress | enrollments, lesson_progress | KEEP |
| Sessions and attendance | training_sessions, session_bookings, session_join_requests, attendance_records, calendar_events, org_holidays, resource_allocations(?), trainer_profiles | KEEP |
| Zero consumer | recurring_templates, trainer_availability, session_reminders (cron-internal), coupon_redemptions (SQL-internal), app_config | CUT, except two SQL-internal ones that fold into the PHP logic |
| Certificates and compliance | learner_certificates, certificate_templates, certificate_expiry_alerts, staff_training_records, staff_training_requirements | KEEP (core of the product) |
| Commerce | products, orders, order_items, coupons, invoices, subscription_plans(?), subscriptions(?) | KEEP orders/coupons/invoices if you sell online; plans UNKNOWN |
| Comms | notifications, announcements, announcement_acks, reminders, messages(?), forum_*(?), blog_posts, blog_post_likes, feedback_responses, one_to_one_requests, email_campaigns(?) | mixed, see scope questions |
| Payroll | payroll | UNKNOWN |
| Integrations and mail | user_mail_accounts, mail_messages, google_oauth_tokens, integration_settings, push_subscriptions, ai_conversations | **CUT** (plaintext mailbox passwords, token tables, Drive/Google, web push v1) except AI log if AI is kept |
| Audit | audit_logs | KEEP |

Policies, helpers, functions: `history/policy-inventory.md`. Facts that shape Phase 3:

- 171 effective policies; **94 call `is_staff()`**, 31 `is_admin()`, 20 `private.staff_for()`. In practice the model is: own rows, staff sees all, admin writes, a few org-scoped rules (`same_org`, `is_department_member`).
- 154 of 162 policies are `TO public` (default role) and rely on `auth.uid()`; this ports cleanly to one `authorise()` per resource in PHP.
- 71 SQL functions (63 SECURITY DEFINER) and 24 triggers carry **most business logic** (certificate issue/approve/verify, grading, course completion, order pricing, notifications). This, not the edge functions, is the bulk of the rebuild.
- Answer keys are locked down by migrations 067/068/077; the PHP port must never send them to learners.
- Migration 094 fixed browser-supplied order prices; the PHP port keeps server-side pricing.

## 5. Edge functions, integrations, cron, email, storage, AI

Detail and file:line evidence: `inv-backend.md`. Summary:

| Item | Verdict |
|---|---|
| Keep and rebuild as PHP endpoints (about 10 groups): admin-create-learners, contact-form, one-to-one-email, send-email (reduced), ai-chat, files API, verify endpoint, notifications, plus certificate-expiry and session-reminder crons | REBUILD |
| Cut: qr-login, user-mail, imap-sync (+ `scripts/imap-pull.mjs`, GitHub workflow), integrations, gcal-sync-event, gmeet-create-event, drive-upload/list/delete, both Google callbacks, zoom-create-meeting, zoom-sync-session, send-push, process-certificate-expiry (wrapper), process-profile-reminders, process-email-drip (defer), OpenRouter fallback, OpenWeather, AI reply to learner/email | CUT |
| Cron | Only two pg_cron jobs exist (certificate expiry 08:00 daily; session reminders every 15 min). Three other processors have **no schedule in the repo** (UNKNOWN). Expiry alerts are in-app only despite comments saying "emailed". |
| Emails actually sent | Three types only: contact enquiry, 1:1 confirmation, staff broadcast. No welcome, certificate, reminder, invoice or payslip email exists. `CLAUDE.md`'s `src/lib/email/*.ts` templates do not exist. |
| Storage | Buckets: `course-media` (**public**, holds DM attachments, avatars, inbound mail attachments), `files` (private), `certificate-signatures` (public). All three → one private folder outside the web root, served by an authenticated endpoint. |
| Realtime | Two channels; notifications already poll every 60 s. Polling replaces both. |
| AI | Gemini + OpenRouter via one proxy, about 25 call sites; two send personal data to US processors; the system prompt embeds unverified claims; the course generator defaults `cstf: true` and appends "Overseen by Harni Muharami RN MSc". Recommend: Gemini only, staff only, no personal data, human review state. |

**Security findings in the source** (all stay out of the rebuild; first five also need action on the live project because they are in a public repo):

1. Two published passwords in git history: `CLAUDE.md` test accounts and migration 003 (super_admin), plus a test password in a comment in `032_seed_dummies.sql`. Rotation status UNKNOWN. This does not affect the new stack (Clerk email code, no passwords) but the Supabase project stays exposed until rotated or retired.
2. `supabase/.temp/*` (project ref, pooler URL) is tracked in a public repo.
3. Supabase URL and publishable key are hard-coded in `src/lib/supabase/client.ts` and `CLAUDE.md` (publishable keys are public by design; the fallback pointing a local build at production is the hazard).
4. OpenWeather key hard-coded in `src/lib/integrations/weather.ts:5` (third-party key in client code).
5. Plaintext mailbox passwords (`user_mail_accounts.smtp_pass`), user-supplied SMTP/IMAP host (SSRF), callable by any learner.
6. Provider secrets stored in a plaintext table (`integration_settings`); public Drive links for recordings; broadcast email exposes up to 50 recipients in one `To`.
7. Push trigger sends no auth header (probably not delivering); Turnstile has no widget and fails open.
8. No gitleaks or Semgrep in the source (house standard requires both).

## 6. Template leftovers and clutter (nothing deleted; the new folder simply will not contain them)

- 37 of 399 `src` files unreachable (template shell widgets, five `file-manager/*`, three `calendar/*`, superseded marketing parts, duplicate `FAQPage`, 11 unused shadcn primitives, `react.svg`).
- 4 dependencies with zero imports (`@marsidev/react-turnstile`, `@tabler/icons-react`, `react-phone-number-input`, `react-simple-wysiwyg`); `recharts`, `embla-carousel-react` and six Radix packages used only by dead files; `xlsx` and `exceljs` overlap. `CLAUDE.md` names `papaparse` and `@supabase/ssr`, which are not installed.
- About 340 lines of removed-theme CSS in `src/index.css`, with a corrupted comment and two dead image URLs.
- About 36 MB of unreferenced template images in `public/` (`cards` and `images/cards` byte-identical, `carousels`, `products`, `illustrations`, `countries`) shipped in every deploy of a 44 MB `dist/`.
- Tracked junk: `documentation.pdf` (probably template docs, ASSUMPTION), `supabase/.temp/*`. Local-only junk: `.idea`, `.gstack`, `graphify-out` (15 MB), `memory/`, `test-results/`, tsbuildinfo files.
- Docs: keep `LAUNCH-READINESS`, `PRIVACY-DATA-MAP`, `LOCAL-ENVIRONMENT` as history; archive `SQL-EDITOR-RUNBOOK` (1,619 lines) and `FEATURES`; delete `DEPLOYMENT-092-093`; `TEST-REPORT` is stale (52 vs about 81 unit tests); `CLAUDE.md` is wrong on roles, tree, migrations, toolchain, catalogue size, and cites `tms-product-spec-2026.html`, which does not exist. Four files restate the GEE-OS routing.
- **Licence:** `LICENSE.md` (PulseUI Core, Codervent) needs no attribution and forbids reselling or redistributing the template as a product. A rebuild that copies no template file can drop it. Any template-derived component that survives keeps it.
- **Tests worth porting as specifications:** 104 security assertions (per-role isolation, order integrity, certificate states, expiry), 81 unit tests (compliance dates, grading, certificate status), 31 journey tests (booking, learning, verification). They describe behaviour the PHP port must reproduce. Journey suite has no npm script and is not in CI.

## 7. Public claims — what may and may not be published

Full register with file:line, repo evidence, public evidence and safe wording: `inv-claims.md`.

**Proven publicly (agent read Companies House):** company 15718997 active, incorporated 14 May 2024, registered office 11 Halesworth Road, SE13 7TJ, Harni Muharami listed as a director. Phone and info@ address are in your own brief. NOT TESTED by me directly; the agent's source, re-checkable on Companies House.

**No evidence in the repo or on any register I could search. Must not be published as written:**

1. "CPD-accredited" (about 40 places, including certificate face and meta tags). The CPD Standards Office directory shows no result for Vitalcare; the CPD UK search was inconclusive. Safe wording unless you hold an accreditation: "CPD hours recorded".
2. "CQC Compliant", "CQC-ready", "aligned to CQC KLOEs". CQC does not approve training providers.
3. "CSTF-aligned" as a blanket line on every course. Alignment is a self-declared mapping per course; Skills for Health says providers must not imply its endorsement. Needs a signed-off mapping record per course.
4. "190+ courses" and per-category counts (hand-typed). The named list has 72 titles, the database seeds 4, and the About page says "all four Vitalcare courses".
5. Both hard-coded testimonials and the "Verified feedback / Verified learner" labels.
6. Harni's "RN MSc", "active NMC registrant", "decade of NHS" and "designed every course"; "Overseen by Harni Muharami RN MSc" printed on every certificate; "every module written by an active clinical practitioner".
7. "Issued within 24 hours", "reply within one working day", "free CV support", "trainers still practise", client types (hospices, dental, named South London trusts), "ICO registered", insurance, "NHS Supplier Registration", the publishing imprint, "compliant in week one", "rolling 12-month review".
8. Regulated-sounding course titles (First Aid at Work, Mental Health First Aid, Cannulation) with no awarding body behind them.
9. "Terms comply with the Consumer Rights Act 2015" and the refund rule that cancellation ends at first access (no consent step). The FAQ and Terms also disagree on group pricing (4+ vs 10+) and invoice terms.
10. "Verifiable at vitalcare.uk/verify": the redirect drops `?id=`, so the certificate QR lands on an empty form.

**Seed risk:** migrations 030, 032, 047, 048, 049 insert demo courses, dummy people, payroll and audit rows. Whether they ran on live is UNKNOWN. None of this data is carried over.

## 8. Risks for the rebuild

1. **Scale.** 67,500 lines of TS, 71 SQL functions, 171 policies. A like-for-like port is months of work; the plan must cut, not copy.
2. **Business logic lives in SQL**, so the port is a rewrite with the existing test suites as the specification.
3. **PHP 8.4 on the server vs 8.5 locally** and no `sodium`/`mysqli` in the listed modules; composer must pin `config.platform.php` to 8.4 and the build must not need sodium.
4. **Shared cPanel account.** Every server write needs a precise target; other sites live beside this one.
5. **Dead cron** calling a missing `cron.php` daily, and a stale `api/uploads` stub in the docroot.
6. **Hand-rolled JWT verification.** `siterelay/backend/src/Jwt.php` (169 lines, RS256, JWKS cached, `iss`/`exp`/`nbf`/`sub`/`azp` checks, fail closed) is proven in that project, but `RULES.md` asks for a maintained JWT library. I will reuse it as you instructed and record the exception in `docs/DECISIONS.md` unless you prefer a library.
7. **Data migration is yours**, so the schema map and import template must be exact. Learner identities map by email to `clerk_user_id`.
8. **Sign-up is public in Clerk** and bot protection is off, so anyone can create a Clerk user today. Authorisation must be "email must match a pre-created database user" until you close sign-up.
9. **Regulated content.** A clinical-training site that overstates accreditation carries advertising-standards and trust risk; no claim ships without a proof file.

## 9. Proposed tier

**FULL** (accounts, shared database, uploads, payments-adjacent orders): Clerk + MariaDB + private PHP API + three rolling verified backups + restore test + `FOUNDATION-ACCEPTANCE`.

## 10. Questions that only you can answer (also in the checkpoint)

See the checkpoint report. Phase 1 scope questions are listed there, and the 15 owner facts for the claims register are at the end of `inv-claims.md`.
