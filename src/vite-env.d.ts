/// <reference types="vite/client" />
/// <reference types="vite-plugin-pwa/react" />

interface ImportMetaEnv {
  /** Public. Root of the API, e.g. "/api". */
  readonly VITE_API_BASE_URL?: string;
  /** Public by design (Clerk publishable key). Never a secret key. */
  readonly VITE_CLERK_PUBLISHABLE_KEY?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
