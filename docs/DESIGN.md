# Design: Vitalcare Training Hub

## Direction

**Clinical Ledger** (approved 30 Oct 2026): restrained and exact, like a well-kept register. White and cool grey surfaces, navy type, gold only as a rule, seal or fill. Tone A: calm and clinical. Navy and gold are kept; the four supplied logo files are used unchanged. Avoid: promotional adjectives, stock-photo heroes, gold text on white, colour as the only status cue, urgency tricks, the banned words below.

## Tokens (mirror in `src/style.css`)

```css
:root {
  /* Brand */
  --navy: #1b2e6b;        --navy-dark: #142054;     --navy-tint: #e8ecf7;
  --gold: #d4a843;        --gold-light: #e8c26a;    --gold-ink: #856408;   /* gold-ink is the only gold allowed as text */

  /* Surfaces and text */
  --paper: #ffffff;       --surface: #f8fafc;       --line: #e2e8f0;
  --ink: #0f172a;         --ink-2: #475569;         --ink-3: #64748b;      /* ink-3: large or secondary text only */
  --field-border: #8391a7;                                                    /* form controls, 3:1 */

  /* Status (text on its own tint) */
  --success-ink: #166534; --success-bg: #dcfce7;
  --warning-ink: #92400e; --warning-bg: #fef3c7;
  --danger-ink:  #b91c1c; --danger-bg:  #fee2e2;

  /* Focus */
  --focus: #1b2e6b;       /* 2px ring + 2px white offset; gold ring is retired */
}
```

Measured contrast (WCAG formula, computed with a script on 30 Oct 2026):

| Pair | Ratio | Use |
|---|---|---|
| navy on white | 12.73 | headings, links, buttons |
| white on navy | 12.73 | primary button, header |
| gold on navy | 5.75 | gold labels on navy surfaces |
| navy on gold | 5.75 | gold button with navy text |
| gold-ink on white / on surface | 5.49 / 5.25 | any gold text on light |
| **gold on white** | **2.21 FAIL** | never as text; fills, rules and icons only |
| ink on surface | 17.06 | body |
| ink-2 on white | 7.58 | secondary text |
| ink-3 on white | 4.76 | passes, but keep to 14px bold or larger for secondary |
| success / warning / danger ink on own tint | 6.49 / 6.37 / 5.30 | badges, alerts |
| field border `#8391a7` on white | 3.19 | form control edges (needs 3:1) |
| line `#e2e8f0` on white | 1.23 | decorative dividers only, never the only cue |
| focus navy on white | 12.73 | focus ring. The old gold ring is 2.21 and fails the 3:1 non-text rule |

Rules: gold is a fill, rule or icon colour, never text on white. A status is never colour alone (icon plus word). Motion is transform and opacity only, with `prefers-reduced-motion` honoured. WCAG 2.2 AA stays a target until tested.

## Type

- Headings (H1, H2): **DM Serif Display**, weight 400. H3 to H6, labels and UI: **DM Sans** 500, 600, 700. Body: DM Sans 400, 16px, line height 1.6, measure capped at 65ch. Tabular figures for registers, dates and invoices.
- **Self-host the font files** (woff2, subset to Latin) instead of loading Google Fonts. That removes a third-party request that the privacy notice would otherwise have to name, and works offline in the PWA. Licence: both are SIL Open Font Licence; include the licence text.
- Scale: 14 / 16 / 18 / 22 / 28 / 36 / 48. No Inter.

## Logos (supplied files used as is)

| File | Use | Never |
|---|---|---|
| `logo-horizontal-navy.svg` | site header and footer on white, invoices, PDFs, email header | recolour, outline, add effects, stretch |
| `logo-horizontal-white.svg` | sign-in panel and any navy surface | place on gold or mid-tones |
| `logo-round-navy.svg` | favicon, PWA icon source, certificate seal position, social image | shrink below 24 px |
| `logo-round-white.svg` | collapsed sidebar, dark surfaces | |

Clear space equals the height of the book glyph on all sides. Minimum width: horizontal 120 px, round 24 px (below 48 px use the round mark without relying on its ring text). Alt text: "Vitalcare Training Hub". The files are single traced paths of about 50 KB; they are used as supplied. Optimising them (SVGO, which does not change the drawing) is optional and needs your yes. PWA icons are rasterised from `logo-round-navy.svg` at 192 and 512 px, plus a maskable variant with safe padding, without altering the artwork. These are derived files, not edits.

