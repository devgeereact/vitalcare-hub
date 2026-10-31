# Architecture: Vitalcare Training Hub

**Tier:** FULL · **Route:** B (rebuild into a new folder) · Status: PLANNED

## Overview

A React PWA served as static files from the cPanel docroot `~/vitalcare.uk`. A small PHP API behind `/api/` does all server logic against MariaDB. Identity is Clerk (email code only); authorisation is ours, in the database and in one PHP class. Files live in a private folder and are served only through the API. Cloudflare proxies the domain in Full (strict) mode.

```
Browser (React PWA, Clerk JS)  --HTTPS-->  Cloudflare  -->  cPanel (LiteSpeed)
   |  session token (JWT, 60 s)                               |-- ~/vitalcare.uk/        static dist + api/index.php
   |                                                          '-- ~/vitalcare-private/    app code, vendor, config, storage, tmp
   '--> clerk.vitalcare.uk (Clerk frontend API, grey cloud)        MariaDB 11.4 (runtime user, migration user)
```

## Stack

| Layer | Choice | Why / status |
|---|---|---|
| Frontend | React 19 + TypeScript strict + Vite 8 PWA, Biome, Vitest, pnpm | house default (scaffold builds and tests green) |
| Routing / data | React Router, TanStack Query (polling) | decided in slice R1 |
| API | PHP 8.2+ on cPanel, no framework, PDO prepared statements only | house default. Web PHP version for the domain is UNKNOWN (CLI is 8.4.27; the selector lists 8.1 to 8.3): `composer.json` pins platform 8.2.0 until `/api/health` reports the real version |
| Database | MariaDB 11.4.13 (server), 13.0 locally | one runtime user (data only), one migration user (schema) |
| Auth | Clerk production instance, email code only; roles in our database | Reuse the existing Clerk production application. Public sign-up is closed once users exist |
| Token check | `Jwt.php` from siterelay: RS256, JWKS cached, `iss`, `exp`, `nbf`, `sub`, `azp`, fail closed | exception to "maintained JWT library", see D-005 |
| Email | cPanel SMTP from `info@vitalcare.uk`, queued in an outbox table, sent by cron | no Resend (opt-in service, not needed) |
| Files | private folder `~/vitalcare-private/storage`, `files` table, `GET /api/files/{id}` | replaces three storage buckets |
| Realtime | polling (notifications 60 s; none elsewhere) | replaces Realtime |
| Scheduled work | cPanel cron running `php ~/vitalcare-private/app/bin/cron.php <task>` | expiry reminders daily 08:00, session reminders every 15 min, email outbox every minute, nightly backup |
| PDF | server-side, drawn on request from the stored snapshot by `Certificates/CertificatePdf.php` (no dependency; D-018 item 6) | the snapshot never changes, so the same certificate always gives the same bytes; the deploy ships no `vendor/` |
| Hosting / DNS | cPanel (shared account, other sites present) + Cloudflare Full (strict) | origin lock in the live `.htaccess` is kept |
| CI | GitHub Actions: lint, typecheck, tests, build, PHP lint and tests, gitleaks, Semgrep; no deploy job | minutes are limited; concurrency cancel, 10 minute timeouts |
| Delivery | local build, `rsync` without `--delete`, checksum dry run first, `--exclude '/.htaccess'` | no CI deploy |

## Folder layout

```
src/                         frontend (React, TypeScript, Vite)
  main.tsx  App.tsx          entry and routes
  features/                  one folder per product feature: components, hooks, services and tests together
    auth/ account/ verify/ contact/ site/
  shared/                    used by more than one feature: components, hooks, services/api, content/claims
  generated/claims.ts        generated from docs/claims.yml; never edited
backend/                     PHP API (PHP 8.2+, no framework, PSR-4 namespace Vitalcare\)
  public/index.php           the only web-exposed PHP (a generated shim in the docroot requires it)
  src/
    Autoload.php Bootstrap.php
    Http/                    Api (routes), HttpError
    Auth/                    Jwt, SessionResolver, Authz, Role, User
    Support/                 Db, Config, Migrator
    Security/                RateLimiter, Turnstile
    Mail/                    SmtpMailer, OutboxSender
    Certificates/            CertificateCode, CertificateVerifier
    Contact/                 ContactService
    (further domains are added the same way: Catalogue/, Learning/, Sessions/, Commerce/)
  bin/                       CLI tools (migrate, backup, config-set, seed, send-outbox); never web-served
  migrations/                numbered SQL, never edited once applied
  tests/                     PHP suites, run on a throwaway local MariaDB
scripts/                     tested deploy and operations tools; the deploy workflow calls these
.github/workflows/           CI, Semgrep and the deploy workflow
docs/                        plans, decisions, authorisation map, schema map, claim register, runbook
```

A domain folder holds the classes of one product area, so a change to (for example) certificates touches one folder, its tests and its migration.

## Server layout

