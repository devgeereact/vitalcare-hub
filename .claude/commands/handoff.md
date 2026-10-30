---
description: End-of-session handoff — update .agent/CURRENT-TASK.md
---
Update `.agent/CURRENT-TASK.md` for the next session. Use exactly these sections:
Goal, Phase/Slice, Status (DONE/PARTIAL/BLOCKED/NOT TESTED), Changed this session (paths + why),
Checks run (command → result), External changes made, Blockers (what → who), Next (one concrete step),
Next-session prompt (ready to paste).

Base it only on what actually happened in this session: run `git status` and `git diff --stat` to list changes.
No secrets, tokens or personal data. Also update `.agent/PROJECT.yml` if any fact changed.
Then show me the file.
