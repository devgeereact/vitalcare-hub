# Back-end inventory: Vitalcare Training Hub (read-only audit for PHP + MariaDB rebuild)

Source: /Users/realmrgee/Developer/Projects/Vitalcare-Training-Hub (nothing edited, run or contacted). Method: read every file in `supabase/functions`, the integration/email/ai/push/exports/certificates/invoices/payroll libs, migrations (grep plus targeted reads), `scripts/imap-pull.mjs`, `.github/workflows/imap-pull.yml`, `docs/`. Status words used: DONE-READ (read in code), NOT TESTED (no runtime evidence), UNKNOWN (live configuration cannot be seen from the repo).

Secret hygiene: no `.env*` opened, no network. Secret-like values found in code (not copied): `src/lib/integrations/weather.ts:5` (hard-coded OpenWeather key shipped in the bundle), `src/lib/supabase/client.ts:10` and `supabase/migrations/010_push_on_notification.sql:36` (Supabase publishable key, public by design but hard-coded), `supabase/functions/gcal-sync-event/index.ts:41` (only the literal text `-----BEGIN PRIVATE KEY-----` used as a PEM stripper, not a key). `src/lib/push.ts:8` holds the VAPID *public* key (fine). `scripts/check-secrets.mjs` matches are scanner regexes.

Global facts that shape the rebuild:
- `supabase/config.toml` has no per-function `verify_jwt` settings; the intended setting is only in each function's header comment ("Deploy: ... --no-verify-jwt"). Live state UNKNOWN.
- Eight functions are public (no JWT): contact-form, google-oauth-callback, google-drive-callback, imap-sync, process-certificate-expiry, process-email-drip, process-profile-reminders, process-reminders. Four of those rely on a single shared `CRON_SECRET` header compared with `!==` (not constant time) (`process-email-drip:24`, `process-reminders:16`, `process-profile-reminders:19`, `process-certificate-expiry:25`, `imap-sync:160`, `send-push:40`).
- All functions send `Access-Control-Allow-Origin: *`.
- Most business logic is not in the functions at all: it is in Postgres (about 70 functions/RPCs, 25 triggers, RLS everywhere). 98 migration slots exist (95 files). The PHP rebuild must re-implement: `issue_course_certificate`, `sync_course_completion`, `submit_assessment_attempt`, `verify_certificate`, `approve_certificate`, `place_order`/`confirm_order`/`set_order_status`, `redeem_coupon`, `archive_course`/`delete_course_permanently`, the guard triggers (`guard_profile_privileged_cols`, `guard_attendance_self_insert`, `guard_order_status`), and all `notify_on_*` triggers. These are out of this inventory's scope except where they send notifications/emails.
- The CLAUDE.md table lists Stripe and Slack; neither exists in code (grep clean). Payments are manual orders/invoices.

---------------------------------------------------------------------------------------------------

## 1. EDGE FUNCTIONS (24 entries: `_shared` plus 23 functions)

### 1.0 `_shared` helpers
| File | What | Notes |
|---|---|---|
| `_shared/auth.ts:15-50` | `requireStaff(req)`: bearer token -> `auth.getUser` -> `profiles.role` in super_admin/admin/manager/trainer/content_editor. `isStaffUserId` for OAuth callbacks | Note the roles list differs from the inline STAFF lists in other functions (see below). Replace with one PHP `requireRole()`. |
| `_shared/oauth-state.ts` | HMAC-SHA256 signed 10-minute OAuth `state` (calendar/drive purpose), env `OAUTH_STATE_SECRET` min 32 chars | Good design, only needed if Google OAuth stays. Unit-tested. |
| `_shared/secrets.ts:6-21` | `getSecret(name)`: DB table `integration_settings` first, then env | Plain-text secrets in a DB table (see `integrations`). |
| `_shared/smtp.ts:14-57` | denomailer SMTP using `SMTP_HOST/PORT/USER/PASS/FROM`, falls back to `RESEND_FROM`; returns `configured:false` so callers fall back to Resend | Sends one message per recipient. |
| `_shared/turnstile.ts:8-30` | Cloudflare Turnstile siteverify; `TURNSTILE_SECRET` | **Fails open when no secret** (line 14). Not used by any function except via an inline copy in contact-form. |

Role lists are inconsistent across functions: `requireStaff` includes content_editor; `drive-*`, `zoom-sync-session`, `one-to-one-email` use `["admin","super_admin","manager","trainer"]`; `send-email`, `send-push`, `process-email-drip` use `["admin","super_admin","manager"]`; `admin-create-learners` uses admin/super_admin only; `integrations` super_admin only.

### 1.1 Per-function table

Legend: Trigger = how it is called. Auth = what protects it. Verdict KEEP / CUT / REBUILD / UNKNOWN.

#### admin-create-learners (`supabase/functions/admin-create-learners/index.ts`)
- Purpose: bulk-create learner accounts (max 500) and patch `phone`, `organisation_id` on the profile created by the `handle_new_user` trigger.
- Trigger: HTTP from browser (`src/lib/queries/learners.queries.ts:307`).
- Auth: JWT verified at gateway (assumed) plus in-code check caller role in (admin, super_admin) (lines 60-71). Service role used inside.
- External: Supabase Auth admin API only.
- Env: `SUPABASE_URL`, `SUPABASE_SERVICE_ROLE_KEY`.
- Tables: reads `profiles`; writes `auth.users` (via admin API, `email_confirm:true`, random password lines 36-39), `profiles`.
- Emails: **none**. Learners are told to "reset via forgot-password" (line 37), so there is no welcome/invite email anywhere in the system.
- Smells: `organisation_id` is taken from the request body, so an admin could place a learner into any organisation (migration 092 line 112 comments on this). `l.phone` unvalidated. Accounts created with confirmed email and unknown password.
- PHP replacement: PHP endpoint `POST /api/learners/bulk` (admin only) that creates the Clerk user via Clerk Backend API (or pre-registers email in MariaDB and lets first Clerk email-code sign-in claim it) and sends an invitation email.
- Verdict: **REBUILD** (effort M). Evidence: wired and used (one caller), core admin workflow.

#### ai-chat (`ai-chat/index.ts`)
- Purpose: generic LLM proxy, Gemini first, OpenRouter fallback, with a long Vitalcare brand system prompt (lines 29-75).
- Trigger: HTTP from browser (`ai.queries.ts:10`, `generate-course.ts:62`).
- Auth: `requireStaff` (line 194), so any staff role including content_editor; JWT gateway on.
- External: `generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key=...` (key in URL query string, line 129, will land in any URL logs); `openrouter.ai/api/v1/chat/completions`.
- Env: `GOOGLE_AI_API_KEY`, `GOOGLE_AI_MODEL` (default `gemini-3.6-flash`), `OPENROUTER_API_KEY`, `OR_MODEL` (default `anthropic/claude-3.5-haiku`), plus `integration_settings` overrides.
- Tables: `integration_settings` (read). No DB writes (conversation persistence is done client-side into `ai_conversations`, `ai.queries.ts:34-58`).
- Emails: none.
- Server-side post-processing: `stripDashes()` removes em/en dashes from every reply (lines 90-103).
- Smells: caller can override the system prompt (`body.system`, line 205) and maxTokens (cap 8192); fine because staff only. Error message tells the user which provider keys are missing (lines 229-235). Brand prompt contains unverifiable marketing claims (see section 4.5).
- PHP replacement: one PHP endpoint `POST /api/ai/complete` (staff), curl to Gemini; drop OpenRouter. 
- Verdict: **REBUILD, reduced** (effort S for the endpoint). Used by about 25 call sites (see 2.9). Decide which surfaces to keep before porting.

#### contact-form (`contact-form/index.ts`)
- Purpose: public marketing contact form -> email to admin(s). Not stored in DB (`docs/PRIVACY-DATA-MAP.md:39`).
- Trigger: HTTP from browser, **public** (`ContactPage.tsx:49`).
- Auth: none (`--no-verify-jwt`). Controls: honeypot field `website` (line 90), optional Turnstile, size limits (lines 106-108), regex email check.
- External: Resend `api.resend.com/emails`, Cloudflare Turnstile siteverify.
- Env: `RESEND_API_KEY` (required), `RESEND_FROM`, `ADMIN_EMAIL` (default hard-coded personal Gmail address, line 78), `ADMIN_EMAIL_SECONDARY`, `TURNSTILE_SECRET`, `SUPABASE_URL`, `SUPABASE_SERVICE_ROLE_KEY`. Reads env directly (`Deno.env.get`), **not** `getSecret`, so setting RESEND_API_KEY in the Integrations UI does not affect it.
- Tables: `integration_settings` read (Turnstile secret) via an inline copy of getSecret.
- Email sent: see 4.1. Fallback to `onboarding@resend.dev` sender on 403 (line 139-145).
- Smells: (a) the browser form collects `subject` and `phone` but the function ignores `subject` and builds its own subject (`ContactPage.tsx:49-57` vs line 121), and `organisation` is never collected though the template prints it (line 116). (b) Turnstile widget **is not in the client** (grep of `src`, `index.html` finds none; `get_public_config` RPC, migration 070, is not called anywhere). So if `TURNSTILE_SECRET` is ever set the form will reject every submission. (c) No rate limit; honeypot only. (d) `detail` of provider failure returned to the browser (line 151). (e) Admin default address is a personal Gmail, hard-coded.
- PHP replacement: PHP endpoint `POST /api/contact` using PHPMailer/`mail()` via cPanel SMTP, honeypot + Turnstile siteverify + per-IP rate limit in MariaDB, optionally store enquiry row.
- Verdict: **KEEP / REBUILD** (effort S).

