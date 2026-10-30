import { useCallback, useEffect, useState } from "react";
import { useSession } from "@/features/auth/session";
import { ApiError } from "@/shared/services/api";
import { fetchMe, type Me } from "./me";

export type MeState =
  | { kind: "loading" }
  | { kind: "ok"; me: Me }
  | { kind: "no-access" }
  | { kind: "error"; retry: () => void };

/** Loads the signed-in person from the API. 403 no_access is its own state, never an error screen. */
export function useMe(): MeState {
  const { status, getToken } = useSession();
  const [state, setState] = useState<MeState>({ kind: "loading" });
  const [attempt, setAttempt] = useState(0);
  const retry = useCallback(() => {
    setState({ kind: "loading" });
    setAttempt((n) => n + 1);
  }, []);

  // biome-ignore lint/correctness/useExhaustiveDependencies: `attempt` re-runs the load on retry
  useEffect(() => {
    if (status !== "signed-in") return;
    const controller = new AbortController();
    (async () => {
      try {
        const token = await getToken();
        if (!token) return setState({ kind: "error", retry });
        const me = await fetchMe(token, controller.signal);
        setState({ kind: "ok", me });
      } catch (err) {
        if (controller.signal.aborted) return;
        if (err instanceof ApiError && err.status === 403 && err.code === "no_access") {
          return setState({ kind: "no-access" });
        }
        console.error("[useMe] failed", err);
        setState({ kind: "error", retry });
      }
    })();
    return () => controller.abort();
  }, [status, getToken, attempt, retry]);

  return state;
}
