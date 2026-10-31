import { API_BASE } from "@/shared/config";

/** Error thrown for any non-2xx API response, carrying the server's error code when present. */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    /** Per-field messages from a 422 validation response. */
    readonly fields?: Record<string, string>,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

/**
 * Join the API root and an endpoint path, refusing anything that could escape the API root
 * (absolute URLs, `//host`, `..`, query strings or fragments in the path).
 */
export function apiUrl(base: string, path: string): string {
  if (!/^(\/(?!\/)|https?:\/\/)/.test(base)) {
    throw new Error("API base must be a root-relative path or an http(s) URL");
  }
  // Segments are letters, digits, "_" and "-", with single dots allowed inside one (people.csv), never "..".
  const segment = "[A-Za-z0-9_-]+(?:\\.[A-Za-z0-9_-]+)*";
  if (!new RegExp(`^${segment}(?:/${segment})*$`).test(path)) {
    throw new Error("API endpoint must be a relative path of simple segments");
  }
  return `${base.replace(/\/+$/, "")}/${path}`;
}

/** Query string from plain values; empty and undefined values are left out. */
export function queryString(query?: Record<string, string | number | undefined>): string {
  if (!query) return "";
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(query)) {
    if (v !== undefined && v !== "") params.set(k, String(v));
  }
  const s = params.toString();
  return s ? `?${s}` : "";
}

type RequestOptions = {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  /** Session token from the auth provider (FULL tier). */
  token?: string;
  /** Pass the AbortController signal from your effect so stale requests are cancelled. */
  signal?: AbortSignal;
  /** Query string values (encoded here, never concatenated by callers). */
  query?: Record<string, string | number | undefined>;
};

async function failure(response: Response): Promise<ApiError> {
  const data: unknown = await response.json().catch(() => null);
  const error = (
    data as {
      error?: { code?: string; message?: string; fields?: Record<string, string> };
    } | null
  )?.error;
  return new ApiError(
    response.status,
    error?.code ?? "HTTP_ERROR",
    error?.message ?? `Request failed (${response.status})`,
    error?.fields,
  );
}

/** Typed JSON request to the project API. Validate the returned shape at the call site. */
export async function apiFetch<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: "application/json" };
  if (options.body !== undefined) headers["Content-Type"] = "application/json";
  if (options.token) headers.Authorization = `Bearer ${options.token}`;

  const response = await fetch(apiUrl(API_BASE, path) + queryString(options.query), {
    method: options.method ?? "GET",
    headers,
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
    signal: options.signal,
    credentials: "same-origin",
  });

  if (!response.ok) throw await failure(response);
  const data: unknown = await response.json().catch(() => null);
  return data as T;
}

/** A file download (PDF, CSV) with the bearer token. Returns the bytes and the server's file name. */
export async function apiDownload(
  path: string,
  options: { token: string; query?: Record<string, string | undefined> },
): Promise<{ blob: Blob; filename: string }> {
  const response = await fetch(apiUrl(API_BASE, path) + queryString(options.query), {
    headers: { Authorization: `Bearer ${options.token}` },
    credentials: "same-origin",
  });
  if (!response.ok) throw await failure(response);
  const disposition = response.headers.get("Content-Disposition") ?? "";
  const match = /filename="([A-Za-z0-9._-]+)"/.exec(disposition);
  return { blob: await response.blob(), filename: match?.[1] ?? "download" };
}
