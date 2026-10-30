import { useRegisterSW } from "virtual:pwa-register/react";

/** Shows a prompt when a new version is deployed. Never reloads without the user's consent. */
export function UpdatePrompt() {
  const {
    needRefresh: [needRefresh, setNeedRefresh],
    updateServiceWorker,
  } = useRegisterSW();

  if (!needRefresh) return null;

  return (
    <div className="page-inner">
      <div className="notice notice--info" role="status">
        <strong>A new version is available</strong>
        Save your work, then reload.
        <div className="actions">
          <button className="btn" type="button" onClick={() => void updateServiceWorker(true)}>
            Reload now
          </button>
          <button className="btn btn--quiet" type="button" onClick={() => setNeedRefresh(false)}>
            Later
          </button>
        </div>
      </div>
    </div>
  );
}
