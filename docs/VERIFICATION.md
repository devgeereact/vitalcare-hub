# Verification record

What has actually been run and seen. Anything not listed is NOT TESTED.

## Slice 1: identity and authorisation core (30 Oct 2026, local only)

| Check | Result |
|---|---|
| `pnpm check` (format, lint, typecheck, 12 frontend tests, PHP lint, 184 PHP assertions, build) | PASS, exit 0 |
| PHP suites on a throwaway local MariaDB 13 (port 33175, `.local-test/`), fresh database per suite | `000-jwt` 38, `010-session-resolver` 24, `020-authz` 82, `030-api` 21, `040-schema` 19: 184 passed, 0 failed |
| Mutation check on the token check | removing the `azp` test fails 4 tests; removing the algorithm test fails 1 (the other algorithm attacks are still caught by the signature check). Original restored, green |
| Mutation check on "no row, no access" | letting a second Clerk identity relink a linked email fails 3 tests |
| Mutation check on roles | counting `manager` as staff fails 5 tests |
| HTTP smoke with `php -S` on 3175, no private config | `/api/health` 200 and `authConfigured:false`; `/api/me` 503 with and without a token; unknown route 404; security headers present |
| `gitleaks dir` on docs, src, backend, scripts, public, .github, .agent | no leaks found |
| First commit scan (`gitleaks git --pre-commit --staged`) | no leaks found |

## Not tested

Real Clerk tokens and JWKS from `clerk.vitalcare.uk` (needs the live issuer and a browser sign-in); the web PHP version on the server; the service worker; the CSP in a real browser; GitHub Actions (the repository does not exist yet); Semgrep; backups and restore; any deployment; accessibility; the sign-in screen (not built).
