import { useRegisterSW } from "virtual:pwa-register/react";

/** Shows a prompt when a new version is deployed. Never reloads without the user's consent. */
export function UpdatePrompt() {
  const {
    needRefresh: [needRefresh, setNeedRefresh],
    updateServiceWorker,
  } = useRegisterSW();

  if (!needRefresh) return null;

  return (
    <section role="status">
      <p>A new version is available. Save your work, then reload.</p>
      <button type="button" onClick={() => void updateServiceWorker(true)}>
        Reload now
      </button>
      <button type="button" onClick={() => setNeedRefresh(false)}>
        Later
      </button>
    </section>
  );
}
