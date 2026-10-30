| Path | Component (LOC) | Guard / roles | Class | Evidence |
|---|---|---|---|---|
| `/` | `pages/marketing/HomePage.tsx` (597) | public | KEEP | eager first paint; curated Unsplash hotlinks from src/data/marketing-images.ts |
| `/about-us` | `marketing/AboutUsPage.tsx` (469) | public | KEEP | static copy page (469 lines), no DB; copy must be re-run through the voice rules |
| `/our-courses` | `marketing/OurCoursesPage.tsx` (329) | public | KEEP | reads `courses` via public-courses.queries; but categories also come from static src/data/courses.ts (two sources of truth) |
| `/our-courses/course/:slug` | `marketing/CourseDetailPage.tsx` (516) | public | KEEP | public course detail from courses table (516 lines) |
| `/our-courses/:categorySlug` | `marketing/CategoryPage.tsx` (230) | public | KEEP | category listing; mixes static COURSE_CATEGORIES with DB courses |
| `/training-solutions/:sector` | `marketing/TrainingSolutionPage.tsx` (274) | public | KEEP | 5 sectors from static src/data/sectors.ts, no DB |
| `/resources/verify-certificate` | `marketing/VerifyCertPage.tsx` (300) | public | KEEP | public rpc verify_certificate; covered by security + e2e tests; printed on every certificate |
| `/verify` | (Navigate to /resources/verify-certificate) | public | KEEP | redirect; every certificate prints vitalcare.uk/verify (src/lib/constants.ts:51) |
| `/resources/accreditations` | `marketing/AccreditationsPage.tsx` (356) | public | KEEP | static trust/accreditation page (356 lines) |
| `/resources/blog` | `marketing/BlogPage.tsx` (179) | public | KEEP | blog_posts + rpc increment_blog_views; src/data/blog.ts is an unused-seed fallback |
| `/resources/blog/:slug` | `marketing/BlogPostPage.tsx` (243) | public | KEEP | blog post detail, same queries |
| `/resources/events` | `marketing/EventsPage.tsx` (197) | public | KEEP | upcoming public training_sessions |
| `/contact-us` | `marketing/ContactPage.tsx` (334) | public | KEEP | posts to edge function contact-form |
| `/faq` | `marketing/legal/FAQPage.tsx` (122) | public | KEEP | static legal copy via LegalPage |
| `/privacy-policy` | `marketing/legal/PrivacyPolicyPage.tsx` (183) | public | KEEP | static legal copy; backed by docs/PRIVACY-DATA-MAP.md |
| `/refund-policy` | `marketing/legal/RefundPolicyPage.tsx` (62) | public | KEEP | static legal copy |
| `/cookie-policy` | `marketing/legal/CookiePolicyPage.tsx` (81) | public | KEEP | static legal copy |
| `/terms-and-conditions` | `marketing/legal/TermsPage.tsx` (95) | public | KEEP | static legal copy |