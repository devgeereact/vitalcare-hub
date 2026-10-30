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

## Verify, rate limiting and contact (30 Oct 2026, local only)

| Check | Result |
|---|---|
| PHP suites | 298 assertions, 0 failed: `050-verify` 50, `060-rate-limit` 14, `070-contact` 50, plus the earlier 184 |
| Frontend tests | 75 passed (8 files), including the verify and contact pages |
| `pnpm check` | PASS, exit 0 |
| Mutation checks | a draft or withdrawn certificate treated as issued fails 4 tests; UTC date instead of London date fails the British Summer Time test; removing the honeypot fails 2; Turnstile failing open when unconfigured fails 1; limiter off by one fails the suite. Originals restored, green |
| Real stack, local (React + Vite proxy + PHP built-in server + MariaDB 13, **synthetic data only**) | `/api/verify/` returned valid, expired and not issued correctly; a draft and an unknown code returned the identical not-issued answer; the three states rendered in Chrome with no horizontal overflow; the contact form loaded Cloudflare's script with its documented always-pass test key, issued a token, submitted through the page, stored one enquiry and queued exactly one email to the office with the visitor as Reply-To; rate-limit buckets are hashes |
| A test mistake found on the way | my hand-typed synthetic codes contained I, L and O, which the alphabet excludes, so the API correctly called them malformed |

Not verified: Turnstile server-side against Cloudflare (local bypass was used on the server); real email sending (queued only); the pages at a true 375 px viewport (checked at 300 px frames); screen reader and keyboard pass; CSP in a browser.

## GitHub Actions (30 Oct 2026)

| Check | Result |
|---|---|
| CI, run 41056672715 on `9287530`, ubuntu-latest, public repo | PASS, every step ran: pnpm install, content checks (3 proven, 17 required claims; copy ok), lint, typecheck, 45 frontend tests (5 files), 184 PHP assertions (5 suites) against the `mariadb:11.4` service container, build, PHP syntax check, gitleaks on git history and on `dist/`: no leaks |
| Why it had not run before | while private, GitHub refused to start jobs: "recent account payments have failed or your spending limit needs to be increased" |
| Semgrep | job completes but skips the scan (no token): NOT RUN as a scan |

## Not tested

Real Clerk tokens and JWKS from `clerk.vitalcare.uk`, and a real email-code sign-in (needs the live issuer, an allowed origin and an existing user; no Clerk users may be created); the sign-in form's appearance (it needs a Clerk key; only its logic is tested); the web PHP version on the server; the service worker; the CSP in a real browser; Semgrep: the workflow runs but its own log says `SEMGREP_APP_TOKEN is not set; skipping the scan`, so **no Semgrep scan has happened** (needs the owner's token, E4); Semgrep; backups and restore; any deployment; keyboard path and screen-reader pass; axe; 375 px on the sign-in form itself.
