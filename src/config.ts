// Public app settings. Everything here ends up in the browser bundle, so never put secrets here.

export const APP_NAME: string = "Vitalcare Training Hub";
export const APP_DESCRIPTION: string =
  "Care-sector training, certificates anyone can verify, and the staff compliance register behind them.";

/** Root of the PHP API. Same-origin `/api` by default. */
export const API_BASE: string = import.meta.env.VITE_API_BASE_URL || "/api";

/** Clerk publishable key. Public by design (a pk_ value); never the secret key. */
export const CLERK_PUBLISHABLE_KEY: string = import.meta.env.VITE_CLERK_PUBLISHABLE_KEY || "";