#### drive-delete / drive-list / drive-upload (`drive-*/index.ts`)
- Purpose: staff file manager and course/recording/workbook uploads stored in a connected Google Drive folder (scope `drive.file`). `drive-upload` makes every file **readable by anyone with the link** (lines 124-129) and returns `https://drive.google.com/uc?export=view&id=...` (line 134).
- Trigger: HTTP from browser. Callers: `files.queries.ts:155,203,235`, `MediaUpload.tsx:49`, `sessions.queries.ts:365` (session recordings), `courses/drive-export.ts:95` (course documents/workbooks incl. review folder), `generate-course.ts` via drive-export.
- Auth: in-code role check, `["admin","super_admin","manager","trainer"]`. JWT gateway assumed on.
- External: `oauth2.googleapis.com/token` (refresh-token grant), `www.googleapis.com/drive/v3/...`, upload API.
- Env / settings (DB-first via getSecret): `GDRIVE_CLIENT_ID`, `GDRIVE_CLIENT_SECRET`, `GDRIVE_REFRESH_TOKEN` (written by `google-drive-callback`), `GDRIVE_FOLDER_ID`, `GDRIVE_REVIEW_FOLDER_ID`, `GDRIVE_API_KEY` (listed in catalogue only).
- Tables: `integration_settings` read.
- Smells: public-by-link files incl. session recordings (faces/voices of learners); no MIME/extension allow-list (50 MB size cap only, line 81); `drive-delete` interpolates unvalidated `id` into the Drive URL path (line 83); the refresh token for the whole organisation sits in a DB table in plaintext; `drive-list` returns `webViewLink` of every file. The Drive path silently falls back to Supabase Storage when "not configured", so what is actually live is UNKNOWN.
- PHP replacement: DROP all three. Replace with a PHP file API storing into a private folder outside webroot and serving through `GET /api/files/{id}` after an access check (see section 5).
- Verdict: **CUT** (Drive) / file API **REBUILD** (effort M).

#### gcal-sync-event (`gcal-sync-event/index.ts`)
- Purpose: write a session to a shared Google Calendar via service account JWT.
- Trigger: **nothing calls it** (grep of src/migrations/scripts/docs finds no caller). `integrations/index.ts:54-58` confirms: "gcal-sync-event, which nothing calls".
- Auth: `requireStaff`. Env: `GOOGLE_SA_JSON` (whole service-account key as env), `GCAL_CALENDAR_ID`.
- Verdict: **CUT** (dead code).

#### gmeet-create-event (`gmeet-create-event/index.ts`)
- Purpose: create a Google Calendar event with a Google Meet link using the single org-wide stored OAuth refresh token.
- Trigger: HTTP from browser: session creation (`sessions.queries.ts:287`), one-to-one approval (`one-to-one.queries.ts`, via `lib/integrations/google-calendar.ts:44`).
- Auth: `requireStaff` (any staff incl. content_editor).
- External: Google OAuth token endpoint, Calendar API `events?conferenceDataVersion=1`.
- Env: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` (env only, not DB), `GCAL_CALENDAR_ID` (default `primary`).
- Tables: reads `google_oauth_tokens` (latest row by `created_at`, line 75-80).
- Smells: one token shared by all staff; any staff user (including content_editor) can create events on the connected account's calendar; `detail` of Google error is returned to the browser (line 114).
- Fallback: client falls back to a deterministic Jitsi room `https://meet.jit.si/vitalcare-session-{id}` / `vitalcare-1to1-{id}` (`sessions.queries.ts:326`, `one-to-one.queries.ts:206`). The Jitsi room names derive from UUIDs, so they are unguessable-ish but permanent and re-usable.
- PHP replacement: either cut and use a "meeting link" text field (Jitsi/Meet/Teams pasted by trainer), or PHP endpoint with the same logic (M).
- Verdict: **CUT for v1** (keep manual link field). Live connection status UNKNOWN.

#### google-oauth-callback / google-drive-callback
- Purpose: OAuth redirect targets. Verify signed state, check signer is staff, exchange code, store refresh token (`google_oauth_tokens` row after deleting all rows, line 97-103; or `integration_settings.GDRIVE_REFRESH_TOKEN`).
- Trigger: browser redirect from Google. Public by necessity. Redirect URIs hard-coded to the Supabase project URL (`google-oauth-callback:19-20`, `google-drive-callback:21-22`).
- Env: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `APP_URL`, `OAUTH_STATE_SECRET`; drive uses DB-first `GDRIVE_CLIENT_ID/SECRET`.
- Migration history: 005 created the token table; 067 locked it; 084 added `google_oauth_status()` RPC to show connection status. The signed state was added in the latest commit (094c640).
- Verdict: **CUT** with the Google features (if Meet kept: REBUILD as one PHP callback, M).

#### imap-sync (`imap-sync/index.ts`)
- Purpose: pull latest 30 INBOX messages over IMAP (imapflow) into `mail_messages`, notify owner(s).
- Trigger: browser "Sync now" (`EmailComposerPage.tsx:260`, personal mailbox) or cron with `x-cron-secret` (org mailbox / `scope:"users"`). In practice the org mailbox is pulled by GitHub Actions `.github/workflows/imap-pull.yml` every 10 minutes running `scripts/imap-pull.mjs`, because the Edge runtime aborts TLS (header comment lines 7-9). Status: edge function partly wired (personal sync only); GH Action wired but secrets state UNKNOWN.
- Auth: either CRON_SECRET or any logged-in user who has a `user_mail_accounts` row. **No role check**: a learner can save a mail account via `user-mail` and then trigger `imap-sync`.
- Env: `CRON_SECRET`, `IMAP_HOST`, `IMAP_PORT`, `SMTP_USER`, `SMTP_PASS` (org mailbox reuses SMTP creds), `SUPABASE_*`.
- Tables: reads `user_mail_accounts` (including `smtp_pass` plaintext), writes `mail_messages`, `notifications`.
- Smells (serious): (a) **IMAP/SMTP passwords stored in plaintext** in `user_mail_accounts.smtp_pass` (migration 024 lines 8-20), used for both SMTP and IMAP; (b) **SSRF/port-scan primitive**: a user-supplied `imap_host`/`imap_port` is connected to from the server (lines 61-71, 173-181); (c) stores raw MIME (`bodyRaw.slice(0,50000)`) in `body_html` (line 112) so the UI needs its own MIME parser (`src/lib/email/mime.ts`) and sanitiser; (d) `scripts/imap-pull.mjs:119-139` uploads inbound email **attachments to the public `course-media` bucket** and stores public URLs; (e) CRON_SECRET compare is not constant time.
- PHP replacement: DROP the in-app webmail entirely (use the cPanel mailbox / Roundcube). If an inbox is demanded, it would be a cPanel cron PHP script using `ext-imap` with credentials in a config file outside webroot.
- Verdict: **CUT** (effort to rebuild would be L; value low; risk high).

#### integrations (`integrations/index.ts`)
- Purpose: super-admin API: list which integration keys are set, set/remove key values into table `integration_settings`, sign Google OAuth state, build Drive auth URL.
- Trigger: HTTP from browser (`IntegrationsManager.tsx:59,74,160,193`, `GoogleIntegrationCard.tsx:77`). Auth: JWT + `role === "super_admin"` (line 110).
- Writes secrets (Resend, SMTP, Zoom, Gemini, OpenRouter, Drive secret, Turnstile secret, VAPID JWK, CRON_SECRET, etc.) into a DB table in plaintext (lines 141-146). Never returns values, only configured flags (good).
- Self-documented inconsistency: Google OAuth/Zoom/GCAL/contact-form read **env only** (`integrations:42-58` comment), so saving them in this UI "has no effect".
- Env: `OAUTH_STATE_SECRET`, plus any key it reads for the `configured` flag.
- PHP replacement: none. Secrets go in a PHP config file outside webroot (`/home/<user>/config/secrets.php`, chmod 600). No admin UI for secrets.
- Verdict: **CUT** (effort 0, simplifies).

