# Deployment

Status: PLANNED. Nothing has been deployed by this project. The live site is currently a coming-soon page from an earlier release; the live `.htaccess` carries the Cloudflare origin lock.

## Targets

| Target | URL | Path on the cPanel account | Status |
|---|---|---|---|
| Production | https://vitalcare.uk | `~/vitalcare.uk` (static files, `api/index.php`) and `~/vitalcare-private/` (app, config, storage, tmp) | PLANNED |
| Staging | none | `~/vitalcare-cs-staging` exists but is not wired to a domain (UNKNOWN); not used | n/a |

The cPanel account is shared with unrelated sites. Every server command names these paths only.

## Method

Local build, then `rsync` over SSH. No GitHub deploy job (Actions minutes are limited). No `--delete`. `/.htaccess` is never uploaded by the script. Entry files (`index.html`, `sw.js`, `manifest.webmanifest`) go last.

```bash
pnpm build
export DEPLOY_TARGET=cpanel:/home/ACCOUNT/vitalcare.uk   # host alias and path, no secrets
pnpm deploy:check     # checksum dry run: prints every file it would change, changes nothing
pnpm deploy           # only after approval of the dry run
```

The script was tested on throwaway folders (dry run changes nothing, the target `.htaccess` is untouched, stray target files are kept, entry files upload last). It has **not** been run against the server.

## `.htaccess`

The live file starts with the Cloudflare origin lock. Our rules are merged after it between `# === BEGIN vitalcare-hub ===` and `# === END vitalcare-hub ===`: `/api/` routing before the SPA fallback, security headers, the CSP naming the Clerk hosts (clerk.vitalcare.uk, cdn.protect.clerk.com, challenges.cloudflare.com, img.clerk.com, clerk-telemetry.com), cache rules. A unified diff against the live file is shown and approved first; the previous file is saved first (E5, E9 in `docs/FOUNDATION-PLAN.md`).

## GitHub (names only)

| Name | Kind | Used by |
|---|---|---|
| VITE_CLERK_PUBLISHABLE_KEY | repository variable (public) | CI build |
| SEMGREP_APP_TOKEN | repository secret (owner enters it) | `semgrep.yml` |

## Done means

A deploy is DONE only when the public URL returns the new build ID (`/build-id.txt`) and the deep-link, headers and `/api/health` checks pass.

## Roll back

Re-upload the previous build and the saved `.htaccess` (taken in E5). Uploads never delete server files, so old hashed assets stay available. Database: restore the pinned pre-migration backup (`docs/RUNBOOK.md`).
