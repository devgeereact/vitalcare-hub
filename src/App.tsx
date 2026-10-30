import { InstallButton } from "./components/InstallButton";
import { UpdatePrompt } from "./components/UpdatePrompt";
import { APP_DESCRIPTION, APP_NAME } from "./config";
import { useOnlineStatus } from "./hooks/useOnlineStatus";

export function App() {
  const online = useOnlineStatus();

  return (
    <main>
      <p className="eyebrow">Project starter · not a finished product</p>
      <h1>{APP_NAME}</h1>
      <p>{APP_DESCRIPTION}</p>

      {!online && (
        <p role="status">You are offline. The app shell works; data may be out of date.</p>
      )}
      <UpdatePrompt />
      <InstallButton />

      <section>
        <h2>Your first vertical slice</h2>
        <p>
          Follow <code>docs/ROADMAP.md</code> and <code>.agent/CURRENT-TASK.md</code>. No fake
          accounts, records or live data are included.
        </p>
      </section>
    </main>
  );
}