#### one-to-one-email (`one-to-one-email/index.ts`)
- Purpose: after staff approve a 1:1 support session, email the learner (and trainer) the meeting details; recipients resolved server-side from request id so addresses never reach the browser.
- Trigger: HTTP from browser (`one-to-one.queries.ts:266`). Auth: JWT + role in admin/super_admin/manager/trainer (line 98).
- External: SMTP (denomailer) preferred, Resend fallback.
- Env/settings: `RESEND_FROM` (default `Vitalcare Training Hub <info@vitalcare.uk>`), `RESEND_API_KEY`, SMTP_*.
- Tables: reads `one_to_one_requests`, `profiles`, `courses`.
- Email: see 4.2. Smell: `meetUrl` is placed in an `href` unescaped (line 55) -> HTML/attribute injection if a staff user stored a crafted URL; trainer role can trigger an email for any request id (no check the trainer owns the request).
- PHP replacement: PHP service `Mailer::oneToOneConfirmation()` called inside the approval endpoint.
- Verdict: **REBUILD** (effort S).

#### process-certificate-expiry (`process-certificate-expiry/index.ts`)
- Purpose: HTTP wrapper to call SQL `notify_expiring_certificates()`. Header says the pg_cron job already does the same daily, "so this function exists for manual or external triggering only".
- Trigger: external only; CRON_SECRET header. Nothing in repo calls it.
- PHP replacement: cPanel cron running a PHP CLI script (see section 3).
- Verdict: **CUT the wrapper**, **REBUILD the logic** as cron (S).

#### process-email-drip (`process-email-drip/index.ts`)
- Purpose: send due rows of `email_campaigns` (status scheduled, `scheduled_at <= now`, 10 per run). Despite the name it is a one-off scheduled broadcast, **not** a multi-step drip sequence.
- Trigger: cron with `x-cron-secret`. **No schedule exists in the repo** (migration 008 only says "on a pg_cron schedule"; no `cron.schedule` for it). Whether it runs is UNKNOWN. UI creates campaigns via `email.queries.ts:47` and `EmailComposerPage` ("Send later").
- Auth: CRON_SECRET only; JWT off.
- Env: `CRON_SECRET`, `RESEND_API_KEY` (**required even when SMTP is configured**, returns 500 at line 32 if missing), `RESEND_FROM`, SMTP_*.
- Tables: reads/writes `email_campaigns` (status scheduled->sending->sent/failed, `sent_count`, `total_count`), reads `profiles` (email, role, `deleted_at`).
- Audiences: all_staff, all_trainers, all_learners/learner, everyone (everyone includes all active accounts, any role).
- Smells: no unsubscribe link, no consent/preference check (UK PECR/GDPR exposure for marketing-style broadcasts); campaign `message` inserted as HTML after only `\n -> <br>` (line 63): HTML injection by any staff who can insert into `email_campaigns` (RLS: `is_staff()`); status flips to "sending" before any send with no lock, so two overlapping runs can double-send; a crash leaves a campaign stuck in "sending".
- Verdict: **CUT for v1** (defer scheduled broadcast); if wanted, REBUILD as cron + `email_campaigns` + `email_outbox` table (M).

#### process-profile-reminders (`process-profile-reminders/index.ts`)
- Purpose: weekly in-app nudge "Complete your profile" for profiles missing name/phone/emergency contact; once per 7 days.
- Trigger: cron + CRON_SECRET; no schedule in repo (UNKNOWN). Reads up to 5000 profiles, then one query per incomplete user (N+1).
- Tables: `profiles`, `notifications`. No emails; push fires through the notification trigger.
- Verdict: **CUT** (nag feature; trivial to re-add in the daily cron script, S).

#### process-reminders (`process-reminders/index.ts`)
- Purpose: turn due `reminders` rows (`sent=false`, `remind_at<=now`, 200 at a time) into `notifications`.
- Trigger: cron + CRON_SECRET; no schedule in repo (UNKNOWN). Writers of `reminders`: `communication.queries.ts:497` (announcement reminder sequence).
- Verdict: **REBUILD only if announcement reminders are kept** (S, one cron script).

#### qr-login (`qr-login/index.ts`)
- Purpose: generate a Supabase **magic link** for the caller's own email and show it as a QR so they can sign in on a phone (`QrLoginDialog.tsx:45`).
- Auth: JWT + `getUser`; link is only generated for the caller's own email (line 48); redirect restricted to `APP_URL` origin (lines 53-63, good).
- Env: `APP_URL`, `SUPABASE_*`.
- Smells: the QR is a live bearer credential on screen (shoulder surfing, screenshots, shared screens in training sessions); depends on Supabase `generateLink`, which does not exist in Clerk; UI text says "Deploy the qr-login function to enable it" (so whether it is live is UNKNOWN).
- Verdict: **CUT** (Clerk email code on the phone is the replacement).

#### send-email (`send-email/index.ts`)
- Purpose: staff broadcast or single email to an audience or explicit address list.
- Trigger: HTTP from browser (`EmailComposerPage.tsx:1008`). Auth: JWT + role admin/super_admin/manager.
- External: SMTP then Resend.
- Env/settings: `RESEND_API_KEY`, `RESEND_FROM`, SMTP_*.
- Tables: reads `profiles` (email, role, `deleted_at`).
- Smells: (a) `body.emails` is an arbitrary list with no cap or domain restriction, so any manager can use it as an authenticated mass mailer from `info@vitalcare.uk` (open relay for logged-in staff); (b) **Resend path sends `to: chunk` of 50 (line 118)**, exposing up to 49 other learners' addresses to each recipient (the SMTP path is per-recipient and the drip function uses BCC); (c) message is inserted as raw HTML (line 90); (d) super_admin may override `from` if it ends with `@vitalcare.uk` (line 70); (e) no unsubscribe, no logging of what was sent (no table) so no audit; (f) `audience` default is all_learners.
- PHP replacement: `POST /api/email/send` with recipient cap, audit row, per-recipient send through cPanel SMTP queue.
- Verdict: **REBUILD** (effort M) or reduce to "announcement email to cohort".

#### send-push (`send-push/index.ts`)
- Purpose: Web Push fan-out to stored subscriptions (`push_subscriptions`) for given user ids using VAPID (`@negrel/webpush`).
- Trigger: (1) DB trigger `trg_push_notification` on every `notifications` insert via `pg_net` (migration 010 lines 32-45), (2) staff browser (code path exists but I found no UI caller of `send-push`; `lib/push.ts` only subscribes).
- Auth: header says "Verify JWT ON". The pg_net call sends `apikey` + `x-cron-secret` but **no `Authorization: Bearer` JWT** (010 lines 33-38). With gateway JWT verification on, that request would be rejected before reaching the function, so DB-triggered push may never have worked. NOT TESTED. Also `app_config.cron_secret` must have been inserted manually; the row is not created by any migration (UNKNOWN).
- Env: `VAPID_JWK`, `VAPID_SUBJECT` (default `mailto:info@vitalcare.uk`), `CRON_SECRET`, `SUPABASE_*`. (`lib/push.ts:4` comment names the secret `VAPID_PRIVATE`, which is wrong.)
- Tables: `push_subscriptions` read/delete; drops subscriptions on any send error (line 87-88, includes transient errors).
- Client: `sw.js` (push + notificationclick), `lib/push.ts`, `NotificationSettingsCard.tsx`, `NotificationsPage.tsx`.
- Verdict: **CUT for v1** (poll notifications; push is PLANNED later via `minishlink/web-push` on PHP, effort M). Status: partly wired, probably not delivering.

#### user-mail (`user-mail/index.ts`)
- Purpose: per-employee mail account: `get` / `set` (store SMTP+IMAP host, user, password) / `send` (SMTP send as that user).
- Trigger: HTTP from browser (`MailSettingsCard.tsx:39,62`, `EmailComposerPage.tsx:861`). Auth: JWT only, **no role check** (any learner can call it).
- Tables: `user_mail_accounts` (RLS on, no policies, service role only) - holds `smtp_pass` plaintext.
- Smells: plaintext third-party mailbox passwords; user-controlled SMTP host/port -> server-side outbound connection to arbitrary host:port (SSRF / internal port probing / spam relay through any open SMTP); `message` raw HTML; `from` display name user-defined.
- Verdict: **CUT** (effort 0). Highest-risk function in the repo.

