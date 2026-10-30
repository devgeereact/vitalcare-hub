# Runbook

Operational procedures. Status words apply: anything not marked DONE has not been proven.

## Where things are

| What | Where |
|---|---|
| App code, vendor, CLI tools | `~/vitalcare-private/app/` (not web-served) |
| Config and credentials | `~/vitalcare-private/config/prod.php` and `backup.cnf`, both mode 0600; values never printed or committed |
| Uploaded files and certificate PDFs | `~/vitalcare-private/storage/` |
| Backups | `~/vitalcare-backups/db/` (`auto-<UTC>` rolling, `pinned-pre-migration-<UTC>` pinned) |
| Static site and API entry | the cPanel docroot for vitalcare.uk |

## PHP on this server (important)

The default command-line `php` on this account can change without notice and is currently 8.5 with **no `pdo_mysql`**. Always call an explicit binary from cron and by hand: `/opt/alt/php84/usr/bin/php`. The web PHP version for the domain must also be 8.4 (set in cPanel; owner action). `GET /api/health` reports the version that actually serves requests.

## Database credentials

Two users: a **runtime** user (data rights only) used by the API, and a **migration** user (schema rights on this one database) used only by `bin/migrate.php`, `bin/seed-super-admin.php` and `bin/backup.sh`. The account's `~/.my.cnf` belongs to another site: every MariaDB tool here is run with `--defaults-file=<our file>`, never `--defaults-extra-file` (which lets `~/.my.cnf` override our credentials).

## Backups

`bin/backup.sh` (DONE, tested by `backend/tests/backup.test.sh`, 39 assertions, plus mutation checks):

- creates a database dump and a storage archive, **verifies** them (gzip integrity, completion marker, table count equals the live database, tar readable), writes checksums and a `COMPLETE` marker, and only then prunes the oldest automatic backups beyond 3;
- on any failure it exits non-zero, removes its unfinished folder, prunes nothing and says why;
- `--pin` makes a pinned pre-migration backup that rotation never touches. **Make one immediately before every migration run.**

Nightly schedule: DONE. One marked block in the user crontab runs `backup.sh` at 02:20 server time (EDT) and logs to `~/vitalcare-private/logs/backup.log`. The previous crontab is saved in `~/vitalcare-private/logs/` (0600). **Off-server copies are manual**: download the newest backup folder to the owner's Mac now and then (same-server backups do not survive losing the hosting account).

### Restore test (a backup is real only after this)

1. Copy a backup folder off the server (kept under `.local-state/backups/` on the owner's Mac; git-ignored).
2. Verify: `shasum -a 256 -c MANIFEST.sha256`.
3. Restore into a scratch database that is not production: `gzip -dc db.sql.gz | mariadb <scratch database>`.
4. Compare the table count with `meta.txt` and spot-check rows.

Done once on 30 Oct 2026 with the first backup, which holds **no tables yet**, so it proved the process but not the data. A restore with real data is still required after the first migration.

### Restore in an emergency

Restore only into the production database after the owner approves, after taking a fresh pinned backup of the current state, and never by dropping or truncating live data without that approval.

## Placing secrets and settings (never by hand, never in chat)

`scripts/server-config-set.sh` changes allowlisted values in the server's private config through `bin/config-set.php`. Values travel over ssh on stdin and are never printed, put on a command line or kept in shell history; the file stays mode 0600 and the previous version is kept as `prod.php.bak-<UTC>` (newest 5).

```bash
bash scripts/server-config-set.sh secret turnstile.secret     # prompts silently for the value
bash scripts/server-config-set.sh secret smtp.password        # the info@ mailbox password, same way
bash scripts/server-config-set.sh set clerk.authorized_parties=https://vitalcare.uk,https://local.vitalcare.uk
```
Allowed keys: `office_email`, `clerk.authorized_parties`, `turnstile.secret`, and `smtp.host|port|secure|user|password|from_email|from_name`. Anything else is refused. A half-finished `smtp` section disables mail sending only, never the API.

## Mail

The sender is the dedicated mailbox `noreply@vitalcare.uk` (created 30 Oct 2026 with a server-generated password held only in the private config); replies go to `info@vitalcare.uk` through the Reply-To header. To rotate that password, generate a new one on the server, set it with `bin/config-set.php` and change it on the mailbox with the cPanel API in the same script, so it is never shown.

Contact-form messages are queued in `outbox_email`; `bin/send-outbox.php` sends them every two minutes (cron) through the server's own SMTP on port 465 with certificate checking. It does nothing, silently, until the SMTP password is set. A failed send is retried after 1, 5, 15 and 60 minutes and then 4 hours; after 5 failures the row is marked `failed` and shown in the log. Only one sender runs at a time.

## Old project items kept

The verified dump of the previous application's database is in `~/vitalcare-backups/legacy/` (0700), with a copy on the owner's Mac. To restore it into a scratch database, remove the `DEFINER=` clauses on the way in: `gzip -dc db.sql.gz | sed -E 's/DEFINER=`[^`]*`@`[^`]*`//g' | mariadb <scratch>`.

## Rollback

See `docs/DEPLOYMENT.md` (site files, `.htaccess`) and the restore steps above (database).
