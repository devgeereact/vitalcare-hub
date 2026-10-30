| Path | Component (LOC) | Guard | Class | Evidence |
|---|---|---|---|---|
| `/sign-in` | `auth/cover/CoverLoginPage.tsx` (158) | public (inside AuthLayout) | KEEP | canonical Supabase sign-in (CoverLoginPage, 158 lines) |
| `/sign-up` | `auth/cover/CoverRegisterPage.tsx` (233) | public (inside AuthLayout) | KEEP | canonical registration (CoverRegisterPage, 233 lines) |
| `/forgot-password` | `auth/cover/CoverForgotPasswordPage.tsx` (116) | public (inside AuthLayout) | KEEP | canonical reset request |
| `/reset-password` | `auth/cover/CoverResetPasswordPage.tsx` (277) | public (inside AuthLayout) | KEEP | canonical set-new-password (277 lines, handles expired link) |
| `/auth/callback` | `pages/auth/callback.tsx` (95) | public (inside AuthLayout) | KEEP | OAuth/magic-link callback (95 lines) |
| `/auth/basic/login` | `auth/basic/LoginPage.tsx` (21) | public (inside AuthLayout) | CUT | template page: "Acme Inc.", LoginForm has no onSubmit and no supabase call, Apple/Google buttons do nothing |
| `/auth/basic/register` | `auth/basic/RegisterPage.tsx` (19) | public (inside AuthLayout) | CUT | template page: "Acme Inc.", SignupForm has no handler / no supabase |
| `/auth/basic/forgot-password` | `auth/basic/ForgotPasswordPage.tsx` (77) | public (inside AuthLayout) | CUT | template page, no auth call, "Acme Inc." |
| `/auth/basic/reset-password` | `auth/basic/ResetPasswordForm.tsx` (127) | public (inside AuthLayout) | CUT | template page, no auth call |
| `/auth/basic/verify-email` | `auth/basic/VerifyEmailForm.tsx` (87) | public (inside AuthLayout) | CUT | template page, no auth call |
| `/auth/basic/password-reset-success` | `auth/basic/PasswordResetSuccess.tsx` (70) | public (inside AuthLayout) | CUT | template page, no auth call |
| `/auth/cover/login` | `auth/cover/CoverLoginPage.tsx` (158) | public (inside AuthLayout) | CUT | duplicate mount of the canonical /sign-in component |
| `/auth/cover/register` | `auth/cover/CoverRegisterPage.tsx` (233) | public (inside AuthLayout) | CUT | duplicate mount of /sign-up |
| `/auth/cover/forgot-password` | `auth/cover/CoverForgotPasswordPage.tsx` (116) | public (inside AuthLayout) | CUT | duplicate mount of /forgot-password |
| `/auth/cover/new-password` | `auth/cover/CoverResetPasswordPage.tsx` (277) | public (inside AuthLayout) | CUT | duplicate mount of /reset-password |
| `/auth/cover/password-reset-success` | `auth/cover/CoverPasswordResetSuccessPage.tsx` (94) | public (inside AuthLayout) | CUT | template page, "Acme Inc." (lines 26, 89), no auth call, nothing links to it |
| `/auth/cover/verify-email` | `auth/cover/CoverVerifyEmailPage.tsx` (113) | public (inside AuthLayout) | CUT | template page, "Acme Inc." (lines 26, 108), no auth call, nothing links to it |
| `/error/error-404` | `pages/error/Error404.tsx` (82) | public (inside AuthLayout) | CUT | template demo error page, no inbound link (real 404 is pages/NotFound.tsx) |
| `/error/error-500` | `pages/error/Error500.tsx` (101) | public (inside AuthLayout) | CUT | template demo error page, no inbound link (real handler is pages/ErrorPage.tsx) |
| `/error/coming-soon` | `pages/error/ComingSoon.tsx` (139) | public (inside AuthLayout) | CUT | template demo "coming soon" page, no inbound link |