#### zoom-create-meeting / zoom-sync-session
- Purpose: create a scheduled Zoom meeting (waiting room on, join-before-host on) / pull past-meeting participants and mark `attendance_records` present by email match + fetch cloud recording share URL and write it to `training_sessions.recording_url`.
- Trigger: HTTP from browser: `sessions.queries.ts:263`, `SessionDetailPage.tsx:259`.
- Auth: `requireStaff` for create; role list incl. trainer for sync. A trainer can sync **any** session id (no ownership check) and mark attendance for anyone whose email appears.
- External: `zoom.us/oauth/token` (account_credentials), `api.zoom.us/v2/...` (the report/participants API needs a paid Zoom plan; availability UNKNOWN).
- Env: `ZOOM_ACCOUNT_ID`, `ZOOM_CLIENT_ID`, `ZOOM_CLIENT_SECRET`.
- Tables: `training_sessions` (r/w), `profiles` (read), `attendance_records` (upsert present).
- Smell: attendance is auto-marked "present" for everyone who ever joined, regardless of duration; `start_url` (host link) stored on the session row (`zoom_start_url`) - check who can read it by RLS.
- Verdict: **CUT for v1** (pasted join link is enough); live use UNKNOWN. If kept: REBUILD as two PHP endpoints (M).

### 1.2 Security smells, ranked
1. `user-mail` + `imap-sync` (personal path): plaintext mailbox passwords, user-supplied SMTP/IMAP host (SSRF), no role check. CUT.
2. `scripts/imap-pull.mjs:119-139`: inbound email attachments (private) uploaded to public bucket `course-media`. CUT with webmail.
3. `integrations`: all provider secrets (Drive client secret and refresh token, SMTP password, VAPID JWK, Turnstile secret, CRON_SECRET) in one plaintext DB table. CUT; use config file outside webroot.
4. `send-email` Resend path leaks recipient addresses (`to: chunk`, line 118); arbitrary `emails[]` without cap; raw HTML in message. REBUILD with fixes.
5. `drive-upload` public-by-link; recordings of live sessions public by link. CUT.
6. `contact-form` default recipient is a hard-coded personal Gmail; Turnstile never rendered client-side but enforced server-side when secret set (would break the form).
7. DB-triggered push call lacks Authorization header (likely 401); CRON_SECRET held in `app_config` and shared across 6 functions.
8. All functions CORS `*`; error bodies include provider `detail` to browser (`contact-form:151`, `gmeet-create-event:114`, `gcal-sync-event:147`, `one-to-one-email:187`).
9. `process-email-drip`: no unsubscribe/consent, HTML injection, non-atomic status, requires Resend key even with SMTP.
10. `qr-login`: bearer link rendered as QR.

---------------------------------------------------------------------------------------------------

## 2. INTEGRATIONS

| Integration | Where | Status | Verdict |
|---|---|---|---|
| **Zoom** | `zoom-create-meeting`, `zoom-sync-session`; `sessions.queries.ts:262-275`; `SessionDetailPage.tsx:259`; UI label "Zoom (backup video)" (`integrations:64`) | Wired in code, live use UNKNOWN (needs 3 env secrets, report API needs paid plan) | CUT v1; manual link field |
| **Google Calendar** | `gmeet-create-event` (always called on session create to keep calendar in sync, `sessions.queries.ts:285-300`), `gcal-sync-event` (dead), `lib/integrations/google-calendar.ts`; in-app `CalendarPage` is built from DB tables (`calendar.queries.ts`) | Partly wired. Requires one connected Google account. Live connection UNKNOWN. | CUT v1 (in-app calendar remains; offer `.ics` download instead, S) |
| **Google Meet** | same function, `conferenceData.createRequest hangoutsMeet`; fallback Jitsi | Partly wired; Jitsi fallback guarantees a link | CUT v1; keep Jitsi room fallback or paste link |
| **Google Drive** | `drive-*`, `google-drive-callback`, `files.queries.ts`, `MediaUpload.tsx`, `drive-export.ts`, `generate-course.ts` | Wired with graceful fallback to Supabase Storage (`files.queries.ts:153-185`, `MediaUpload.tsx:46-62`). Which backend is live: UNKNOWN. | CUT; private file API |
| **Google OAuth (sign-in)** | `lib/supabase/auth.ts:48` `signInWithOAuth`; `006_oauth_profile_names.sql` maps Google name/picture to profile; `CoverLoginPage.tsx:12` | Wired (Supabase provider configured in dashboard, not in repo) | Replaced by Clerk (Clerk supports Google social login natively). |
| **Google OAuth (Calendar/Drive connection)** | callbacks + `integrations` + `GoogleIntegrationCard.tsx` | Wired; one org-wide token | CUT |
| **IMAP / mail inbox** | `imap-sync`, `scripts/imap-pull.mjs`, `.github/workflows/imap-pull.yml`, `EmailComposerPage.tsx` (full webmail: folders, labels, drafts, trash, AI reply), `InboxPage`, `MailDetailPage`, tables `mail_messages` (+ 023,024,025,036,037) | Wired and used by staff (route `/platform/email` open to super_admin, admin, manager, trainer, content_editor, `routes/index.tsx:607-613`). GH Action secrets UNKNOWN | **CUT** |
| **Resend** | contact-form, send-email, one-to-one-email (fallback), process-email-drip | Wired. contact-form needs env key. Whether a verified domain exists: UNKNOWN (code comments say verified, with a testing-mode fallback). | Replace with cPanel SMTP (PHPMailer) with SPF/DKIM on vitalcare.uk. |
| **SMTP (org)** | `_shared/smtp.ts` | Wired as preferred sender when SMTP_* set (UNKNOWN) | KEEP as the single mail transport. |
| **Supabase Auth emails** | confirmation, recovery, magic link (qr-login) sent by Supabase Auth; templates live in dashboard (not in repo); `docs/LAUNCH-READINESS.md:143-160` says SMTP for Auth may not be configured ("Error sending confirmation email") | UNKNOWN | Disappears with Clerk. |
| **Web push (VAPID)** | `lib/push.ts`, `public/sw.js`, `send-push`, `trg_push_notification` (010), table `push_subscriptions` | Partly wired; DB-trigger path likely rejected by gateway (see 1.1 send-push). Browser side wired (settings card, notifications page). NOT TESTED | CUT v1; PLANNED |
| **Cloudflare Turnstile** | server verify in contact-form + `_shared/turnstile.ts`; `get_public_config` RPC (070) | **Stub**: no widget in client; verification fails open without secret | REBUILD properly (S): widget on contact form (and sign-in if desired), server verify |
| **AI: Gemini** | `ai-chat` (primary) | Wired; funding of the Gemini account was a recurring issue (comments in `ai.queries.ts:18-23`, `ai-chat:223-226`) | KEEP one provider |
| **AI: OpenRouter** | `ai-chat` fallback (default model `anthropic/claude-3.5-haiku`) | Wired as fallback only | CUT (one provider is simpler) |
| **OpenWeather** | `lib/integrations/weather.ts` called directly **from the browser** with a key hard-coded in source (line 5) as fallback to `VITE_OPENWEATHER_API_KEY`; `WeatherWidget.tsx` on `DashboardPage` (refetch 5 min); UI catalogue also lists `OPENWEATHER_API_KEY` but nothing server-side uses it | Wired, cosmetic | CUT (dashboard weather has no training value) |
| **Public holidays** | `lib/integrations/holidays.ts` -> `https://date.nager.at/api/v3/PublicHolidays/{year}/GB` from the browser (`CalendarPage.tsx`) | Wired, third-party call from every user's browser | Replace with a static `bank-holidays.json` committed to repo or a yearly PHP cache (S) |
| **Jitsi (meet.jit.si)** | `sessions.queries.ts:326`, `one-to-one.queries.ts:206`; named in Privacy Policy `PrivacyPolicyPage.tsx:93` | Wired fallback | KEEP as zero-config fallback |
| **QR login** | `qr-login`, `QrLoginDialog.tsx` | Wired in code; deployment UNKNOWN | CUT |
| **QR certificate verification** | `qrcode.react` in `certificates/pdf.ts:86-111` and `CertificatePreview.tsx`; QR encodes `https://vitalcare.uk/verify?id={uuid}` | Wired, client-side | KEEP (generate client-side or `endroid/qr-code`) |
| **Stripe / Slack** | none in code | Absent (docs only) | n/a |
| **GitHub Actions IMAP pull** | `.github/workflows/imap-pull.yml` (cron every 10 min) | Wired; needs 6 secrets incl. service role key | CUT |
| **Google Fonts / Unsplash** | `index.html`, `src/data/marketing-images.ts` (per privacy map) | Wired; third-party requests | Self-host fonts, local images (S) |

