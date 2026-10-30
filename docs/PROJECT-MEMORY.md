# Project memory: Vitalcare Training Hub

## In one sentence

Care-sector training for individuals and employers, with certificates anyone can verify and a staff register employers can trust. Vitalcare Training Hub Ltd (15718997), vitalcare.uk.

## Current state

| Area | Status | Notes |
|---|---|---|
| Phase 0 inventory | DONE | `docs/DISCOVERY.md`; source untouched |
| Purpose and plan | DONE (owner approved 30 Oct 2026) | `docs/PRD.md`, `docs/ROADMAP.md` |
| Brand direction | DONE (Clinical Ledger approved) | `docs/DESIGN.md` |
| Architecture and authorisation map | PARTIAL | written; owner decisions D-006 to D-012 pending |
| Scaffold | DONE | `pnpm check` green (local) |
| Repo on GitHub | DONE (E1, 30 Oct 2026) | private `devgeereact/vitalcare-hub`, `main` at `6dcbf5a`; Actions enabled but jobs blocked by account billing |
| Server foundation | PLANNED | E5 to E16 each need a named go |
| Build slices | PLANNED | none started |
| Production | not live (coming-soon page from an earlier release) | |

## Key decisions (details in `docs/DECISIONS.md`)

FULL tier; Route B rebuild; PHP + MariaDB + Clerk email code; five roles; fresh repo; `Jwt.php` reused with a recorded exception; new database rather than the existing one; claims shown only when `PROVEN`.

## Assumptions (re-check when)

- Source policy state equals live: unverifiable without live Supabase access (not needed: no data moves).
- Web PHP version for the domain: re-check at `/api/health` (E11).
- Companies House record: re-read before launch.

## Open questions

Tenant model (D-010); SMTP mailbox and sender; Turnstile; certificate approver wording and Harni's consent; accreditation proof; payment details and VAT; retention periods; solicitor review of legal pages.

## Out of scope for V1

See `docs/PRD.md`.
