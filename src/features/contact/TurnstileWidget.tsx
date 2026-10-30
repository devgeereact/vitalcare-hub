import { useEffect, useRef } from "react";

type TurnstileApi = {
  render: (
    el: HTMLElement,
    options: {
      sitekey: string;
      callback: (token: string) => void;
      "expired-callback": () => void;
      "error-callback": () => void;
    },
  ) => string;
  remove: (id: string) => void;
};

declare global {
  interface Window {
    turnstile?: TurnstileApi;
  }
}

const SCRIPT_URL = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
let loading: Promise<void> | null = null;

function loadScript(): Promise<void> {
  if (window.turnstile) return Promise.resolve();
  loading ??= new Promise<void>((resolve, reject) => {
    const el = document.createElement("script");
    el.src = SCRIPT_URL;
    el.async = true;
    el.onload = () => resolve();
    el.onerror = () => {
      loading = null;
      reject(new Error("Turnstile failed to load"));
    };
    document.head.appendChild(el);
  });
  return loading;
}

type Props = {
  siteKey: string;
  /** Called with a token when the check passes, and with null when it expires or fails. */
  onToken: (token: string | null) => void;
};

/** Cloudflare Turnstile. Loaded only on pages that show it, so no other page contacts Cloudflare. */
export function TurnstileWidget({ siteKey, onToken }: Props) {
  const host = useRef<HTMLDivElement>(null);

  // biome-ignore lint/correctness/useExhaustiveDependencies: render once per site key; onToken is read through the closure
  useEffect(() => {
    let id: string | null = null;
    let cancelled = false;
    loadScript()
      .then(() => {
        if (cancelled || !host.current || !window.turnstile) return;
        id = window.turnstile.render(host.current, {
          sitekey: siteKey,
          callback: (token) => onToken(token),
          "expired-callback": () => onToken(null),
          "error-callback": () => onToken(null),
        });
      })
      .catch((err) => {
        console.error("[turnstile] could not load", err);
        onToken(null);
      });
    return () => {
      cancelled = true;
      if (id && window.turnstile) window.turnstile.remove(id);
    };
  }, [siteKey]);

  return <div ref={host} />;
}