### 2.9 AI detail: where it is called, prompts, data sent

All calls go through `sendChat()` (`ai.queries.ts:9`) or `aiJson()` (`generate-course.ts:61`) to `ai-chat`; every request is prefixed server-side with the VITALCARE_BRAND system prompt (`ai-chat:29-75`). Also sent is whatever the UI puts in the user message.

| Surface | File:line | Visible to | Data sent to model | Personal data? |
|---|---|---|---|---|
| AI Assistant chat page | `pages/platform/ai/AiAssistantPage.tsx:36` (suggestions e.g. "Summarise the CSTF mandatory training topics.") | route access per router (staff via ai-chat gate) | whole conversation (last 20 messages), conversations are also saved to `ai_conversations` | only what the user types |
| Draft learner chat reply | `components/communication/AiReplyButton.tsx:48-62` | admin/trainer/manager | the other person's **name** plus the **last 8 messages of the direct-message thread** (`history.slice(-8)`) | **Yes: learner name and message content** |
| Draft email reply | `EmailComposerPage.tsx:201-214, 516` | staff | sender name/address, subject, **first 4000 characters of the received email body** | **Yes: external senders' names, addresses, email content (may include learner/patient details)** |
| "AI: write bio" | `AccountSettingsCard.tsx:154`, `ProfileDetailsCard.tsx:96` (gated `isAdmin` in `AiFieldsButton.tsx:~38`) | admin | first and last name | Yes (name only) |
| Generate fields for course, session, quiz, blog, announcement, forum, Q&A, library, department, calendar entry, email subject | `AiFieldsButton` used in 14 files (list: AccountSettingsCard, ThreadBoard, ProfileDetailsCard, ForumsPage, QuizBuilderPage, AnnouncementsPage, QaWallPage, DepartmentsPage, LibraryPage, BlogEditPage, CourseBuilderPage, CalendarPage, SessionFormPage, EmailComposerPage) | admin only (`isAdmin`) | working title / topic string | No |
| "AI assist" free text | `AiAssistButton` in QuizBuilderPage, LibraryPage, BlogEditPage, CourseBuilderPage, SessionFormPage | admin only | title, summary, excerpt | No |
| **Generate a full course** | `lib/ai/generate-course.ts:82-130`+ (4 to 6 modules, 3 lessons each, 200-300 words of HTML per lesson, 10 MCQs with answers) then uploads 3 documents (Full Course, Learner Workbook, Trainer Workbook) to the Drive review folder (`drive-export.ts`) and appends a line "Overseen by Harni Muharami RN MSc, Clinical Director." (`generate-course.ts:171`) | admin | course name only | No personal data. **Content risk**: auto-generated clinical content labelled CSTF-aligned, CPD hours and "Overseen by" the Clinical Director without a human review gate in code; `cstf: bp.cstf ?? true` defaults to true (`generate-course.ts:~125`). |

Prompts: brand/voice system prompt (identity, positioning, differentiators, tone rules, banned words), `GEN_SYSTEM` for course generation (`generate-course.ts:11-15`), per-surface task prompts built from strings above. Model defaults: `gemini-3.6-flash` and `anthropic/claude-3.5-haiku` (the "3.6" Gemini name appears in code; whether that model exists is not verifiable from the repo, NOT TESTED).

Learner/patient data conclusion: two surfaces (AiReplyButton, email AI reply) send third-party personal data and message content to Google (US) and possibly OpenRouter (US). Privacy map lists both as recipients (`docs/PRIVACY-DATA-MAP.md:61-62`) but describes only "text submitted to the writing assistant". Recommend CUT both, keep admin-only authoring helpers with no personal data.

---------------------------------------------------------------------------------------------------

## 3. CRON / SCHEDULED

| # | Job | Where defined | Schedule | What it does | Tables / state needed | Rebuild |
|---|---|---|---|---|---|---|
| 1 | `certificate-expiry-alerts` | pg_cron, `migrations/033_certificate_designer_expiry.sql:160-165` | `0 8 * * *` (08:00 daily, DB timezone presumably UTC) | `notify_expiring_certificates()`: for each non-deleted `learner_certificates` with `expires_at <= now()+30 days`, insert one in-app notification per stage ("Certificate expiring soon" at <=30 days or "Certificate expired"), dedupe via `certificate_expiry_alerts(certificate_id, stage)` unique; push fires from trigger | `learner_certificates(expires_at, deleted_at, learner_id, course_id)`, `courses(title, renewal_months)`, `certificate_expiry_alerts`, `notifications` | cPanel cron `0 8 * * * php /home/USER/app/cron/cert-expiry.php`, S. **Note**: comments in 097 and the backfill script say the owner is "emailed", but the SQL creates only an in-app notification (+push), no email; decide if the PHP version should email. Applying an expiry backfill makes the first run alert everyone whose cert is already lapsed (see `scripts/backfill-certificate-expiry.mjs`). |
| 2 | `session-start-reminders` | pg_cron, `migrations/089_notification_reminders_forum.sql:46-53` | `*/15 * * * *` | `notify_session_reminders()`: for `training_sessions` with `status='scheduled'`, `deleted_at is null`, `starts_at` between now and now+30 minutes and no row in `session_reminders`: insert "Session starting soon" for each booked learner (`session_bookings.learner_id`, not deleted) and record in `session_reminders` | `training_sessions`, `session_bookings`, `session_reminders`, `notifications` | cron `*/15 * * * *`, S. Trainer gets no reminder (only bookings). |
| 3 | `process-email-drip` | Edge function; **no schedule in repo** | UNKNOWN (header says pg_cron, none defined) | scheduled broadcast sender | `email_campaigns`, `profiles` | defer (see 1.1) |
| 4 | `process-reminders` | Edge function; no schedule in repo | UNKNOWN | due `reminders` -> `notifications` | `reminders`, `notifications` | cron every 5-15 min if kept |
| 5 | `process-profile-reminders` | Edge function; no schedule in repo | UNKNOWN ("weekly") | profile completeness nudge | `profiles`, `notifications` | CUT |
| 6 | `process-certificate-expiry` | Edge function; wrapper only | not scheduled; pg_cron job #1 does the work | manual trigger | n/a | CUT wrapper |
| 7 | `imap-sync` (cron mode) | Edge function | no schedule in repo | org / users inbox | `mail_messages`, `user_mail_accounts` | CUT |
| 8 | IMAP pull workflow | `.github/workflows/imap-pull.yml:9` | `*/10 * * * *` | `scripts/imap-pull.mjs` | `mail_messages`, public bucket | CUT |
| 9 | DB trigger, per notification | `trg_push_notification` (010) via `pg_net` | every insert on `notifications` | POST to `send-push` | `app_config.cron_secret`, `push_subscriptions` | n/a (v1 no push) |
| 10 | Client polling | `communication.queries.ts:31` | every 60 s, not in background | notifications list | `notifications` | keep |

Other repeating work in the browser: weather widget refetch every 5 minutes (`WeatherWidget.tsx:16`) - cut.

The "drip" the brief mentions does not exist as a multi-step sequence: `email_campaigns` is one row per broadcast (migration 008 columns: subject, message, audience, scheduled_at, status, counts).

Rebuild shape on cPanel: one PHP CLI entry `cron/run.php <task>` with tasks `cert-expiry` (daily 08:00), `session-reminders` (*/15), `reminders` (*/10, if kept). Idempotency already supplied by the dedupe tables (`certificate_expiry_alerts`, `session_reminders`, `reminders.sent`); keep them. Add a `cron_runs` log table or log file.

In-app notification producers to port (25 DB triggers; all `AFTER INSERT/UPDATE` creating `notifications` rows; implement as one `Notifier::send()` call in the PHP handler for the same action): enrolment, booking, certificate issued/approved (`009`, `082`, `088`), course completed, blog post published, session created, trainer assigned, new message (title "New message from {name}"), store order (staff) and "Order confirmed" (buyer), invoice sent, payslip available, feedback to review / feedback published, forum reply, department task assigned, blog 'likes milestone', file uploaded, new email (imap). Titles listed in 4.4.

---------------------------------------------------------------------------------------------------

## 4. EMAILS

Sender address default everywhere: `Vitalcare Training Hub <info@vitalcare.uk>` (env `RESEND_FROM`). Brand/legal words used inside emails: Vitalcare Training Hub / Vitalcare Training Hub Ltd, Company No. 15718997, England and Wales, 11 Halesworth Road, London SE13 7TJ, 020 8059 8757, info@vitalcare.uk, vitalcare.uk, vitalcare.uk/verify, "CSTF-aligned, CPD-accredited, verifiable at vitalcare.uk/verify", brand colours #1b2e6b navy. Named people in emails: none directly (trainer/learner names are data).

