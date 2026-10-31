# Decisions

One entry per significant choice. Newest first. "Approved" means the owner said so in the session on that date; "Proposed" needs an answer before the work that depends on it.

## D-018: No LMS. A CMS for the owner and the office (2026-10-31)
- **Status:** Approved by the owner, 31 Oct 2026 (re-scope instruction). The implementation choices marked "chosen" below are mine where the instruction left a gap; each picks the safer option and can be relaxed by the owner.
- **Context:** The V1 plan (PRD R2 to R4) was a learning platform: learners sign in, enrol, take lessons and assessments, and employers see a register. The owner does not want online learning. Training is delivered in sessions; the office needs one place to keep the record.
- **Decision (owner):**
  - No LMS: no enrolment, lessons, progress or online assessment. **Learners never sign in.** The learner, trainer and manager sign-in paths leave the scope.
  - Only the owner (`super_admin`) and office staff (`admin`) sign in, with the Clerk email code as now.
  - The CMS keeps: people (learners) and their details; organisations (care homes and employers) and which of their staff are trained; courses and their public facts (title, summary, duration, format, price, assessment method, renewal period in months, accreditation note) with a published flag, read by the public course pages from the database; training sessions (date and time, venue or online link, trainer, capacity) and attendance (attended, no-show, result); certificates from attendance or issued by hand with a reason, with a random verifiable code, an issue date, an expiry computed on the server, a PDF, and revocation with a reason; a renewals view (due in 30, 60, 90 days; expired) and reminder emails at 60, 30 and 7 days through the outbox, once per certificate and stage, to the person and optionally the organisation contact; notes and follow-ups per person and per organisation; the enquiries inbox (new, in progress, closed; assignee; internal notes; reply by email through the outbox); a dashboard for today; search; CSV export of people and certificates; an audit log of who changed what.
  - The public site keeps: home, course pages (from the CMS), contact (Turnstile), certificate verify, legal pages. The claim register stays: course facts entered in the CMS are the owner's statements; the build check still guards hard-coded copy.
