# Private PHP API starter

Only /api/health exists. No database, auth or business CRUD is implemented. Protected functionality must fail closed until the approved Clerk verification and authorisation layers exist.

For local evaluation with PHP 8.2+ available:

```sh
php -S 127.0.0.1:8080 backend/router.php
```

Run from project root. Vite proxies /api to this local server. Production does not use this development router/server. Deploy backend source/config/vendor privately and expose only the reviewed public entrypoint via scoped Apache routing. Allowed origins / Clerk authorised parties (`azp`) are exactly: `https://<domain>` and `https://local.<domain>` (local development against the live API, see `local-live.json`). Use current official Clerk docs for a compatible PHP verification approach; this scaffold does not pretend the middleware exists. Composer manifest/lock and DB migrations are added only when actual approved dependencies/entities are implemented.

`composer.json` pins `config.platform.php` to the **server's** PHP version (set it in Phase 03 after checking the server with `php -v`). Composer then only installs packages that work in production, even though your Mac runs a newer PHP.
