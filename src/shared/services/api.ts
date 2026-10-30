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
  if (!/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/.test(path)) {
    throw new Error("API endpoint must be a relative path of simple segments");
  }
  return `${base.replace(/\/+$/, "")}/${path}`;
}

type RequestOptions = {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  /** Session token from the auth provider (FULL tier). */
  token?: string;
  /** Pass the AbortController signal from your effect so stale requests are cancelled. */
  signal?: AbortSignal;
};

/** Typed JSON request to the project API. Validate the returned shape at the call site. */
export async function apiFetch<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: "application/json" };
  if (options.body !== undefined) headers["Content-Type"] = "application/json";
  if (options.token) headers.Authorization = `Bearer ${options.token}`;

  const response = await fetch(apiUrl(API_BASE, path), {
    method: options.method ?? "GET",
    headers,
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
    signal: options.signal,
    credentials: "same-origin",
  });

  const data: unknown = await response.json().catch(() => null);
  if (!response.ok) {
    const error = (
      data as {
        error?: { code?: string; message?: string; fields?: Record<string, string> };
      } | null
    )?.error;
    throw new ApiError(
      response.status,
      error?.code ?? "HTTP_ERROR",
      error?.message ?? `Request failed (${response.status})`,
      error?.fields,
    );
  }
  return data as T;
}