## Voice (tone A: calm and clinical)

**Rules.** Short sentences. Facts, dates and numbers before adjectives. Say what happens, who does it and by when, only where that is true. No exclamation marks, no superlatives, no urgency tricks. UK English. No em-dashes (commas, colons, brackets, or restructure). Address learners as "you", employers as "your team" or "your staff". Name the legal entity in footers. A claim appears only if its register entry is `PROVEN`.

**Banned (unchanged):** delve, tapestry, testament, showcase (verb), pivotal, synergy, seamless, leverage (verb), holistic, game-changer, transformative, innovative and unique (unsupported), comprehensive (as filler), bespoke, streamline, facilitate, empower, world-class. Hollow healthcare phrases ("passionate about patient care", "going the extra mile", "making a difference", "dedicated team of professionals") stay banned. **Added:** "state-of-the-art", "cutting-edge", "best-in-class", "trusted by", "leading provider", "fully compliant", "guaranteed".

**Claim register.** `docs/claims.yml` in the new repo: one entry per claim with `status: REQUIRED | PROVEN`, the evidence reference, and the owner who supplied it. Page copy refers to claims by key. A build check fails if a page uses a claim that is not `PROVEN`. Today every accreditation, alignment, credential and service-level entry is `REQUIRED`, so the site ships with none of them.

**Per-page voice** (draft lines are illustrative and use only provable statements):

| Page | Job | Voice and draft line |
|---|---|---|
| Home | say what it is, route to a course or to verify | "Care training with a record you can check." Two paths: individuals, employers. No stats, no testimonials until real. |
| About | the company as a fact sheet | Legal entity, founding date (14 May 2024, Companies House), address, who runs training once Harni consents and proof exists. Otherwise "Training is led by our clinical director" is NOT used; REQUIRED. |
| Courses and course detail | exact, dull, useful | Title, audience, duration, format (online or in person), assessment, certificate, renewal period, price. Fields appear only when approved; nothing is estimated. |
| Training for employers (sector pages) | help a manager decide | What staff need, how to book a group, how invoices work, how to check certificates. No sector outcome promises. |
| Verify | the proof page | Plain result states: "Valid", "Expired on 3 March 2027", "Not issued". No personal data for "Not issued". |
| Standards (replaces Accreditations) | state only what is proven | Until proof exists the page says what the certificate records (course, date, hours, assessment) and nothing about external bodies. |
| Blog and events | useful notes, dated and signed | Only written by named people; no AI-written clinical advice. |
| Contact | make contact easy | Form, email, phone 020 8059 8757, Monday to Friday 9am to 5pm. No reply-time promise. |
| FAQ, Privacy, Refund, Cookies, Terms | exact and readable | Plain-English summary above each legal text. Legal text drafted from observed data flows, then marked for solicitor review; no "compliant" label. Refund page follows the Consumer Contracts rules only after advice. |
| Sign-in | one step | "Enter your email. We will send you a six-digit code." No password language. |
| Platform empty and error states | next step, no blame | "No sessions booked yet. Book a session to start." "We could not load this. Try again." |
| Emails | short, signed, one action | Subject states the fact; body under 100 words; footer carries the legal entity line and contact details. New: welcome, enquiry received (to sender), enquiry (to office), booking confirmation, certificate issued, expiry reminder at 60 and 14 days, invoice sent. |
| Certificate and PDFs | the record | Learner name, course, date, hours, certificate ID, verify URL and QR, issuing company line. The "Overseen by Harni Muharami RN MSc, Clinical Director" line is REQUIRED until her consent and credentials are proven; the layout keeps a reserved slot. |

## Screens

| Screen | Purpose | Primary action | States covered |
|---|---|---|---|
| Home, courses, course detail, standards, contact, legal | tell the facts | find a course, verify, enquire | loading, empty, error, offline shell |
| Verify | prove a certificate | enter or scan a code | valid, expired, not issued, rate limited, error |
| Sign-in | one step | enter email, then six-digit code | wrong code, expired code, no access, offline |
| Learner, trainer, employer, admin areas | per slice | per slice | loading, empty, error, success, permission denied, offline where relevant |

## Accessibility

Target WCAG 2.2 AA, not claimed until tested. Measured contrast is in the token table. Checks to run and record in `docs/VERIFICATION.md`: keyboard path and focus order on every screen, 375 px layout, reduced motion, axe on public pages and the three journeys, screen-reader pass on sign-in and verify.
