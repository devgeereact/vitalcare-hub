# Audit record

One audit file for this project. Every audit, check or review adds a dated section at the top; nothing is rewritten. Open work lives in `.agent/CURRENT-TASK.md`.

## 31 October 2026: certificate PDF fonts (D-018 item 6), branch `feature/cms`, local only

- **Scope:** names outside Western European letters printed as "?" (WinAnsi base fonts; NOT TESTED list of the CMS build below). Owner approved the fix before launch. Now: Noto Sans Regular and Bold 2.015 (SIL OFL 1.1, `backend/assets/fonts/` with `OFL.txt`), embedded as subsetted Type0 / CIDFontType2 fonts, Identity-H, widths, CID-to-glyph map and ToUnicode; names normalised to NFC; combining marks without a precomposed letter centred over their letter; a missing glyph prints the font's visible box. No Composer, no new dependency (`TrueTypeFont.php` reads and subsets the font). Deploy: `assets/` added to `build-release.sh`, `deploy-api.sh` and the gate's allow-list. Nothing pushed or deployed; no server, Clerk or GitHub setting touched. Test database: the project's throwaway MariaDB on 127.0.0.1:33175 only.

### Evidence (DONE, seen 31 Oct 2026)

| Check | Result |
|---|---|
| `pnpm check` (content, Biome, typecheck, Vitest 114, PHP lint, PHP suites, backup, deploy gate, scripts, build) | PASS, exit 0, before the commit |
| PHP suites | 1,393 assertions in 21 suites. New suite 155 (103): one name each in Latin (Polish, Romanian, German, French), Latin Extended (Czech, Turkish, Icelandic, Maltese), Vietnamese, Yoruba with tone marks on dotted vowels, Igbo, Greek, Cyrillic: every character has a glyph, the name and course copy back out exactly through ToUnicode, no "?" or U+FFFD, identical bytes on a second render, exact xref offsets, trailer and stream lengths, both fonts embedded as CIDFontType2 subsets whose every used glyph has an outline, under 60 KB, and `qpdf --check` clean. Also: NFD input gives the same bytes as NFC; Latin A, Greek Alpha and Cyrillic A copy out as themselves; a tone mark on a capital is raised and moved over its letter; Arabic, Hebrew, Chinese, Japanese, Korean, Devanagari and Thai are reported by `missing()`, drawn as the .notdef box, still copy out as the real characters, and stay well formed. Suite 150's PDF checks rewritten to read text through ToUnicode (77) |
| Mutations of `CertificatePdf.php` (local, restored byte-identical) | 3 of 3 caught: NFC switched off; missing glyphs mapped to a real glyph; mark not moved over its letter |
| Deploy pipeline tests | 98 (new: `api.tar` with the PDF generator but no fonts refused; a non-font file in `assets/` refused; fonts and licence installed beside `src/`; a rollback copy made before `assets/` existed restores without removing the fonts). `deploy-api.sh` test: fonts copied (42) |
| Rendered and looked at | `pdftoppm` (poppler 26.10) and `qpdf` (both installed with Homebrew for this check) at 110 and 300 dpi, and macOS Quick Look (`qlmanage`): "Łukasz Ştefan-Nguyễn", "Ọlá Ẹ̀kọ́ Ọ̀ṣun", "Ngozi Ụzọ Ịfẹ́ ṅ", "Αλέξανδρος Παπαδόπουλος", "Владимир Ёжиков-Щукин" all correct, marks over the right letters, grave raised over capitals; an Arabic and Chinese name shows empty boxes. `pdffonts`: both fonts `CID TrueType`, `Identity-H`, emb yes, sub yes, uni yes. `pdftotext` gives the names back. Samples in `.local-test/pdf-sample/` (not in Git) |
| Size | a certificate for "Łukasz Ştefan-Nguyễn" is 36,245 bytes (two font subsets of about 15 KB each, compressed); the fonts in the repository are 621,572 and 631,484 bytes |

### NOT TESTED

- PDF in Adobe Reader, Chrome's viewer and on paper (poppler and Apple's renderer only).
- Whether the server's web PHP 8.4 has `intl`. Without it names are drawn as stored (no NFC); a decomposed name then still prints, with its marks placed by the box rule. Owner check: `php -m | grep intl` with `/opt/alt/php84/usr/bin/php`, or the cPanel PHP extensions page.
- The new gate on the server: it is not installed yet (DEPLOYMENT.md, one-off install). If a copy was installed before this change, it must be re-installed before the next deploy or it refuses the new `api.tar`.
- Right-to-left, CJK, Indic and Thai names (out of scope, D-018 item 6): they print as boxes. No screen warns the office yet; `CertificatePdf::missing()` exists for that.
- Typographic quality of stacked marks (more than one mark above a letter) and marks on tall lower-case letters: placed by a simple box rule, no OpenType positioning.