### 4.1 Contact form -> admins (`contact-form/index.ts:110-121`)
- Trigger: public form submit. To: `ADMIN_EMAIL` (default personal Gmail, line 78) plus `ADMIN_EMAIL_SECONDARY`; `reply_to` = submitter. Subject `Contact enquiry from {name}`. Template inline HTML: heading "New contact enquiry" navy, rows Name / Email / Organisation (never populated) / Phone, message in a `<p>`. No brand claims. Field `subject` from form is discarded.

### 4.2 1:1 session confirmation (`one-to-one-email/index.ts:37-79,159-165`)
- Trigger: staff approve a one-to-one request. To: learner + trainer emails. Subject and H1: "Your 1:1 session is confirmed". Intro: "Hello {name}, your one to one support session has been approved. The details are below. Join from the button when it is time, or find it any time under One to one in your platform." Rows Session / When / Trainer. CTA button "Join the meeting" to `meet_url`. Header image `https://vitalcare.uk/logos/logo-horizontal-white.svg`. Footer: "Vitalcare Training Hub Ltd · Company No. 15718997 (England and Wales) / 11 Halesworth Road, London SE13 7TJ · 020 8059 8757 · info@vitalcare.uk · vitalcare.uk / CSTF-aligned, CPD-accredited, verifiable at vitalcare.uk/verify". Claim to review: CSTF/CPD line on a logistics email.

### 4.3 Staff broadcast / single / scheduled campaign
- `send-email` (immediate) and `process-email-drip` (scheduled). Subject: free text from staff. Body: staff text in a `<div>` with `\n->&lt;br&gt;`, footer paragraph "Vitalcare Training Hub · CSTF-aligned, CPD-accredited" (`send-email:92`, `process-email-drip:65`). Sender for super_admin may be any `@vitalcare.uk` address. No unsubscribe, no postal address, no PECR statement. Audiences: all_learners / all_trainers / all_staff / everyone / single / explicit `emails[]`.

### 4.4 Emails that do not exist (important gaps)
- No welcome / invitation email on `admin-create-learners` or registration beyond Supabase Auth's own.
- No certificate-issued email (only an in-app notification "Certificate issued": "{course} ... verifiable at vitalcare.uk/verify", `009:82-83` contains an em-dash, later replaced in 082/088).
- No certificate-expiry email despite comments.
- No session-reminder email, no invoice email, no payslip email, no password-reset template in repo (Supabase Auth).
- CLAUDE.md lists `src/lib/email/{welcome,certificate,session-reminder,contact-form}.ts`; **none exist**. `src/lib/email/` only contains `mime.ts` (a raw-MIME parser for the webmail).

### 4.5 In-app notification texts (these become the same-wording notifications in the rebuild)
Enrolment: "Enrolled on {course}" / "You have been enrolled. Start learning when you are ready."; Booking: "Booked: {session}" / "Scheduled for DD Mon YYYY, HH24:MI"; Certificate: "Certificate issued" / "Your certificate for {course} ..."; "Course completed" / "{course} is complete. Well done."; "New article published"; "New session scheduled"; "Session assigned to you"; "New message from {name}"; "New store order", "Order confirmed" / "Your order has been confirmed."; "New invoice" / "Invoice {n} is ready."; "New payslip" / "Your payslip for {period} is available."; "New feedback to review", "Your feedback is published" / "Thank you. Your feedback has been approved."; "Session starting soon" / "{title} starts within 30 minutes."; "Certificate expiring soon" / "... expires in N day(s). Renew it to stay compliant."; "Certificate expired" / "... has expired. Book a refresher to stay compliant."; "Complete your profile" / "Please add your phone number and an emergency contact in Settings."; "New email"; "Task assigned to you"; "New reply: {thread}"; "Your article reached N likes"; "New file uploaded".

