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

## Slice 1 front end and slice 2 foundations (30 Oct 2026, local only)

| Check | Result |
|---|---|
| `pnpm check` (content checks, lint, typecheck, 45 frontend tests, PHP lint, 184 PHP assertions, build) | PASS, exit 0 |
| Claim register enforcement | `claim("cpd_accreditation")` and `claim("cstf_alignment")` fail typecheck (two `@ts-expect-error` directives are accepted); `node scripts/claims.mjs --check` fails if the generated file is stale; PROVEN needs text, evidence and owner; REQUIRED must have empty text |
| Copy check (`scripts/check-copy.mjs`) | PASS on 24 files and 20 claims: no em-dash, banned word, hollow phrase, old-system trace or American spelling. It found three real em-dashes in starter files, now fixed |
| Sign-in form against the real `@clerk/react` v6 types | typechecks (`signIn.create`, `emailCode.sendCode`, `emailCode.verifyCode`, `finalize`). 13 tests with Clerk mocked: no password or social buttons, focus moves between steps, wrong code, unreachable service, no finalise unless the sign-in is complete, service error text never shown to the person |
| Account page | 8 tests: details shown, bearer header sent, 403 `no_access` shown as a message not an error, retry works, malformed response is an error never a user, signed-out redirect makes no API call, loading, sign out |
| Browser check (Chrome, local dev server) | home and sign-in render; fonts self-hosted and loaded (DM Sans 400/500/600, DM Serif Display 400); 0 requests to other hosts; no horizontal overflow at 373 px (checked in a 375 px-wide frame, because the window resize did not change the viewport) |
| Built `dist/` audit | no host other than documentation links; no trace of the earlier template or systems; 1.1 MB; the four logo files are byte-identical to the supplied ones (SHA-256 compared) |
| PWA icons | regenerated from the supplied round logo by `scripts/make-icons.sh`; the starter's placeholder icons are gone |

## Not tested

Real Clerk tokens and JWKS from `clerk.vitalcare.uk`, and a real email-code sign-in (needs the live issuer, an allowed origin and an existing user; no Clerk users may be created); the sign-in form's appearance (it needs a Clerk key; only its logic is tested); the web PHP version on the server; the service worker; the CSP in a real browser; GitHub Actions (the repository does not exist yet); Semgrep; backups and restore; any deployment; keyboard path and screen-reader pass; axe; 375 px on the sign-in form itself.
