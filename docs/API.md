# API: Vitalcare Training Hub

Base: `/api`. JSON in and out. Errors: `{"error":{"code":"...","message":"..."}}`; no stack traces, no internals.

| Method | Path | Auth | Response | Errors | Status |
|---|---|---|---|---|---|
| GET | `/api/health` | none | `{"status":"ok","php":"<version>","authConfigured":true|false}` | 405 | DONE locally (tests + HTTP smoke); NOT deployed |
| GET | `/api/me` | Bearer Clerk session token | `{"user":{"id","email","displayName","role","organisationId"}}` | 401 no or invalid token; 403 `no_access` valid identity, no row; 405; 503 not configured | DONE locally; NOT deployed |

| GET | `/api/verify/{code}` | none (public) | `{"status":"valid\|expired\|not_issued","certificate"?:{code,learnerName,courseTitle,hours,completedOn,expiresOn,issuer}}`; `Cache-Control: no-store` | 429 `rate_limited` (30 per 5 minutes per caller) with `Retry-After`; 503 | DONE locally (50 assertions + real browser check); NOT deployed |
| POST | `/api/contact` | none (public), Turnstile token | body `{name,email,phone?,message,turnstileToken,website}`; `201 {"received":true}` | 413 body over 16 KB; 422 with `error.fields`; 403 `verification_failed`; 429 (5 per hour per caller); 503 if Turnstile is not configured (fails closed) | DONE locally (50 assertions + real browser check); email is queued, **sending is not built**; NOT deployed |

Verify rules: a code is 12 characters from a 31-character alphabet (no 0, 1, I, L or O) and is random. An unknown, malformed, draft or withdrawn code all return the same `{"status":"not_issued"}` with no data, so the endpoint is not an oracle. `expires_on` is the last day of validity; dates compare as Europe/London calendar dates.

Contact rules: one email is queued to the office with a fixed subject and the visitor as `Reply-To`; nothing is ever sent to the visitor. A filled honeypot field gets a normal 201 and stores nothing. The caller's address is stored only as a SHA-256.

Rules: validate every input server-side; owner, role and organisation come from the verified session and the `users` row, never the request. The `Bearer` scheme is case-insensitive. Planned endpoints are added slice by slice (`docs/ROADMAP.md`).
