/// <reference types="vite/client" />
/// <reference types="vite-plugin-pwa/react" />

interface ImportMetaEnv {
  readonly VITE_API_BASE_URL?: string;
  /** Public Clerk publishable key (pk_...). */
  readonly VITE_CLERK_PUBLISHABLE_KEY?: string;
  /** Public Cloudflare Turnstile site key. */
  readonly VITE_TURNSTILE_SITE_KEY?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
