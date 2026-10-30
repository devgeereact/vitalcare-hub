# Deployment

Status: **pipeline built and tested locally (NOT yet run against the server or GitHub)**. The live site is still a coming-soon page from an earlier release; the live `.htaccess` carries the Cloudflare origin lock. Decision: D-017.

## Targets

| Target | URL | Path on the cPanel account | Status |
|---|---|---|---|
| Production | https://vitalcare.uk | `~/vitalcare.uk` (static files, `api/index.php`) and `~/vitalcare-private/` (app, config, storage, tmp) | API live (deployed by the earlier local script); site PLANNED |
| Staging | none | `~/vitalcare-cs-staging` was removed (D-016) | n/a |

The cPanel account is shared with unrelated sites. Every server command names these paths only.

## The one way code reaches the server

```
you ──► GitHub Actions "Deploy" (main only) ──► build job: CI green for this SHA? build the release
                                            └─► deploy job: waits for your approval (environment "production")
                                                  ├─ SFTP upload to ~/vitalcare-private/incoming/<sha>/   (cannot delete or rename)
                                                  └─ ssh "<verb> <sha>" ──► deploy-gate.sh (the only command the key can run)
```

Run it: **Actions > Deploy > Run workflow**, tick what you want (API, site, .htaccess), then approve the run when GitHub asks. The site checkbox also needs the repository variable `LAUNCH_APPROVED=yes`, which you set only when the release plan's gates are met.

### What the gate does (`backend/bin/deploy-gate.sh`)

| Verb | What it does | If it fails |
|---|---|---|
| `verify <sha>` | checks `SHA256SUMS`, refuses unsafe archive entries (absolute paths, `..`, links, odd names, unexpected top-level folders), checks the archive was built for this commit, runs `php -l` on every PHP file, refuses a site archive that carries an `api/` folder, a `.htaccess`, env files, source maps or dumps | nothing is staged as verified; live code untouched |
| `activate-api <sha>` | rollback copy, then (only if migrations are pending) a **pinned backup** and the migrations, then the code (`rsync --checksum`, no delete), then waits and checks `/api/health` reports this release | a failed backup or migration stops before the code switches; a failed health check restores the previous code automatically |
| `activate-site <sha>` | rollback copy of the files it will overwrite, upload without deleting, entry files last, `/.htaccess` and `api/` excluded, then checks the public `/build-id.txt` | the rollback copy holds the previous files |
| `activate-htaccess <sha>` | runs `merge-htaccess.py` (fail-closed: refuses if the origin lock is missing), keeps a copy, checks the API still answers 200 | restores the previous file |
| `rollback-api <stamp>` | puts a rollback copy of the code back | n/a |
| `status` | live API release, live site build, pending migrations, rollback copies, the gate's own sha256 | n/a |

Migrations must be **backward compatible with the code that is still running** (add, do not drop or rename in the same release), because the database changes before the code does. MariaDB cannot roll DDL back; a migration failure is recovered from the pinned backup only with the owner's approval.

### GitHub settings (names only; values are never in the repository)

| Name | Kind | Used by |
|---|---|---|
| VITE_CLERK_PUBLISHABLE_KEY, VITE_TURNSTILE_SITE_KEY | repository variables (public) | build |
| LAUNCH_APPROVED | repository variable, unset until launch | site deploys |
| DEPLOY_SSH_KEY, DEPLOY_KNOWN_HOSTS, DEPLOY_HOST, DEPLOY_PORT, DEPLOY_USER | **environment** secrets on `production` | deploy job only |
| environment `production` | required reviewer: the owner; deployment branches: `main` only | deploy job |
| SEMGREP_APP_TOKEN | repository secret (owner enters it) | `semgrep.yml` |

### One-off install (owner approves each step; NOT DONE yet)

1. Create a dedicated ed25519 key on the Mac; the private half goes only into the GitHub environment secret and is then deleted locally.
2. Copy `backend/bin/deploy-gate.sh` to `~/vitalcare-private/gate/deploy-gate.sh` (mode 0750) and create `incoming`, `releases`, `rollback`.
3. Back up `~/.ssh/authorized_keys`, add the one line `restrict,command="/home/ACCOUNT/vitalcare-private/gate/deploy-gate.sh" ssh-ed25519 ... github-deploy`, and prove the existing keys are unchanged.
4. Create the environment, its reviewer rule and secrets with `gh`, without printing them.
5. First run: API only. The owner approves it in the GitHub UI. Success means `/api/health` reports the new release.
6. After that run is proven, retire `scripts/deploy-api.sh` and `scripts/deploy-rsync.sh`.

Changing the gate is a repeat of step 2 by the owner, never part of a deploy.

### Rotating the key

Generate a new key, add its line, update the secret, run a deploy, then remove the old line (back up `authorized_keys` first). Do this if a runner or the secret may have been exposed.

## `.htaccess`

The live file starts with the Cloudflare origin lock. Our rules are merged after it between `# === BEGIN vitalcare-hub ===` and `# === END vitalcare-hub ===`: `/api/` routing before the SPA fallback, the `Authorization` hand-off, and at release the security headers and the CSP naming the Clerk hosts (clerk.vitalcare.uk, cdn.protect.clerk.com, `*.client.protect.clerk.com`, challenges.cloudflare.com, img.clerk.com, clerk-telemetry.com). It changes only through the `activate-htaccess` verb.

## Done means

A deploy is DONE only when the public URL reports the new release (`/api/health` for the API, `/build-id.txt` for the site). The workflow's last step checks both.

## Roll back

API code: `rollback-api <stamp>` (stamps are in `status`). Site: the files in `~/vitalcare-private/rollback/site-<stamp>/files` are the previous versions; re-deploying an earlier commit from GitHub also works. `.htaccess`: the copy in `rollback/htaccess-<stamp>`. Database: restore the pinned pre-migration backup (`docs/RUNBOOK.md`), only with the owner's approval.

## Local checks

`pnpm test:gate` runs the whole pipeline locally (build, upload, verify, activate, health, rollback) against a throwaway database and PHP's built-in server: 93 assertions, mutation-checked. It proves the logic, not the real SSH transport, which is NOT TESTED until the first real run.
