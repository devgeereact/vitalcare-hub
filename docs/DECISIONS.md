# Decisions

One entry per significant choice. Newest first. "Approved" means the owner said so in the session on that date; "Proposed" needs an answer before the work that depends on it.

## D-012: Where the source inventories live (2026-10-30)
- **Status:** Approved 2026-10-30 (owner replied "approved" to the recommended options; any server or account write it implies still needs its own named go)
- **Context:** The brief says no trace of the old brand or the template remains in this folder. The planning record necessarily names the old system, its tables and the template.
- **Options:** (a) keep the record under `docs/history/` and exempt it from the rebrand audit; (b) keep it outside the repo; (c) delete it after cutover.
- **Decision (recommended):** (a) now, (c) after cutover. The product tree (`src/`, `public/`, `backend/`, emails, PDFs, metadata, built `dist/`) contains none of it and is audited that way.
- **Approved by:** owner, 2026-10-30

## D-011: Public sign-up in Clerk (2026-10-30)
- **Status:** Approved 2026-10-30 (owner replied "approved" to the recommended options; any server or account write it implies still needs its own named go)
- **Context:** The production instance allows public sign-up and has bot protection off. Access here depends on a `users` row, so a stranger who signs up gets nothing.
- **Decision (recommended):** Rely on "no row, no access" until the first real users exist, then close sign-up in Clerk (a Clerk write, needs a named "go"). Enable bot protection at the same time.
- **Approved by:** owner, 2026-10-30 (closing sign-up itself is E16 and waits for the first real users and a named go)

## D-010: Tenant model for staff access (2026-10-30)
- **Status:** Approved 2026-10-30 (owner replied "approved" to the recommended options; any server or account write it implies still needs its own named go)
- **Context:** In the old database a trainer or admin could see a learner only in the same organisation (`staff_for` = staff and same organisation), and `manager` could reach only the compliance register. Vitalcare staff and employer staff are different organisations, so applying that rule literally would stop a Vitalcare trainer seeing an employer's learners.
- **Options:** (1) Vitalcare internal roles (`trainer`, `admin`, `super_admin`) are platform-wide; `manager` is limited to their own organisation, read-only on their staff's records. (2) Keep the old per-organisation scoping for everyone. (3) Make every learner belong to the Vitalcare organisation and keep old rules.
- **Decision (recommended):** (1). It matches how a single provider works and keeps the check simple: `staffFor($u)` is true for internal roles; `managerFor($u)` is true only when the target is in the manager's organisation.
- **Consequences:** Differs from the old rule for admins and trainers; recorded here so it is deliberate. The authorisation map rows using `staffFor` are tested against this rule.
- **Approved by:** owner, 2026-10-30

## D-009: Roles reduced from seven to five (2026-10-30)
- **Status:** Approved (plan approved 30 Oct 2026)
- **Decision:** `super_admin`, `admin`, `manager`, `trainer`, `learner`. `content_editor` merges into `trainer`; `guest` is dropped.

## D-008: Email without a new provider (2026-10-30)
- **Status:** Approved 2026-10-30 (owner replied "approved" to the recommended options; any server or account write it implies still needs its own named go)
- **Context:** The old build used Resend (opt-in service) from edge functions. The domain already has SPF including the cPanel mail host, DKIM and DMARC.
- **Decision (recommended):** Send from `info@vitalcare.uk` through the cPanel SMTP server using an outbox table and a cron sender. The SMTP password lives only in the private server config; the owner places it. Clerk sends sign-in codes itself (its own `clkmail` records are already in DNS).
- **Approved by:** owner, 2026-10-30

## D-007: Certificate PDF is generated on the server at approval (2026-10-30)
- **Status:** Proposed (library chosen in an R2 spike)
- **Context:** The old build drew certificates in the browser, so any user could regenerate one with changed wording.
- **Decision:** Generate once at approval, store privately with a SHA-256 in the database, serve through the API. The public verify page reads from the database, not the PDF.

## D-006: Database: new, not the existing one (2026-10-30)
- **Status:** Approved 2026-10-30 (owner replied "approved" to the recommended options; any server or account write it implies still needs its own named go)
- **Context:** `devgeereact_vitalcare_uk` exists (about 10 MB, one user with all privileges). Its contents were not read. One all-privileges user breaks the runtime/migration split.
- **Decision (recommended):** Leave it untouched; create a new empty database with two users; list the old one for the owner to delete after taking a cPanel backup.
- **Approved by:** owner, 2026-10-30 (creating it is E7 and still needs a named go)

## D-005: Reuse the siterelay token check (2026-10-30)
- **Status:** Approved by the owner's instruction ("reuse the proven Clerk token check")
- **Context:** `RULES.md` asks for a maintained JWT library. `siterelay/backend/src/Jwt.php` is 169 lines, RS256 only, JWKS fetched and cached, and checks `iss`, `exp`, `nbf`, `sub` and `azp`, failing closed.
- **Decision:** Copy it in (it has no dependencies, which also avoids needing `sodium`, which the server CLI lacks). Port its tests. Record this as a named exception to the library rule.

## D-004: Fresh repository, no old history (2026-10-30)
- **Status:** Approved
- **Context:** The old repo is public and its history holds two published passwords and a test password in a comment.
- **Decision:** New private repo, one initial commit. The old repo is untouched until a separate approved retirement.

## D-003: Names (2026-10-30)
- **Status:** Approved
- **Decision:** Working name `vitalcare-hub` (folder `~/Developer/Projects/vitalcare-hub`, repo `devgeereact/vitalcare-hub`, ports 5175 and 3175). Reason: the lowercase of the old name resolves to the old folder on this case-insensitive Mac, and the old public repo holds the exact name. At cutover, with approval, rename the old repo to `Vitalcare-Training-Hub-legacy` and, if still wanted, the new one to `Vitalcare-Training-Hub`. Public name stays "Vitalcare Training Hub"; legal line "Vitalcare Training Hub Ltd".

## D-002: Direction, brand and tone (2026-10-30)
- **Status:** Approved
- **Decision:** Clinical Ledger. Logos used exactly as supplied. Navy `#1b2e6b` and gold `#d4a843` kept, with gold-ink for gold text and a navy focus ring (gold on white measures 2.21:1). Tone: calm and clinical. Self-hosted DM Serif Display and DM Sans. See `docs/DESIGN.md`.

## D-001: Tier and stack (2026-10-30)
- **Status:** Approved
- **Context:** Accounts, a shared database, uploads and orders.
- **Options considered:** keep the old stack (Supabase) and fix it; rebuild on the house stack.
- **Decision:** FULL tier, Route B rebuild on React + TypeScript PWA, PHP API, MariaDB on cPanel, Clerk email code only, polling instead of realtime, private file folder. Cut list and V1 scope as in `docs/PRD.md`.
- **Consequences:** 71 SQL functions and 171 policies are re-implemented in PHP with tests; most of the effort. No data migration (all earlier rows were test data).