| Path | Public? | Contents |
|---|---|---|
| `~/vitalcare.uk/` | yes | built `dist/`, `api/index.php` shim, `.htaccess` (origin lock plus our rules) |
| `~/vitalcare-private/app/` | no | `src/`, `vendor/`, `bin/` |
| `~/vitalcare-private/config/prod.php` | no, mode 0600 | database credentials, SMTP credentials, Clerk issuer and authorised parties |
| `~/vitalcare-private/storage/` | no | uploaded files and issued certificate PDFs |
| `~/vitalcare-private/tmp/` | no | JWKS cache, rate-limit scratch |
| `~/vitalcare-backups/` | no | existing site-file backup kept; new `db/` and `files/` rolling sets (3 verified plus 1 pinned pre-migration) |

## Authentication and authorisation

1. The browser signs in with Clerk (six-digit email code) and sends `Authorization: Bearer <session token>` to `/api/...`.
2. PHP verifies the token (`Jwt.php`), then resolves `sub` to a `users` row by `clerk_user_id`. On the first sign-in only, if no row matches, it links the one row whose `email` equals the verified `email` claim and whose `clerk_user_id` is empty. Anyone else is authenticated but has no access: every endpoint returns 403.
3. `Authz` decides every action from the user row (role, organisation, active flag). Request bodies never supply role, owner or organisation ids. See `docs/AUTHORISATION-MAP.md`: policy, PHP check, test.
4. Because Clerk sign-up is currently public, step 2's "no row, no access" rule is what protects the data. Closing sign-up is a Clerk write that waits for approval.
5. The first `super_admin` row (the owner, no password concept) is created by `backend/bin/seed-super-admin.php`, run once with that email (E13). It is not a migration, so no personal address is stored in the repository. No demo, test or migrated users are ever created.

## Public endpoints (no token)

`GET /api/health`, `GET /api/verify/{code}`, `POST /api/contact`, `GET /api/courses` and `/api/sessions` (published, public only). Each is rate-limited by IP and key in `rate_limits`, validates input with explicit schemas, and returns the standard error shape. `POST /api/contact` also verifies a Cloudflare Turnstile token server-side and fails closed.

## Content Security Policy (draft, NOT TESTED)

To be set in `.htaccess` and checked in a real browser during R1. It must name the Clerk hosts:

`default-src 'self'; script-src 'self' https://clerk.vitalcare.uk https://cdn.protect.clerk.com https://challenges.cloudflare.com; connect-src 'self' https://clerk.vitalcare.uk https://clerk-telemetry.com; img-src 'self' data: https://img.clerk.com; frame-src https://challenges.cloudflare.com https://clerk.vitalcare.uk; worker-src 'self' blob:; style-src 'self' 'unsafe-inline'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'`

**Observed on 30 Oct 2026** (Chrome, the sign-in page on the local-live origin): besides `clerk.vitalcare.uk`, Clerk's bot protection contacts `cdn.protect.clerk.com` and a per-session host of the form `<id>.client.protect.clerk.com`, on ports 443, 8443, 18443, 2082 and 992. The final `connect-src` must therefore allow `https://*.client.protect.clerk.com` and those ports, or sign-in can fail under the CSP; confirm by testing in a browser with the CSP on, before launch. Cloudflare Turnstile loads from `challenges.cloudflare.com` as listed.

Fonts are self-hosted so no font CDN is listed. Any additional host Clerk needs (for example its accounts portal) is added only after the browser console shows the violation.

## `.htaccess` and routing

The live file begins with the Cloudflare origin lock (403 without `CF-RAY`, except `/.well-known/`). We only merge into it: our blocks are added after it, delimited by `# === BEGIN vitalcare-hub ===` markers, and `/api/` is routed to `api/index.php` before the SPA fallback. `index.html`, `sw.js` and `manifest.webmanifest` are `no-cache`; hashed assets under `/assets/` are `immutable`. The service worker never caches `/api/` responses, tokens, authenticated pages or non-GET requests. An "update available" prompt appears; no forced reload.

## Data

MariaDB schema is built by numbered migrations written for this app, not translated line by line from the old one. `docs/SCHEMA-MAP.md` maps every source table to its target and phase. Every migration is first rehearsed on a throwaway local database built from the structure only (no data). Money is stored in pence. Timestamps are UTC; calendar-month arithmetic for renewals uses Europe/London and clamps to month end.

## Security baseline

PDO prepared statements only; strict types; input validated at the boundary; output errors as `{"error":{"code","message"}}` with no stack traces; secrets only in `~/vitalcare-private/config/prod.php`, ignored local `.env` files and GitHub secrets; `VITE_CLERK_PUBLISHABLE_KEY` is the only `VITE_` variable; gitleaks pre-commit and in CI; Semgrep report-only for two weeks, then blocking; answer keys and personal data never leave the API without a check; uploads are type- and size-checked and stored under generated names; audit log for privileged actions.
