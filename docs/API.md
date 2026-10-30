# API — Vitalcare Training Hub

Base: `/api`. JSON in and out. Errors: `{"error":{"code":"…","message":"…"}}`.

| Method | Path | Auth | Request | Response | Errors |
|---|---|---|---|---|---|
| GET | `/api/health` | none | — | `{"status":"ok"}` | — |

Rules: validate every input server-side; owner/tenant IDs come from the verified session, never the body.
