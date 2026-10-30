# Audit record

One audit file for this project. Every audit, check or review adds a dated section at the top; nothing is rewritten. Open work lives in `.agent/CURRENT-TASK.md`.

## 30 October 2026: release route

- Scope: deploy route alignment with the kit (SSH, no FTP).
- Finding: `deploy.yml` stays manual with owner approval (D-017) until the cutover replaces the live Vitalcare site; turning on deploy-on-push is part of the cutover.
- Result: no change made.
