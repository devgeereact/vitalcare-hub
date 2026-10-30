# Vitalcare Training Hub

Care-sector training, certificates anyone can verify, and the staff compliance register behind them.
Vitalcare Training Hub Ltd, company number 15718997, registered in England and Wales.

**Status: not live.** The public site at vitalcare.uk is an earlier coming-soon page. This repository is the rebuild; nothing here has been deployed.

## Where to start

| If you want to | Read |
|---|---|
| Know what the product is for | `docs/PRD.md` |
| Know what is planned and in what order | `docs/ROADMAP.md`, `docs/FOUNDATION-PLAN.md` |
| Understand how it is built | `docs/ARCHITECTURE.md` |
| See who may do what | `docs/AUTHORISATION-MAP.md` |
| See which claims may be published | `docs/claims.yml` |
| Know the decisions and open questions | `docs/DECISIONS.md` |

## Commands

```bash
pnpm install     # also enables the secret-scan pre-commit hook once this is a git repo
pnpm dev         # Vite on :5175 and the PHP API on :3175
pnpm check       # format, lint, typecheck, tests, build: the one gate
```

Secrets live only in ignored local `.env` files, GitHub secrets and private server config outside the web root. Anything starting with `VITE_` is public.