### 4.6 Email-related brand/claim text outside emails (AI prompt, `ai-chat:29-75`)
Injected into every AI generation, with claims that need owner verification before any rebuild reuses them: "Founded May 2024; website and trading from April 2026", "ICO registered", NHS trust names (Lewisham and Greenwich, Oxleas, South London and Maudsley, King's College Hospital, Guy's and St Thomas', SEL ICB), "certificates ... issued within 24 hours of completion", "records retained 7 years", "Assessments at an 80% pass mark", "every graduate gets free CV support, personal statement guidance and career strategy", "190+ courses across 15 categories" with per-category counts, "groups of 6 or more", positioning against "national eLearning platforms". Leadership named: Gideon Akinlotan (Founder and CEO), Harni Muharami RN MSc (Co-Founder and Clinical Director, "Registered Nurse (NMC) with NHS leadership experience who oversees ALL clinical content"). Other prompt copies: `AiReplyButton.tsx:23-29`, `EmailComposerPage.tsx:192-198`, `generate-course.ts:11-15` (banned-word lists).

---------------------------------------------------------------------------------------------------

## 5. STORAGE

Buckets created in migrations (3), plus hosted-state unknowns:

| Bucket | Created | Public? | Size / MIME limits | Policies | Holds | Code paths |
|---|---|---|---|---|---|---|
| `course-media` | `012_course_media.sql:10-12` (`on conflict ... set public = true`) | **Public** | None set in SQL (client-side caps only: 5 MB images `ProfileImageUpload.tsx:~43`, 10 MB files `MediaUpload.tsx:37`, 10 MB chat `MessagesPage.tsx:98`). `config.toml:117` global 50MiB. | SELECT policy created then dropped in `053_security_lints.sql:83-84` (listing disabled; public URL still works). INSERT/UPDATE/DELETE require `is_staff()` (012 lines 19-29). | Course thumbnails/lesson files, profile avatars and banners (`ProfileImageUpload`), **direct-message attachments** (`chat/{userId}/{Date.now()}-{name}`, `MessagesPage.tsx:107`), AI-generated course images (`generate-course.ts:224-229`), **inbound email attachments** (`mail/{uuid}-{name}`, `scripts/imap-pull.mjs:119-134`) | `getPublicUrl()` in 6 places |
| `certificate-signatures` | `033:50-52` | **Public** | none | read policy dropped in 053; write staff-only | Signature image per certificate template | `certificates.queries.ts:439-446` |
| `files` | `029_files_bucket.sql:1-3` | **Private** | none | SELECT/INSERT/DELETE `is_staff()` only | Staff file manager (fallback when Drive not connected); list limit 500 | `files.queries.ts:125-258`; only signed-URL use in the repo is here: `createSignedUrl(path, 120)` (`files.queries.ts:252`, 120 s) |

Findings:
- **Public bucket holding personal data**: `course-media` contains direct-message attachments (learner<->staff, path predictable from user UUID plus millisecond timestamp), profile photos, and private inbound email attachments. All permanently reachable by URL with no auth. Highest priority to fix in the rebuild: everything personal goes to the private folder served through an authenticated API.
- Likely functional bug (NOT TESTED): INSERT on `course-media` is `is_staff()` only, yet learner avatar upload and learner chat attachments upload to the same bucket from learner accounts. Unless storage policies were changed in the dashboard (UNKNOWN; no later migration changes them), those uploads fail for learners.
- Google Drive is the preferred store when connected (files public-by-link), so the effective storage in production is UNKNOWN.
- No server-side MIME or extension validation anywhere; no virus scan.
- Certificates are not stored: PDFs are generated in the browser on demand (section 7). Invoices and payslips likewise.
- Recording uploads go to Drive (`useUploadRecordingToDrive`, public link stored on `training_sessions.recording_url`).

Rebuild: private directory outside webroot (`/home/USER/storage/{uploads,signatures}/`), table `files(id, owner_id, kind, original_name, mime, size, sha256, stored_name, visibility, created_at)`, endpoints `POST /api/files` (multipart; allow-list of MIME + extension; size cap; random stored name) and `GET /api/files/{id}` (checks ownership/role, `X-Content-Type-Options: nosniff`, `Content-Disposition`, optional `X-Accel`-style stream via `readfile`). Public marketing images: ship as static assets in the build, not uploads. Avatars: serve via the same API with a small cache header (effort M total).

---------------------------------------------------------------------------------------------------

## 6. REALTIME / PUSH

`.channel(` occurrences (grep over `src`): 2.

| Channel | File:line | Subscribed table / event | UI depending on it | In publication? | Polling feasibility |
|---|---|---|---|---|---|
| `notifications:{userId}` | `lib/queries/communication.queries.ts:61-82` | `public.notifications` INSERT filtered `user_id=eq.{id}` | Bell dropdown (invalidates the query) plus toast on new row (`useNotificationsRealtime`) | Yes: `046_notifications_realtime.sql:15-19` adds it to `supabase_realtime` | Easy. `useNotifications` already polls every 60 s (`communication.queries.ts:31`, `refetchIntervalInBackground:false`) and its own comment calls the poll "the fallback". Use 30-60 s plus refetch on window focus; endpoint `GET /api/notifications?since=` with `If-None-Match`. Cost is one cheap indexed query per active user per minute. |
| `messages:{userId}:{otherId}` | `pages/platform/messages/MessagesPage.tsx:141-162` | `public.messages` all events (INSERT/UPDATE/DELETE), **no server-side filter**, client filters by sender/recipient | Live 1:1 chat in `MessagesPage` (new messages and read receipts), plus the thread list refresh | **Not found in any migration** (only `notifications` is added). Unless enabled in the dashboard (UNKNOWN), this channel never fires and chat only updates on manual refresh/navigation | Feasible: while a conversation is open poll `GET /api/messages?with=ID&after=ID` every 5-10 s, only when tab visible; thread list every 30-60 s. Chat is low volume (direct messages between staff/learners). |

Nothing else subscribes (no presence, no broadcast). Web push (section 2) is a separate delivery path. Replacement summary: realtime -> polling with visibility-aware intervals; keep `notifications` toast on poll diff.

---------------------------------------------------------------------------------------------------

## 7. PDF / EXPORT

All generation is **client-side in the browser**; the server only stores data (no server renders PDFs or xlsx). Libraries: `jspdf ^4.2.1`, `jspdf-autotable ^5.0.8`, `exceljs ^4.4.0` (lazy chunk), `qrcode.react ^4.2.0`, `file-saver`.

| Document | File | Where | Data source | Notes / wording claims |
|---|---|---|---|---|
| **Certificate PDF** (A4 landscape) | `src/lib/certificates/pdf.ts:174+` (`downloadCertificatePdf`) | Browser (jsPDF + canvas), callers `CertificatesListPage.tsx:247`, `CertTemplatesPage.tsx:311`, `MyFilesPage.tsx:45` | `learner_certificates` joined with course/profile; template fields from `certificate_templates` | Navy band with white logo (`/logos/logo-horizontal-white.svg`), gold rule, title (default "Certificate of Completion"), intro ("This is to certify that"), learner name, completion text ("has successfully completed"), course title, accreditation line (default "CSTF-aligned, CPD-accredited"; template default "CSTF-aligned, CPD-accredited, verifiable at vitalcare.uk/verify", `033:30-32`), ISSUED / EXPIRES dates, centred **typed cursive "signature"** drawn from the signatory name in Dancing Script (not a real signature unless an image is supplied; `pdf.ts:~310`), signatory name+role (default "Harni Muharami RN MSc", "Clinical Director") and "Vitalcare Training Hub Ltd" beneath, QR code "Scan to verify" + `VC-XXXXXX` code, footer "Vitalcare Training Hub Ltd · Company No. 15718997 · Verify at vitalcare.uk/verify", file name `vitalcare-certificate-{uuid8}.pdf`. CPD hours were deliberately **removed from the face** (`pdf.ts` interface comment). The QR URL is `https://vitalcare.uk/verify?id={uuid}` (`pdf.ts:55`). |
| Presets | `certificate_templates.preset` (`042_certificate_preset.sql`: completion / participation / achievement) and spec `docs/certificate-template-presets.md` | **Not implemented**: `grep preset src` returns nothing. The PDF has a single fixed layout and the preview (`CertificatePreview.tsx`) does not read `preset`. | - | Designer lets admins edit section texts and signature image only. |
| Approval and verification | DB: `approve_certificate`, `verify_certificate(p_code)` (095) returns `valid / expired / not_issued`, public RPC, returns no personal data for unapproved certs; learner cannot download until `approved` (`CertificatesListPage.tsx:241-245`) | Server (Postgres) | - | Port as PHP `GET /api/verify/{code}` public, rate-limited, same three states. This is the only public data endpoint and must not reveal names for unapproved certs. |
| **Invoice PDF** (A4) | `src/lib/invoices/pdf.ts:24+` (`downloadInvoicePdf`) | Browser (jsPDF + autotable) | `invoices` table | Letterhead navy band with company name, address, email, phone; "INVOICE", number, issue/due dates, status; FROM block (legal name, address, email, phone, "Company No. 15718997"), BILL TO; line items table; totals; "PAYMENT TERMS" band: "Payment is due by {date}. Please quote invoice {number}. Made payable to Vitalcare Training Hub Ltd."; footer "Vitalcare Training Hub Ltd · Company No. 15718997 (England and Wales) · vitalcare.uk · info@vitalcare.uk". **No bank details, no VAT number**. Print stylesheet `invoices/print.css`. |
| **Payslip PDF** | `src/lib/payroll/payslip.ts` | Browser (jsPDF) | `payroll` table (`staff_name`, `staff_email`, `period`, gross, deductions, net) | Company header with Company No.; EMPLOYEE / PAY DETAILS blocks; footer: "This is a computer-generated payslip and does not require a signature. Please retain it for your records. Authorised by Gideon Akinlotan, Founder & CEO." (`payslip.ts:125-127`). **No PAYE, NI, tax code or pension calculation exists**: payroll is a record-keeping table only. Legal exposure: a document called "payslip" without statutory fields; treat as "pay statement" or cut. |
| Lesson handout PDF | `src/lib/courses/lesson-handout.ts` | Browser | lesson HTML | Footer on each page: company line and "CSTF-aligned, CPD-accredited, verifiable at vitalcare.uk/verify". |
| **Workbooks (.xlsx, ExcelJS)** | `src/lib/exports/{engine,registry,theme,types,download}.ts` + `builders/*` (6 reports: Booking Register, Certificate Log, Finance Tracker, Learner Progress, Training Matrix, Business Overview), UI `pages/platform/reports/ReportsPage.tsx` | Browser (`downloadWorkbook`, ExcelJS dynamic import) | Live data fetched via the same client queries; some sheets are blank templates and are labelled "(blank template)" (`builders/shared.ts:76-84`) | Creator = "Vitalcare Training Hub Ltd". Hard-coded content in templates: TRAINERS = Harni Muharami RN MSc and Gideon Akinlotan; SUMMARY_COURSES BLS/IPC/Manual Handling/Safeguarding Adults; JOB_ROLES; ORG_TYPES (Care Home, NHS, ...); months of 2026; **certificate-log template formula sets expiry = issue date + 3 years** (`certificate-log.ts` EXPIRY_FORMULA) which conflicts with per-course `renewal_months`; Finance Tracker only adds VAT when `VAT.registered` in constants. `scripts/verify-workbooks.mjs` is a checker. Training Matrix reads `staff_training_records` / `staff_training_requirements` (061). |
| Course "documents" | `courses/drive-export.ts` | Browser builds HTML/Word-like docs (`docFile`, `esc`), uploads to Drive review folder | course content | Contains "CPD hours: n · Duration: n mins · CSTF-aligned" (`drive-export.ts:51-52`). |

Rebuild options (decision for the owner): keep **client-side** generation for certificates, invoices, payslips (no server change, jsPDF stays in the bundle, S) or move to server-side (TCPDF/Dompdf/mPDF via Composer on cPanel, M each). Recommendation: certificates server-rendered once at approval and stored (so the issued document is immutable and reproducible for audit, M), invoices and workbooks stay client-side (S), payslips CUT or rename.

---------------------------------------------------------------------------------------------------

## 8. CAPABILITY -> REPLACEMENT TABLE

Target stack: React PWA + PHP 8.2 API + MariaDB + Clerk email-code auth, files in private folder outside webroot served via API, realtime -> polling, cron -> cPanel cron. Effort S (<1 day) / M (1-3 days) / L (>3 days).

| Capability | Current | Recommended replacement | Effort | Verdict |
|---|---|---|---|---|
| Auth, sessions, roles | Supabase Auth + `handle_new_user` trigger + role in `profiles`, RLS | Clerk email code; verify Clerk JWT in PHP middleware (JWKS), map `sub` -> `users` row; role stored in MariaDB; central `authorise()` replaces RLS | L | REBUILD |
| Bulk learner creation | `admin-create-learners` | `POST /api/learners/bulk` + Clerk Backend API invite | M | REBUILD |
| QR phone sign-in | `qr-login` | none (Clerk email code on phone) | 0 | CUT |
| Contact form | `contact-form` + Resend | `POST /api/contact`, PHPMailer via cPanel SMTP, honeypot, Turnstile, rate limit | S | REBUILD |
| Transactional email (1:1 confirmation) | `one-to-one-email` | `Mailer` class, same template in PHP | S | REBUILD |
| Staff broadcast email | `send-email` | `POST /api/email/broadcast` with recipient cap, audit table, per-recipient send (no shared `To`) | M | REBUILD (reduced) |
| Scheduled campaigns | `process-email-drip` (no schedule in repo) | defer; later cron + outbox | M | CUT v1 |
| Welcome / invite / certificate-issued emails | none exist | add three simple templates | S each | NEW (recommended) |
| In-app notifications | 25 SQL triggers + table + 60 s poll | PHP `Notifier::send()` invoked by handlers; `GET /api/notifications` poll | M | REBUILD |
| Realtime notifications/chat | Supabase Realtime (2 channels) | polling 30-60 s (notifications), 5-10 s while chat open | S | REBUILD |
| Web push | VAPID + `send-push` + `pg_net` (probably not delivering) | none v1; later `minishlink/web-push` | M later | CUT v1 |
| Certificate expiry alerts | pg_cron daily 08:00 | cPanel cron + PHP script, keep dedupe table | S | REBUILD |
| Session start reminders | pg_cron `*/15` | cPanel cron + PHP script | S | REBUILD |
| Profile-completeness nudges | cron fn (unscheduled) | fold into UI banner | 0 | CUT |
| Announcement reminders | `reminders` + `process-reminders` | cron every 10 min if feature kept | S | KEEP if used |
| File storage (course media, avatars, attachments, staff files, recordings) | 3 buckets + Google Drive public links | private folder + `files` table + authenticated download endpoint | M | REBUILD |
| Google Drive integration | 3 functions + callback | none | 0 | CUT |
| Google Calendar/Meet integration | 2 functions + callbacks + OAuth state | manual meeting link; `.ics` export | S | CUT v1 |
| Zoom integration | 2 functions | manual link | 0 | CUT v1 |
| In-app webmail, IMAP, per-user SMTP | `imap-sync`, `user-mail`, GH Action, `mail_messages` | none (use cPanel mailboxes) | 0 | CUT (biggest risk reduction) |
| Integrations admin (secret UI) | `integrations` + `integration_settings` | PHP config file outside webroot | 0 | CUT |
| AI assistant and authoring helpers | `ai-chat` + 25 call sites | `POST /api/ai/complete` (Gemini only), staff only, no personal data in prompts | S | REBUILD |
| AI "reply to learner/email" | `AiReplyButton`, email AI reply | none (sends personal data to US processors) | 0 | CUT |
| AI full-course generator | `generate-course.ts` | keep only behind an explicit human-review state, remove auto "Overseen by" line and `cstf:true` default | M | REBUILD or CUT |
| Certificate issue, approve, verify | SQL RPCs 069/081/083/093/095/097 | PHP service + `GET /api/verify/{code}` public rate-limited; keep three states | M | REBUILD |
| Certificate PDF | client jsPDF | server PDF at approval time, stored privately; or keep jsPDF | M (S if keep) | REBUILD |
| Invoice / payslip PDF | client jsPDF | keep client-side; payslip rename or cut | S | KEEP / review |
| Workbooks (6 xlsx reports) | client ExcelJS | keep client-side initially (data via API); verify hard-coded sample content and 3-year expiry formula | S-M | KEEP |
| Turnstile | server-only, no widget | widget on contact (and optionally sign-in), siteverify in PHP | S | REBUILD |
| Weather widget | OpenWeather from browser with hard-coded key | remove | 0 | CUT |
| Public holidays | Nager.Date from browser | static JSON of UK bank holidays | S | REPLACE |
| Store / orders / coupons / invoices | SQL RPCs, no payment gateway | port RPCs to PHP transactions; no Stripe | L | REBUILD (only if store kept) |
| Course completion, assessment grading | SQL triggers/RPCs (`sync_course_completion`, `submit_assessment_attempt`) | PHP services with transactions; keep answer key server-side (067/068/077 lock it) | L | REBUILD |

CUT candidates (zero rebuild): `qr-login`, `user-mail`, `imap-sync` + `scripts/imap-pull.mjs` + `.github/workflows/imap-pull.yml`, `integrations`, `gcal-sync-event`, `drive-delete/list/upload`, both Google callbacks, `gmeet-create-event`, `zoom-create-meeting`, `zoom-sync-session`, `send-push`, `process-certificate-expiry` (wrapper), `process-profile-reminders`, `process-email-drip` (defer), OpenRouter fallback, OpenWeather widget, AI reply to learner/email.

Kept (rebuilt) back-end surface in PHP: auth middleware, learners bulk create, contact form, 1:1 email, broadcast email, notifications + 3 cron tasks, files API, AI proxy, verify endpoint, the SQL-logic services. That is roughly 10 endpoint groups instead of 23 functions.

---------------------------------------------------------------------------------------------------

## FINAL SUMMARY (15 lines)

1. 23 edge functions plus `_shared`: only about 9 are worth rebuilding in PHP (admin-create-learners, contact-form, one-to-one-email, send-email, ai-chat, and the cron logic of certificate-expiry, session reminders, reminders; plus a files API replacing the Drive trio). About 14 are CUT candidates.
2. Worst security smells: `user-mail` and `imap-sync` (plaintext mailbox passwords in `user_mail_accounts.smtp_pass`, user-supplied SMTP/IMAP host = SSRF, no role check so learners can call them); `scripts/imap-pull.mjs:119-139` puts private email attachments in the public `course-media` bucket.
3. `integrations` stores every provider secret (Drive client secret and refresh token, SMTP password, VAPID JWK, Turnstile secret, CRON_SECRET) in a plaintext DB table; several functions read env only, so the UI is partly cosmetic. Replace with a config file outside webroot.
4. `send-email` Resend path puts 50 recipients in one `To` (`send-email:118`), leaking learner addresses; it also accepts arbitrary `emails[]`, raw HTML and has no unsubscribe or audit log. Same HTML-injection pattern in `process-email-drip` and `user-mail`.
5. `drive-upload` makes every upload public-by-link, including session recordings; effective storage in production (Drive vs Supabase bucket) is UNKNOWN.
6. Public bucket `course-media` holds direct-message attachments, avatars and inbound mail attachments; INSERT policy is staff-only, so learner uploads probably fail (NOT TESTED). Only `files` is private (signed URLs 120 s, the only signed-URL use).
7. Cron: only two pg_cron jobs exist (`certificate-expiry-alerts` 0 8 * * *, `session-start-reminders` */15, migrations 033 and 089). `process-email-drip`, `process-reminders`, `process-profile-reminders` have no schedule anywhere in the repo (UNKNOWN whether they run). No multi-step "drip" exists; campaigns are single scheduled broadcasts. Expiry alerts are in-app only, though comments say "emailed".
8. Email reality: only 3 email types are sent (contact enquiry, 1:1 confirmation, staff broadcast/campaign) plus Supabase Auth mails; there is no welcome, certificate-issued, reminder, invoice or payslip email, and CLAUDE.md's listed `src/lib/email/*.ts` templates do not exist. Sender is `info@vitalcare.uk`; admin recipient defaults to a hard-coded personal Gmail.
9. Web push is partly wired and probably not delivering: the `pg_net` trigger (migration 010) calls `send-push` without an Authorization JWT, and `app_config.cron_secret` is never seeded by a migration. Turnstile is a stub (verifier only, no widget; fails open without secret).
10. AI: Gemini then OpenRouter via one proxy, used at about 25 call sites; two surfaces send personal data (learner chat transcript and first 4000 chars of received emails) to US processors; the brand system prompt embeds unverified claims (ICO registered, NHS trust names, 24-hour certificates, 7-year retention, free CV support); the course generator defaults `cstf: true` and appends "Overseen by Harni Muharami RN MSc" to AI output.
11. Realtime: 2 channels only (`notifications` INSERT, which is in the publication per migration 046 and already polls every 60 s; `messages`, which is not in any publication in the repo). Polling is straightforward.
12. PDFs and xlsx are all generated client-side (jsPDF, ExcelJS); nothing is rendered server-side. Certificate presets exist in the DB and in a spec doc but are not implemented; the payslip has no tax/NI fields; the certificate-log template hard-codes a 3-year expiry formula; the typed cursive "signature" is a rendering of the signatory's name.
13. Integrations in OpenWeather (hard-coded key in `weather.ts:5`), Nager.Date holidays, Google Fonts and Unsplash are browser-side third-party calls; recommend static replacements.
14. Heavy business logic lives in Postgres (about 70 functions, 25 triggers, RLS) and must be ported to PHP services; that, not the edge functions, is the bulk of the back-end rebuild effort.
15. Report path: /private/tmp/claude-501/-Users-realmrgee-Developer-Projects-Vitalcare-Training-Hub/2c75fd43-f095-4e4a-b1e3-57e5d60195c2/scratchpad/inv-backend.md
