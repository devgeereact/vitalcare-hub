# Verification log

Only record what was actually run. Newest first.

| Date | Commit | Environment | Check | Result | Evidence |
|---|---|---|---|---|---|

## Standing test plan
- Unit: `pnpm test` (Vitest) — domain rules and services.
- Full gate: `pnpm check` (lint, typecheck, tests, build) — runs in CI on every push.
- Journeys (manual or Playwright later): new user, invalid input, offline/reconnect, denied access, update prompt.
