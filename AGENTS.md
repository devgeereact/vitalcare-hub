# Vitalcare Training Hub — agent guide

Care-sector training, certificates anyone can verify, and the staff compliance register behind them. Vitalcare Training Hub Ltd.

## Start of every session
1. Read `.agent/PROJECT.yml` (facts: tier, stack, targets) and `.agent/CURRENT-TASK.md` (where we are, what's next).
2. Read `docs/PROJECT-MEMORY.md`.
3. Summarise the state in three lines, then continue from **Next**.

Load other docs only when the task needs them:

| Need | Read |
|---|---|
| Rules (always apply) | `docs/RULES.md` |
| What to build | `docs/PRD.md`, `docs/ROADMAP.md` |
| How it is built | `docs/ARCHITECTURE.md`, `docs/API.md`, `docs/SCHEMA.md` |
| How it looks | `docs/DESIGN.md` |
| Why a choice was made | `docs/DECISIONS.md` |
| Deploying / operating | `docs/DEPLOYMENT.md`, `docs/RUNBOOK.md` |
| What has been verified | `docs/VERIFICATION.md` |

## Commands
| Task | Command |
|---|---|
| Install | `pnpm install` (also enables the secret-scan pre-commit hook) |
| Dev servers | `pnpm dev` — Vite on :5175 and the PHP API on :3175 together |
| Everything CI runs | `pnpm check` |
| Fix formatting | `pnpm format` |
| Tests with local temp files | `bash scripts/with-local-tests.sh pnpm test` |

## Non-negotiables (full list in docs/RULES.md)
- Status words: DONE (with evidence), PARTIAL, NOT TESTED, BLOCKED, PLANNED, ASSUMPTION.
- No secrets in code, chat, logs or any `VITE_` variable.
- Ask for an explicit "approved" before: external/account changes, pushes, any public deploy, deleting or moving files you did not create, DB migrations on real data, anything that costs money.
- Never: force-push, `reset --hard`, `clean -fdx`, history rewrites, mirror-delete deploys.
- Finish every session by updating `.agent/CURRENT-TASK.md` (or run `/handoff`).
