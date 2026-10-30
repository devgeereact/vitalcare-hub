# Phase 2 — Rebrand: directions, tokens, type, logo brief, voice

Status: PLANNED. Nothing is rewritten yet. Owner answers recorded 30 Oct 2026.

## 0. What "rebrand" means here

You kept the name, the logos and the navy and gold. So the rebrand removes the **old claims, the template layer (Acme Inc., pulse-ui-react, Codervent, Supabase wording, template images) and the old visual system's faults**, and adds the legal entity line. The name and logo files stay. The old-brand audit (`audit-rebrand.py`) will therefore look for template and claim traces, not for "Vitalcare".

| | Old | New |
|---|---|---|
| Public name | Vitalcare Training Hub | Vitalcare Training Hub (unchanged) |
| Legal entity line | partial, inconsistent | **Vitalcare Training Hub Ltd, company number 15718997, registered in England and Wales, 11 Halesworth Road, London SE13 7TJ** on every footer, certificate, invoice, email footer and legal page. ASSUMPTION: "Ltd" as in your brief and the source, not "Limited"; confirm against Companies House wording |
| Logos | four SVGs | the same four files, byte-identical, never edited |
| Colour base | navy `#1b2e6b`, gold `#d4a843` | unchanged base; text and focus colours corrected for contrast (below) |
| Tone | mixed, promotional | **A: calm and clinical** |
| Domain | vitalcare.uk | vitalcare.uk |
| cPanel location | `~/vitalcare.uk` | same docroot; private code outside it (see §7) |

## 1. Three directions (all navy and gold, all tone A)

| | **1. Clinical Ledger** (recommended) | 2. Ward Light | 3. Certificate Paper |
|---|---|---|---|
| Idea | Restrained and exact, like a well-kept register. White and cool grey surfaces, navy type, gold only as a hairline rule, a seal or a fill. | Softer and friendlier: pale navy-tint surfaces, rounder components, sans-serif throughout. | A document feel: warm ivory paper, serif headings, a large gold seal, ruled panels. |
| Fits tone A | Best: it looks like evidence | Good, but drifts toward "friendly app" | Good, but warm paper reads ceremonial rather than clinical |
| Suits the proof pages (verify, certificate) | Strongest: the verify result looks like a record | Adequate | Strongest for print, weak for dense tables |
| Dense staff tables (compliance matrix, registers) | Strong: high contrast, low decoration | Fair | Weak: paper tone lowers table contrast |
| Cost | Lowest: fonts and palette already in the source | Medium: new tints and radii | Medium: new paper surface and print styles |
| Risk | Can feel plain; mitigated with spacing and one strong headline per page | Looks like generic SaaS | Ivory background fails to match the white logo files |

**Recommendation: Clinical Ledger.** The product's one differentiator is proof: a certificate anyone can verify and a register an employer can trust. Restraint signals that, it keeps the supplied logos on clean white or navy without clashing, and it needs the least new design work.

## 2. Token set (Clinical Ledger)

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

## 3. Type

- Headings (H1, H2): **DM Serif Display**, weight 400. H3 to H6, labels and UI: **DM Sans** 500, 600, 700. Body: DM Sans 400, 16px, line height 1.6, measure capped at 65ch. Tabular figures for registers, dates and invoices.
- **Self-host the font files** (woff2, subset to Latin) instead of loading Google Fonts. That removes a third-party request that the privacy notice would otherwise have to name, and works offline in the PWA. Licence: both are SIL Open Font Licence; include the licence text.
- Scale: 14 / 16 / 18 / 22 / 28 / 36 / 48. No Inter.

## 4. Logo brief (logos already exist; nothing is redrawn)

| File | Use | Never |
|---|---|---|
| `logo-horizontal-navy.svg` | site header and footer on white, invoices, PDFs, email header | recolour, outline, add effects, stretch |
| `logo-horizontal-white.svg` | sign-in panel and any navy surface | place on gold or mid-tones |
| `logo-round-navy.svg` | favicon, PWA icon source, certificate seal position, social image | shrink below 24 px |
| `logo-round-white.svg` | collapsed sidebar, dark surfaces | |

Clear space equals the height of the book glyph on all sides. Minimum width: horizontal 120 px, round 24 px (below 48 px use the round mark without relying on its ring text). Alt text: "Vitalcare Training Hub". The files are single traced paths of about 50 KB; they are used as supplied. Optimising them (SVGO, which does not change the drawing) is optional and needs your yes. PWA icons are rasterised from `logo-round-navy.svg` at 192 and 512 px, plus a maskable variant with safe padding, without altering the artwork. These are derived files, not edits.

## 5. Voice (tone A: calm and clinical)

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

## 6. Rewrite scope (once the destination exists)

All 18 public pages, page titles and meta descriptions, Open Graph tags, `manifest.webmanifest`, `robots.txt`, `sitemap.xml`, JSON-LD (Organization only; no Review or AggregateRating), the 404 page, every email template, the certificate and invoice PDFs and workbook headings, platform labels, and the demo-free seed courses. Template pages, the Acme auth pages and all CSS and images from the template are not carried over.

## 7. Name and location: one conflict to resolve

You asked for the same repo name and the same cPanel location.
- **cPanel location: yes, same.** Domain `vitalcare.uk`, docroot `~/vitalcare.uk`, merged into the live `.htaccess` (origin lock kept), upload with `--exclude '/.htaccess'`. The PHP API entry point lives at `~/vitalcare.uk/api/index.php` only; all other PHP, config, the new database credentials, uploads and backups live outside the web root in `~/vitalcare-private/` and `~/vitalcare-backups/`.
- **Repo and folder name: cannot be identical today.** Verified: this Mac's filesystem is case-insensitive (the lowercase path `~/Developer/Projects/vitalcare-training-hub` resolves to the source folder), and the old GitHub repo `devgeereact/Vitalcare-Training-Hub` exists and is public. Two repos cannot share a name under one owner. The rules also keep the old repo untouched until a separate approved retirement.
- **Recommendation:** work as `~/Developer/Projects/vitalcare-hub` and a private repo `devgeereact/vitalcare-hub` (ports 5175 and 3175). At cutover, in the release plan and with your approval, rename the old repo to `Vitalcare-Training-Hub-legacy` and, if you still want it, rename the new one to `Vitalcare-Training-Hub`. You end with the same name, and the old repo is never touched before then.
