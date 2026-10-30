# Runbook

## Quick facts
| What | Where |
|---|---|
| Hosting control panel | |
| DNS | Cloudflare zone: |
| Repo | |
| Backups (FULL) | |
| Error logs | |

## Common tasks
- **Deploy / roll back:** see DEPLOYMENT.md.
- **Restore a backup (FULL):** see BACKUP-AND-RESTORE.md. Always restore to an isolated folder/database first.
- **Rotate a credential:** create the new one → update the GitHub secret / server config → deploy → revoke the old one.

## Ops log
| Date | Check | Result | Follow-up |
|---|---|---|---|

## Incidents
| Date | What happened | Impact | Fix | Prevention |
|---|---|---|---|---|
