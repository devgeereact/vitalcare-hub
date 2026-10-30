# Product requirements: Vitalcare Training Hub

Owner: Vitalcare Training Hub Ltd, company number 15718997 (England and Wales), 11 Halesworth Road, London SE13 7TJ. Domain: vitalcare.uk. Tier: FULL. Status: PLANNED (owner approved the plan on 30 Oct 2026; build not started).

## Problem and users

A care-sector training provider needs one system of record: sell and deliver short training, prove each person completed it, and let employers see who is in date. Today there is a "coming soon" page and an unreleased earlier build whose own readiness record says it is not fit to launch.

| User | Job to be done | Role |
|---|---|---|
| Individual learner (pays for themselves) | Find a course, book, learn, get a certificate anyone can verify, be told before it expires | `learner` |
| Employer (training lead or manager) | Book staff in a group, see who is in date, get an invoice, verify a certificate, export the record | `manager`, scoped to their organisation |
| Trainer (Harni Muharami today) | Run sessions online and in person, take attendance, mark assessments | `trainer` |
| Owner and office | Publish courses and sessions, approve and issue certificates, reconcile orders, see who changed what | `admin`, `super_admin` (gakinz101@gmail.com) |
| Public and inspectors | Check a certificate, read course facts | no sign-in |

Customers are both individuals and companies. Delivery is both online and in person. Sales are by enquiry and bank transfer: no basket and no card payments in V1.

## V1 scope

| ID | User story | Acceptance criteria |
|---|---|---|
| R1 | As a visitor I want accurate course and company facts and a way to check a certificate | Given the public site, when I open a course, then I see only approved facts (duration, format, price, assessment, renewal). Given a certificate code, when I verify it, then I see Valid, Expired or Not issued, and nothing personal for Not issued. Given the contact form, when I submit it, then the office receives it and I see a confirmation. No unproven claim appears (build check). |
| R2 | As a learner I want to sign in, enrol, learn, be assessed and receive a certificate | Given an email known to the system, when I enter the six-digit code, then I reach my learning. Given an unknown email, then I see "no access" and no data. Given a passed assessment, when an approver approves it, then my certificate is issued with a renewal date computed on the server, and verifies publicly. Answer keys never reach my browser. |
| R3 | As a trainer I want to run sessions and take attendance, online or in person | Given a session, when learners are booked, then I can mark attendance; a learner can check themselves in only for a session they booked; the meeting link is visible only to booked learners and staff. |
| R4 | As an employer I want to see my staff's training status and be invoiced | Given my organisation, when I open the register, then I see only my staff, with in date, due soon (30 days) and lapsed. Given a group order, when it is placed, then price comes from the catalogue, an order reference is shown, and staff can confirm, cancel or refund with an audit entry. |

## Out of scope (V1)

Card payments and Stripe; webmail and IMAP; Zoom, Google Drive, Calendar and Meet integrations (a pasted meeting link and an `.ics` file instead); web push; QR sign-in; passwords and Google sign-in; payroll; forums and Q&A; departments; learning paths; plan billing; file manager; AI replies to learners or emails; AI-generated courses published without human review; any personal data sent to an AI provider; any claim without a proof file. The data model keeps room for deferred items so a later import loses nothing.

## Constraints

- Budget and services: no new paid service. Email sends through the existing cPanel mail server (SPF already includes it). Clerk and Cloudflare are existing.
- Devices: mobile first, tested at 375 px; current Chrome, Safari, Firefox, Edge.
- Accessibility: WCAG 2.2 AA is the target, not claimed until tested.
- Data and privacy: UK GDPR. Personal data of learners and staff; no real data exists yet (all earlier rows were test data). Retention periods are set per category in the privacy notice once the owner confirms them (REQUIRED).
- Support: email, phone 020 8059 8757, contact form; Monday to Friday, 9am to 5pm. No reply-time promise.

## Success measures

1. One learner goes from enquiry to a verifiable certificate in the live system, with the journey tests passing.
2. Every row of `docs/AUTHORISATION-MAP.md` that ships has a passing test.
3. Zero published claims without a `PROVEN` entry in `docs/claims.yml`.
4. `pnpm check`, the PHP checks, gitleaks and Semgrep are green on every slice.
5. A restore from backup into a scratch database is rehearsed and recorded before first release.
6. A scan of the built site finds no reference to the old template or earlier systems.
7. axe reports no serious violations on the public pages and the sign-in, learner and verify journeys at 375 px.
