# Security and privacy

## Data inventory
| Data | Where stored | Who can see it | Retention | Personal? |
|---|---|---|---|---|

## Secrets (names and locations only — never values)
| Name | Location | Rotated |
|---|---|---|

## Access model (FULL)
| Role | Can | Cannot | Enforced in |
|---|---|---|---|

## Threats considered
- Exposed secrets → gitleaks pre-commit + CI scan; no secrets in `VITE_*`.
- Broken access control → server-side ownership checks + negative tests.
- Stale or cached private data → service worker never caches `/api`.

Legal pages (privacy policy, terms) are drafted with `KIT/library/` prompts and need human/legal review before publishing.
