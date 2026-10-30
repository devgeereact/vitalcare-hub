---
description: Run the definition-of-done checks for the current slice
---
Run the definition of done from `docs/RULES.md §9` for the current slice in `.agent/CURRENT-TASK.md`:

1. `pnpm check` — report each stage PASS/FAIL.
2. `gitleaks git --pre-commit --redact --staged` and `gitleaks dir --redact dist` — report findings count only.
3. List acceptance criteria for the slice and mark each DONE / NOT TESTED / FAIL with evidence.
4. List docs that should change because of this diff (`git diff --stat`) and whether they did.

Do not fix anything yet. Show a short table and ask what to fix first.
