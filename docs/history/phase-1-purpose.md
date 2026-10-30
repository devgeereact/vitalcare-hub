# Phase 1 — Purpose and replan (one page)

Status: PLANNED, awaiting owner approval. Evidence for every statement is in `PHASE-0-DISCOVERY.md`. Where the code cannot answer, the line is marked **ASK**.

## What it is for

A training provider's single system of record and front door: it sells and delivers short care-sector training, proves that each person completed it, and lets an employer see who is in date. The public site brings people in; the platform keeps the record; the verify page lets anyone check a certificate.

## Who it is for

| Audience | Evidence in the source | Role in the rebuild |
|---|---|---|
| **Learners** (care workers, nurses, volunteers) | enrolments, lesson progress, assessments, certificates | `learner` |
| **Employers** (care home or practice managers, training leads) | `manager` role, `organisations`, staff compliance matrix, group booking, invoices | `manager`, scoped to their organisation |
| **Trainers** | trainer profiles, sessions, attendance | `trainer` |
| **Owner and office** (Gideon, Harni, admin staff) | catalogue, orders, certificate approval, audit | `admin`, `super_admin` (gakinz101@gmail.com) |
| **The public / inspectors** | certificate verification, course pages | no sign-in |

**ASK:** which of these is the real majority today: individuals paying for themselves, or employers booking staff? It decides the order of the roadmap below.

Roles drop from 7 to 5: `super_admin, admin, manager, trainer, learner`. `content_editor` folds into `trainer` and `admin`; `guest` goes.

## The jobs it must do

**Learner:** find a course and know exactly what it is and costs; book or be enrolled; learn and be assessed; get a certificate that anyone can verify; be told before it expires; re-book.
**Employer:** see which staff are in date, due or lapsed; book a group; receive an invoice; verify a certificate in seconds; export the record for an inspection.
**Owner:** publish courses and sessions; run attendance; approve and issue certificates; take and reconcile orders by bank transfer; see an audit trail of who changed what; never publish a claim that cannot be proved.

## What it will not do (V1)

No card payments or Stripe (orders are reconciled by bank transfer, as today). No webmail or IMAP. No Zoom, Google Drive, Calendar or Meet integrations (a pasted meeting link and a downloadable `.ics` file instead). No web push. No QR sign-in. No passwords and no Google sign-in (Clerk email code only). No payroll, forums, Q&A wall, departments, learning paths, plan billing or file manager until you say otherwise (the data model leaves room so your import loses nothing). No AI replies to learners or emails, no AI-generated course published without a human-review state, and no personal data sent to any AI provider. No certificate wording, course page or email that states an accreditation, alignment, credential or turnaround unless a proof file exists for it.

## Success measures

1. A learner goes from enquiry to a verifiable certificate in the live system, end to end, with the journey tests green. (Today: the journey suite is not in CI.)
2. Every one of the 171 source policies maps to a named PHP check and a passing test, in a table kept in the repo.
3. Public claims: zero published without an entry in the claims register marked PROVEN.
4. `pnpm check` and PHP checks green on every slice; gitleaks and Semgrep clean.
5. A restore from backup into a scratch database is rehearsed and recorded before first release.
6. A scan of the built site finds zero references to the old brand, the template, Supabase or Acme.
7. axe reports no serious violations on the public pages and the sign-in, learner and verify journeys at 375 px (target WCAG 2.2 AA; not claimed until tested).

## Phased roadmap

| Phase | Ships | Needs |
|---|---|---|
| **R0 Foundation** | Private repo, scaffold, CI, Clerk guard in PHP, database migrations, users keyed by `clerk_user_id`, three rolling backups with a tested restore | Your go on each server and GitHub write |
| **R1 Front door** | Public site (rewritten, proven-only copy), course pages, enquiry form with spam protection, **certificate verify page**, legal pages, replaces the coming-soon page | Claims proof or "CPD hours recorded" wording; legal copy review |
| **R2 Learner core** | Sign-in, courses, enrolment, lessons, assessments with server-side grading, certificate approval and issue, PDF, expiry reminders by cron | Your data import of users and records |
| **R3 Delivery** | Sessions, trainers, attendance, calendar, announcements, notifications by polling | |
| **R4 Employer and money** | Organisation compliance matrix, group booking, orders by bank transfer, coupons, invoices, exports, audit viewer | Payment details and VAT status from you |
| **R5 Deferred** | Anything from the UNKNOWN list you choose to bring back | Your decision per item |

Public site and verify page go first so the coming-soon page is replaced by something true as early as possible, without needing learner accounts.

## Open questions for you (the code cannot answer these)

1. Individuals or employers: who pays today, and who do you want to sell to next?
2. Do you sell online (basket and order), or take bookings by enquiry and bank transfer? Recommended for V1: enquiry plus order by bank transfer, no basket until payment details and VAT status are fixed.
3. Is training delivered online, in person, or both? Where, and by whom (named trainers or freelancers)?
4. Accreditation facts: any CPD accreditation (accreditor, number, logo permission); any signed CSTF mapping; Harni's NMC PIN and consent to be named; your consent and role wording; ICO registration number. Without these the site says only what is proven.
5. Does certificate approval by the Clinical Director stay as a step? (Recommended: yes, as an internal approval, with wording that says only what actually happens.)
6. Real volumes and records to import: the runbook says two real certificates exist. How many users, enrolments and certificates are real?
7. Support contact, opening hours and reply promises you can keep.
8. Database: is there an existing MariaDB for vitalcare.uk? If not, I will plan its creation as a named server write for your "go".

---

## Decisions recorded 30 Oct 2026 (owner replies)

| # | Question | Answer | Effect |
|---|---|---|---|
| 1 | Who pays | Individuals and companies | Both journeys in V1: self-serve enquiry and employer/group booking; `manager` role stays |
| 2 | Online sales | Enquiry plus order by bank transfer, no basket | Orders and invoices in R4; no payment gateway |
| 3 | Delivery | Online and in person, by Harni | One named trainer; venue and online-link fields per session; "delivered by" wording only after her consent and credentials are proven |
| 4 | Accreditation facts | Not yet acquired; "put them as required" | Claims register: each claim is `REQUIRED` until proof exists. Copy renders only `PROVEN` claims; a build check fails if a page uses an unproven one |
| 5 | Certificate approval step | Keep | Internal approval stays; wording says only what happens |
| 6 | Real data | None: all existing rows are test data | No migration of live records. Schema map and import template still produced; seeds are synthetic and local only |
| 7 | Support | Email, phone, contact form; normal working hours as on the landing | Source states Monday to Friday, 9am to 5pm (`ContactPage.tsx:192-193`); no reply-time promise published |
| 8 | Database | Verify, create if missing | Verified: **exists** (see Phase 1 checkpoint) |
| 9 | Server clean-up | Owner will delete; list only | List supplied in the checkpoint |
