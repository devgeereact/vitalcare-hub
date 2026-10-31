# Security and privacy

## Data inventory
| Data | Where stored | Who can see it | Retention | Personal? |
|---|---|---|---|---|
| Staff accounts (email, name, role) | `users` | owner (all fields); office staff see names only in pickers | REQUIRED from the owner | yes |
| People: name, email, phone, job title, organisation | `people` | owner and office staff | REQUIRED | yes |
| Organisation contact name, email, phone, address | `organisations` | owner and office staff | REQUIRED | yes (contact) |
| Attendance and results | `session_attendees` | owner and office staff | REQUIRED | yes |
| Certificates (name snapshot, course, dates) | `certificates` | staff; the public sees name, course and dates by code; for a revoked one only course and dates | REQUIRED | yes |
| Notes, follow-ups, enquiries and replies | `notes`, `follow_ups`, `enquiries`, `outbox_email` | owner and office staff | REQUIRED | yes |
| Audit log | `audit_log` | owner | REQUIRED | staff id only; field names, no values |
| CSV exports | the owner's device | owner | delete after use | yes |

## Secrets (names and locations only — never values)
| Name | Location | Rotated |
|---|---|---|

## Access model (FULL)
| Role | Can | Cannot | Enforced in |
|---|---|---|---|
| `super_admin` (owner) | everything in the CMS | change their own role or status; remove the last owner | `Authz::PERMISSIONS`, `AdminApi`, tests 110 and 180 |
| `admin` (office) | people, organisations, sessions, attendance, issue from attendance, notes, follow-ups, enquiries, renewals, PDFs | course writes, by-hand certificates, revoke, exports, audit log, staff accounts | same |
| `trainer`, `manager`, `learner`, unknown email | nothing (403 `no_access`) | sign in to anything (D-018) | `Api::cmsUser`, test 110 |
| Public | course pages (published only), verify, contact | anything else | rate limits; tests 050, 070, 130 |

## Threats considered
- Exposed secrets → gitleaks pre-commit + CI scan; no secrets in `VITE_*`.
- Broken access control → server-side ownership checks + negative tests.
- Stale or cached private data → service worker never caches `/api`.
- Spreadsheet formula injection in exports → every risky cell is prefixed (D-018 item 8, test 180).
- Personal data in logs → the audit log stores field names only (test 120 and 180); the PDF holds no email.
- Enumeration through verify → unknown, malformed and draft codes give one identical answer; revoked shows no name.

Legal pages (privacy policy, terms) are drafted with `KIT/library/` prompts and need human/legal review before publishing.
