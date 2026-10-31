# Product requirements: Vitalcare Training Hub

Owner: Vitalcare Training Hub Ltd, company number 15718997 (England and Wales), 11 Halesworth Road, London SE13 7TJ. Domain: vitalcare.uk. Tier: FULL. Status: PLANNED (owner approved the plan on 30 Oct 2026; re-scoped to a CMS on 31 Oct 2026, D-018).

## Problem and users

Re-scoped 31 Oct 2026 (D-018): **no learning platform.** Training is delivered in sessions, in person or online. The office needs one system of record for who was trained, when, on what, whether their certificate is still in date, and who to remind. Learners never sign in.

| User | Job to be done | Role |
|---|---|---|
| Owner | Publish courses and their public facts, run the office, issue and revoke certificates, see who changed what, manage staff accounts | `super_admin` |
| Office staff | Keep people and organisations up to date, schedule sessions, record attendance and results, issue certificates from attendance, answer enquiries, chase follow-ups and renewals | `admin` |
| Learners and employers | Read course facts, ask a question, check a certificate, receive renewal reminders by email | no sign-in |
| Public and inspectors | Check a certificate, read course facts | no sign-in |

Customers are both individuals and organisations (care homes, employers). Delivery is both online and in person. Sales are by enquiry and bank transfer: no basket and no card payments.

## V1 scope

| ID | User story | Acceptance criteria |
|---|---|---|
| R1 | As a visitor I want accurate course and company facts and a way to check a certificate | Given the public site, when I open the course list or a course, then I see only published courses and the facts the owner entered in the CMS (title, summary, duration, format, price, assessment method, renewal period, accreditation note). Given a certificate code, when I verify it, then I see Valid, Expired, Revoked or Not issued; Not issued shows nothing personal and Revoked shows no name. Given the contact form, when I submit it, then the office receives it and I see a confirmation. No unproven hard-coded claim appears (build check). |
| R2 | As the owner or office staff I want one CMS for the training record | Given an email with an `admin` or `super_admin` row, when I enter the six-digit code, then I reach the CMS; any other email or role sees "no access" and no data. I can keep people and organisations, courses (owner only), sessions and attendance; issue a certificate from an attended session with a passing or not-assessed result (expiry computed on the server from the course's renewal months), or by hand with a reason (owner only); download its PDF; revoke it with a reason (owner only). I see renewals due in 30, 60 and 90 days and expired ones; reminders go at 60, 30 and 7 days, once per certificate and stage. I keep notes and follow-ups on people and organisations, work the enquiries inbox (status, assignee, notes, reply by email), see today's dashboard, search, export CSV (owner only), and read the audit log (owner only). |

## Out of scope (V1)

**Any learning platform:** enrolment, lessons, progress, online assessment, learner, trainer or manager sign-in, an employer portal. Also: card payments and Stripe; orders and invoices in the system (bank transfer is handled outside it); webmail and IMAP; Zoom, Google Drive, Calendar and Meet integrations (a pasted meeting link instead); web push; QR sign-in; passwords and Google sign-in; payroll; forums and Q&A; learning paths; plan billing; file manager; AI replies; any personal data sent to an AI provider; any hard-coded claim without a proof file. The `users.role` column keeps the five values so a later portal loses nothing.

## Constraints

- Budget and services: no new paid service. Email sends through the existing cPanel mail server (SPF already includes it). Clerk and Cloudflare are existing.
- Devices: mobile first, tested at 375 px; current Chrome, Safari, Firefox, Edge.
- Accessibility: WCAG 2.2 AA is the target, not claimed until tested.
- Data and privacy: UK GDPR. Personal data of learners, organisation contacts and staff; no real data exists yet (all earlier rows were test data). Retention periods are set per category in the privacy notice once the owner confirms them (REQUIRED).
- Support: email, phone 020 8059 8757, contact form; Monday to Friday, 9am to 5pm. No reply-time promise.

## Success measures

1. One person goes from enquiry to session attendance to a verifiable certificate and a renewal reminder in the live system, with the tests passing.
2. Every CMS row of `docs/AUTHORISATION-MAP.md` that ships has a passing test (admin, super_admin and anonymous cases).
3. Zero hard-coded published claims without a `PROVEN` entry in `docs/claims.yml`.
4. `pnpm check`, the PHP checks, gitleaks and Semgrep are green on every slice.
5. A restore from backup into a scratch database is rehearsed and recorded before first release.
6. A scan of the built site finds no reference to the old template or earlier systems.
7. axe reports no serious violations on the public pages, the verify page and the CMS screens at 375 px.
