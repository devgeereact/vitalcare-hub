# Restructure plan: source to destination (Route B)

- Selected mode: **B**, confirmed by the owner (30 Oct 2026).
- Source: `~/Developer/Projects/Vitalcare-Training-Hub` (read-only for the whole project; its only local change, `supabase/.temp/cli-latest`, pre-dates this work).
- Destination: `~/Developer/Projects/vitalcare-hub` (created by the kit scaffolder; did not exist before).
- Same product or new: **same product moving to a new home**, but with **no history carried over** (D-004: the old history holds published passwords). Fresh private repo, one initial commit.
- Revised audience and brand: individuals and employers; Clinical Ledger; see `docs/PRD.md`, `docs/DESIGN.md`.
- Existing live site and triggers: the live site is a coming-soon page. The old repo, its deploy scripts, its Supabase project and the source folder are untouched until a separate approved retirement.
- Git branches and stashes in the source: 11 dependabot branches, `dev`, `update/2026-10-08`, `chore/add-gsap-lenis-production`; no stashes. None is relevant to the rebuild; none is preserved or deleted by this project.
- Backup and recovery evidence: the source is untouched and remains the recovery copy. Server files of the previous static site are in `~/vitalcare-backups/live-20261023-0447*`. No live data exists to back up (all earlier rows were test data). Pre-release server backup is item E5 in `docs/FOUNDATION-PLAN.md`.
- Auth, data and config migration: **none to migrate** (no real users or data). Identity moves from Supabase Auth to Clerk; users are created by the owner and linked by verified email on first sign-in. Schema and import contract: `docs/SCHEMA-MAP.md`. Secret locations in the source (listed, never opened): `.env.local`, `.env.test.local`, `tests/security/.env*`, repository secrets, edge-function secrets, the `integration_settings` table. None is copied.

## Classification of every source area

| Source area | Class | Destination / reason |
|---|---|---|
| `src/pages/marketing/*`, `src/components/marketing/*`, `src/data/*` | REFACTOR | Page structure reused as a content outline; every word of copy rewritten against `docs/claims.yml`; static catalogue replaced by the database |
| `src/pages/platform/*` for V1 screens (learners, courses, assessments, sessions, attendance, certificates, compliance, orders, invoices, notifications, announcements, cohorts, blog, settings, audit) | REFACTOR | Behaviour reproduced slice by slice on the new API; no component copied unless it survives review and is rewritten to the new tokens |
| `src/pages/platform/*` for deferred screens (payroll, forums, Q&A, departments, learning paths, library, files, plan billing) | ARCHIVE | Stays in the source; returns only on a decision |
| `src/auth/basic/*`, `src/auth/cover/*`, `login-form`, `signup-form`, `auth/callback`, error demos, `ModuleComingSoon` | DUPLICATE or template | Not carried; Clerk replaces sign-in |
| `src/components/ui/*` (shadcn) | GENERATED | Re-generated only for primitives actually used; 11 unused primitives not generated |
| `src/lib/queries/*`, `src/lib/supabase/*`, `src/types/database.types.ts` | GENERATED | Replaced by `src/services/` calling the PHP API |
| `src/lib/dates.ts` (calendar-month arithmetic) and `tests/unit/compliance-dates.test.ts` | KEEP as specification | Re-implemented in TypeScript and PHP with the same 30 assertions |
| `src/lib/certificates`, `invoices`, `exports` | REFACTOR | Certificate moves server-side (D-007); invoices and workbooks re-specified |
| `supabase/migrations/*` (95) | MIGRATE (design only) | Behaviour captured in `docs/AUTHORISATION-MAP.md` and `docs/SCHEMA-MAP.md`; new numbered MariaDB migrations written fresh. Seed and demo migrations (030, 032, 047, 048, 049, 078, 080) not carried |
| `supabase/functions/*` (23) | REFACTOR or CUT | ~10 endpoint groups rebuilt in PHP; ~14 cut (see `docs/history/inv-backend.md`) |
| `tests/security/*` (104 assertions), `tests/journey/*` (31), `tests/unit/*` (81) | KEEP as specification | Each becomes a PHP or journey test for the matching slice |
| `public/logos/*.svg` (4) | KEEP | Byte-identical copies, never edited |
| `public/` template images (`cards`, `images/*`, `products`, `illustrations`, `vite.svg`) | DUPLICATE / not used | Not carried (about 36 MB) |
| `index.html`, `manifest`, `robots.txt`, `sw.js`, `scripts/generate-sitemap.mjs` | REFACTOR | Rewritten for the new brand and the new service-worker rules |
| `.github/workflows/*` | REFACTOR | CI kept cheap; no deploy job; Semgrep and gitleaks added |
| `docs/*` | ARCHIVE | Only `LAUNCH-READINESS`, `PRIVACY-DATA-MAP`, `LOCAL-ENVIRONMENT` informed this plan; summarised in `docs/history/`; originals stay in the source |
| `LICENSE.md` (template licence), `documentation.pdf`, `components.json`, `.idea`, `.gstack`, `graphify-out`, `memory/`, `test-results/`, `*.tsbuildinfo`, `supabase/.temp/*` | not carried | Template or junk. Licence file dropped because no template file is copied; revisit if any template component survives |
| `CLAUDE.md`, `AGENTS.md`, `CODEX.md`, `.claude/rules/gee-os.md` | not carried | Describe the old project; replaced by this repo's `AGENTS.md`, `CLAUDE.md` and `.agent/` |
| `.env*`, keys, dumps, uploads | never copied | By rule |

## Never copied by default

`.git`, `node_modules`, `dist`, `.env*`, private config, uploads, database dumps, keys.

## Cutover and retirement (separate approvals, later)

Cutover is the first release (E10, E17). Renaming or retiring the old repo, its Supabase project and the source folder is E18 and needs its own backup and approval. Until then the source stays exactly as it is.

## Done when

Kept and replaced features, imported paths, auth and data decisions, and the remaining cutover steps are all recorded here, and the rebrand audit (`docs/REBRAND-VERIFICATION.md`) shows no old-system or template trace in the product tree.
