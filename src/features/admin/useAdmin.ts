import { useCallback, useEffect, useState } from "react";
import { useSession } from "@/features/auth/session";
import { ApiError, apiDownload, apiFetch } from "@/shared/services/api";

export type Load<T> =
  | { kind: "loading" }
  | { kind: "ok"; data: T }
  | { kind: "error"; status: number; message: string };

type Query = Record<string, string | number | undefined>;

/**
 * Loads one CMS resource with a fresh session token. `parse` validates the response at the boundary.
 * Returns the state and a reload function. A null path loads nothing.
 */
export function useAdminGet<T>(
  path: string | null,
  parse: (data: unknown) => T,
  query?: Query,
): [Load<T>, () => void] {
  const { getToken } = useSession();
  const [state, setState] = useState<Load<T>>({ kind: "loading" });
  const [attempt, setAttempt] = useState(0);
  const key = JSON.stringify(query ?? {});
  const reload = useCallback(() => setAttempt((n) => n + 1), []);

  // biome-ignore lint/correctness/useExhaustiveDependencies: `key` stands for `query`; `attempt` re-runs on reload; parse is stable per page
  useEffect(() => {
    if (!path) return;
    const controller = new AbortController();
    (async () => {
      try {
        const token = await getToken();
        if (!token) throw new ApiError(401, "signed_out", "Sign in again.");
        const data = await apiFetch<unknown>(path, {
          token,
          signal: controller.signal,
          query: JSON.parse(key) as Query,
        });
        if (!controller.signal.aborted) setState({ kind: "ok", data: parse(data) });
      } catch (err) {
        if (controller.signal.aborted) return;
        if (err instanceof ApiError) {
          setState({ kind: "error", status: err.status, message: err.message });
        } else {
          console.error("[admin] load failed", err);
          setState({ kind: "error", status: 0, message: "We could not load this. Try again." });
        }
      }
    })();
    return () => controller.abort();
  }, [path, key, attempt, getToken]);

  return [state, reload];
}

/** Sends one change. Throws ApiError (with field messages on a 422) for the form to show. */
export function useAdminSend() {
  const { getToken } = useSession();
  return useCallback(
    async <T = unknown>(method: "POST" | "PATCH", path: string, body: unknown): Promise<T> => {
      const token = await getToken();
      if (!token) throw new ApiError(401, "signed_out", "Sign in again.");
      return apiFetch<T>(path, { method, body, token });
    },
    [getToken],
  );
}

/** Downloads a file (PDF or CSV) and hands it to the browser as a saved file. */
export function useAdminDownload() {
  const { getToken } = useSession();
  return useCallback(
    async (path: string) => {
      const token = await getToken();
      if (!token) throw new ApiError(401, "signed_out", "Sign in again.");
      const { blob, filename } = await apiDownload(path, { token });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = filename;
      document.body.append(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    },
    [getToken],
  );
}
