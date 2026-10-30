# API: Vitalcare Training Hub

Base: `/api`. JSON in and out. Errors: `{"error":{"code":"...","message":"..."}}`; no stack traces, no internals.

| Method | Path | Auth | Response | Errors | Status |
|---|---|---|---|---|---|
| GET | `/api/health` | none | `{"status":"ok","php":"<version>","authConfigured":true|false}` | 405 | DONE locally (tests + HTTP smoke); NOT deployed |
| GET | `/api/me` | Bearer Clerk session token | `{"user":{"id","email","displayName","role","organisationId"}}` | 401 no or invalid token; 403 `no_access` valid identity, no row; 405; 503 not configured | DONE locally; NOT deployed |

Rules: validate every input server-side; owner, role and organisation come from the verified session and the `users` row, never the request. The `Bearer` scheme is case-insensitive. Planned endpoints are added slice by slice (`docs/ROADMAP.md`).