- **Chosen (safer option where the instruction left room):**
  1. **Roles:** the database keeps the five role values (cheap room for later), but the API lets only `admin` and `super_admin` in. Any other role, and any unknown email, gets `403 no_access` at `/api/me` and every `/api/admin/*` route.
  2. **Owner-only actions (`super_admin`):** creating, editing and publishing courses (they are the owner's public statements); issuing a certificate by hand (no attendance behind it); revoking a certificate; CSV exports (bulk personal data); the audit log; managing staff accounts. Everything else is open to `admin` as well.
  3. **Expiry runs from the training (completion) date, not the issue date**, so a late issue never extends validity. `expires_on` = completion date plus the course's renewal months, clamped to the last day of a shorter month (31 Jan + 1 month = 28 or 29 Feb), and is the last day of validity (as before). No renewal period means no expiry.
  4. **Revoked on the public page** shows the code, course title and completion date and the word Revoked, but **not the person's name or the reason**. Draft, unknown and malformed codes stay one identical "Not issued" with no data.
  5. **Reminders run inside the existing outbox cron** (`bin/send-outbox.php`, every two minutes): it queues due reminders first, idempotently (a primary key on certificate and stage), then sends. The server crontab needs no change. Only the most urgent due stage is sent (a certificate first seen 20 days before expiry gets the 30-day reminder, not a stale 60-day one); a certificate already renewed (a later certificate for the same person and course) gets none; a revoked one gets none; a person can be opted out.
  6. **Certificate PDF** is drawn on request by a small self-contained PHP generator (`Certificates/CertificatePdf.php`, standard PDF base fonts, no images, no dependencies) from the stored snapshot, which never changes. The deploy ships `src/` only and the server has no Composer (`scripts/build-release.sh`, `Autoload.php`), so a Composer library would need `vendor/` in the release, which the gate's archive rules do not carry. This replaces the "store the PDF" part of D-007 (still Proposed); the snapshot and the database remain the record, and the verify page never reads the PDF.
  7. **Audit log holds no personal data:** who (staff user id), when, the action, the record type and id, and the names of the fields that changed. Never the values.
  8. **CSV exports** prefix any cell starting with `=`, `+`, `-`, `@`, tab or carriage return with a single quote, quote every cell, and are served as attachments with `no-store`.
  9. **Course copy guard:** the server refuses course text that says CQC approves, endorses or certifies anything, or that it is "CQC compliant" or "CQC ready" (claim register: `cqc_claims` can never be proven).
  10. **Enquiry status:** the API uses `new`, `in_progress` and `closed`. The table's older values (`read`, `answered`, `spam`) stay allowed so no row is rewritten; the API reads `read` as in progress and `answered` or `spam` as closed.
- **Consequences:** PRD R2 to R4 are replaced by R2 (CMS). The AUTHORISATION-MAP's learner, trainer and manager rows are retired (kept for history); the CMS rows are new. Roadmap slices 3 to 5 are replaced. Nothing here touches the server: migrations 006 to 010 are additive and need the usual pinned backup and a named go to apply.
- **Approved by:** owner, 2026-10-31 (scope); the "chosen" items are recorded for the owner to confirm or relax.

## D-017: All deploys go through GitHub, over SFTP, behind a gate (2026-10-30)
- **Status:** Approved by the owner ("make sure all deploy go through Github, using the Sftp"); the server-side install is a separate named step.
- **Context:** Until now code reached the server from a laptop with `rsync` over a full-access SSH key (D-006's "no deploy job" was about Actions minutes; the repository is public, so minutes are free).
- **Decision:** A manual workflow (`Deploy`, on `main` only) builds a release, waits for the owner's approval in the GitHub environment `production`, then uploads over SFTP. The deploy key is a **separate key** whose `authorized_keys` line is `restrict,command="<private root>/gate/deploy-gate.sh"`. The gate (`backend/bin/deploy-gate.sh`) accepts only (1) the SFTP subsystem, run with deletion, rename, link and attribute requests refused at protocol level, and (2) a short list of verbs taking a commit id. Verbs verify the upload, take a pinned backup before any migration, switch the code, check `/api/health` reports the new release, and roll the code back automatically if it does not.
- **Why this shape:** a leaked or hijacked deploy key cannot open a shell, delete or rename anything, or read secrets; it can only upload files and run the gate. The gate is installed by the owner and is not updated by the pipeline it guards; `status` prints its checksum so a run can tell it is out of date.
- **Residual risk (disclosed):** the account is shared hosting, and an SFTP key cannot be limited to one folder. A compromised key could overwrite files that belong to the other sites on the account, or fill the disk. The long-term answer is a hosting account of its own; until then the approval gate, the no-delete rule and key rotation are the controls.
- **Rule kept:** nothing is mirror-deleted. Old hashed assets stay; code is synced without `--delete`; every activation leaves a rollback copy (the newest three are kept).
- **ASSUMPTION:** the web PHP's opcache revalidates within the gate's 4-second settle delay. Checked on the first real deploy.
- **Retires** (after the first real deploy is proven): `scripts/deploy-api.sh`, `scripts/deploy-rsync.sh` and their local-key use.
- **Approved by:** owner, 2026-10-30

## D-016: Clean-up of retired items (2026-10-30)
- **Status:** Approved by the owner ("you can delete what not needed")
- **Rule I applied:** nothing is deleted until a backup of it exists, is verified, and (for data) has been restored somewhere isolated. Deletions were by exact path, from a list, with the result checked afterwards.
- **Deleted:** the dead crontab line for a script that never existed (every other line proven identical); `vc_cron.log`; the stale docroot `error_log` and the `api/uploads` stub with one image (both byte-identical in the E5 backup); the unused staging folder (all 9 files identical to the E5 backup; no domain pointed at it); the unpacked copy of the old site's files (a byte-identical subset of the kept tarball); the **old application database** (83 tables, 177 rows of test data) and its user, after a dump that was verified, copied to the owner's Mac and restored with the exact same row count in every one of the 83 tables; and the now-dead credentials file for that user.
- **Kept:** the tarball of the old site, the E5 backup, the verified dump of the old database (0700 on the server and a copy on the owner's Mac), the old repository and its Cloudflare widget (E18).
- **Approved by:** owner, 2026-10-30

## D-015: Backend first, frontend at launch (2026-10-30)
- **Status:** Approved 2026-10-30 (the owner asked me to apply best practice and proceed)
- **Context:** The live site is a designed coming-soon page. The new Home page is plainer, and the course content, proofs and Turnstile are not ready.
- **Decision:** Deploy the database, the private app, the API shim and the `.htaccess` routing now; leave the static site and `robots.txt` untouched until a named launch step (E17). The API is reachable but every route that needs the database fails closed (503) until the web PHP version is fixed, and the contact form fails closed until the Turnstile secret is placed.
- **Consequences:** no visible change for visitors; the real sign-in test uses local-live (E15) instead of the public site.
- **Approved by:** owner, 2026-10-30

## D-014: CLI and database tooling on the shared host (2026-10-30)
- **Status:** Approved by the owner's instruction to proceed with E7 and E8; recorded because it came from a defect.
- **Context:** The cPanel account is shared. Its default `php` can change (8.4 to 8.5 within a day, and 8.5 has no `pdo_mysql`), and its `~/.my.cnf` holds another site's database credentials, which silently override `--defaults-extra-file`.
- **Decision:** Cron and CLI tools call an explicit PHP binary (`/opt/alt/php84/usr/bin/php`). Every MariaDB tool uses `--defaults-file` with our own 0600 file. The web PHP version for the domain is set to 8.4 by the owner.
- **Consequences:** no accidental use of another site's credentials; the migration and backup tools are independent of the default `php`.

## D-013: Repository visibility (2026-10-30)
- **Status:** Approved 2026-10-30, **option A**: make this repository public as it is. This is a recorded, named exception to the kit rule "never make a repo public to get around a limit".
- **Context:** GitHub refuses to start Actions jobs on the private repository because of an account payment or spending-limit problem. The owner chose to make it public so CI runs on free minutes. A public repository publishes its whole history, and rewriting history is not allowed.
- **Options:** (A) public as it is; (B) new public repository from a clean tree; (C) private and fix billing; (D) private and local checks only.
- **Decision:** A, chosen by the owner after being told what the history contains: a named director's birth month and year with the Companies House officer discrepancy, the owner's personal email address, and server details (cPanel account and host, `/home` paths, server address, Clerk identifiers). The working tree was scrubbed first and the two scrub commits pushed before the change, so the visible tree is clean; the earlier history is not.
- **Consequences:** The exposed history items are permanent and may be cached or indexed. From now on everything committed is public: server specifics, credentials, personal data and unproven claims stay out of the repository (ops notes live in the git-ignored `.local-state/`). The earlier suggestion to treat the repo as private no longer holds, so secret hygiene rules apply with more force. Dependabot and Semgrep now run against a public repo.
- **Approved by:** owner, 2026-10-30 ("A")

## D-012: Where the source inventories live (2026-10-30)
- **Status:** Approved 2026-10-30 (owner replied "approved" to the recommended options; any server or account write it implies still needs its own named go)
- **Context:** The brief says no trace of the old brand or the template remains in this folder. The planning record necessarily names the old system, its tables and the template, and the claims audit holds personal details about named people.
- **Options:** (a) keep the record under `docs/history/` and exempt it from the rebrand audit; (b) keep it outside the repo; (c) delete it after cutover.
- **Decision (recommended):** (a) now, (c) after cutover.
- **Update 2026-10-30:** because the repository may become public (D-013), the record was moved to `.local-state/history/` (git-ignored, local only) and is no longer in the working tree. It remains in the git history of the private repository. The product tree (`src/`, `public/`, `backend/`, emails, PDFs, metadata, built `dist/`) contains none of it and is audited that way.
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
- **Context:** `<account>_vitalcare_uk` exists (about 10 MB, one user with all privileges). Its contents were not read. One all-privileges user breaks the runtime/migration split.
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