## 31 October 2026: CMS build (D-018), branch `feature/cms`, local only

- **Scope:** re-scope from a learning platform to a CMS for the owner (`super_admin`) and office staff (`admin`). Docs, migrations 006 to 010, backend, front end, PDF. Nothing pushed, deployed or applied to any server database; no Clerk, Cloudflare or GitHub setting touched.
- **Database used:** the project's throwaway local MariaDB (`scripts/test-db.sh`, 127.0.0.1:33175, databases `vitalcare_test*` only). One extra throwaway database `vitalcare_test_browser` was created for the browser check and dropped afterwards.

### Evidence (DONE, seen 31 Oct 2026)

| Check | Result |
|---|---|
| `pnpm check` before every commit (content checks, Biome, typecheck, Vitest, PHP lint, PHP suites, backup, deploy-gate and script tests, build) | PASS, exit 0, before each of the commits on the branch |
| PHP suites | 1,294 assertions in 20 suites (was 452 in 11). New: 105 schema (48), 110 permissions route by route (480), 120 people and organisations (39), 130 courses and public pages (36), 140 sessions (33), 150 certificates, expiry, verify, PDF (81), 160 renewals and reminders (39), 170 office (39), 180 CSV, exports, audit, staff accounts (44); 030 updated |
| Front-end tests | 114 tests in 12 files (was 75): courses parsing and pages, admin gate and owner-only nav, form conversion and field errors, verify Revoked |
| Permissions | every `/api/admin` route: anonymous 401; unknown email, trainer, manager, learner and disabled admin 403 `no_access`; admin 403 `forbidden` on the six owner-only permissions; owner let through; a coverage check fails if a new route has no case |
| Mutation checks (`.local-test/mutate.py`, local only) | 24 of 24 mutations caught, sources byte-identical afterwards: course writes, revoke and export opened to admin; trainer let into the CMS; unknown permission allowed; CMS gate removed; by-hand issue without the owner check; route permission skipped; expired on the last day; withdrawn always revoked; revoked answer leaks the name; no month-end clamp; expiry off by a month; expiry from the issue date; a fail certified; reminder marker ignored; stage boundary moved; renewed certificates reminded; opt-out ignored; CSV prefix removed; CSV leading-space formula missed; drafts public; public rate limit removed; audit storing values |
| HTTP smoke through `public/index.php` with `php -S` against a throwaway database | `/api/courses` lists published only; a draft slug is 404; `/api/verify/<revoked>` answers `revoked` with no name; `/api/admin/people` without a token is 401 |
| Real browser (desktop app browser pane) at 375 px against Vite and the throwaway API | course list and detail show the database facts; verify shows Revoked with no name; no horizontal overflow; nav has no learner sign-in, footer has "Staff sign in"; `/admin` without a Clerk key says sign-in is not set up |
| Certificate PDF | rendered by macOS (`sips`) to PNG and inspected: layout correct, A4 landscape; xref offsets, stream length and escaping checked by tests |
| gitleaks pre-commit hook | ran on every commit, no leaks |

### NOT TESTED

- A real Clerk email-code sign-in into `/admin` and every CMS screen in a browser (needs a Clerk key locally or local-live). The screens are covered by unit tests of the shared parts and the API by suites 110 to 180 only.
- Downloads (PDF, CSV) through the real front controller over HTTP with a token (the `RawBody` path in `public/index.php`); the API side is tested.
- Reminder emails actually delivered by SMTP; the queueing is tested, the sender was tested before (suite 100).
- Migrations 006 to 010 on a copy of the production database (only fresh throwaway databases). MariaDB on the server is 11.4; locally Homebrew's MariaDB.
- PDF in Adobe Reader, Chrome and on paper; non-Latin names print as "?" (WinAnsi fonts).
- axe or screen-reader runs on the CMS screens; keyboard use checked by structure only (labels, buttons, native controls).
- Load: lists page at 50 rows; the organisation and course pickers in forms load only the first 50 of each.
- Semgrep (no token), CI on GitHub (nothing pushed).

### Decisions where the docs and the request pulled apart (safer option taken; D-018 "chosen" items)

Owner-only course edits, by-hand certificates, revocation, exports, audit log and staff accounts; expiry from the training date; Revoked shown publicly without name or reason; PDF drawn on request (replaces D-007's stored PDF); trainer, manager and learner roles kept in the schema but refused at sign-in; enquiry statuses widened without rewriting rows; CQC approval wording refused in course text.

## 30 October 2026: release route

- Scope: deploy route alignment with the kit (SSH, no FTP).
- Finding: `deploy.yml` stays manual with owner approval (D-017) until the cutover replaces the live Vitalcare site; turning on deploy-on-push is part of the cutover.
- Result: no change made.
