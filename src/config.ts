// Public app settings. Everything here ends up in the browser bundle — never put secrets here.

export const APP_NAME: string = "Vitalcare Training Hub";
export const APP_DESCRIPTION: string =
  "Care-sector training, certificates anyone can verify, and the staff compliance register behind them. Vitalcare Training Hub Ltd.";

/** Root of the PHP API. Same-origin `/api` by default. */
export const API_BASE: string = import.meta.env.VITE_API_BASE_URL || "/api";
