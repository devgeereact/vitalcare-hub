import { useInstallPrompt } from "../hooks/useInstallPrompt";

/** Only renders where the browser supports an install prompt (mainly Chromium). */
export function InstallButton() {
  const { canInstall, install } = useInstallPrompt();
  if (!canInstall) return null;

  return (
    <button type="button" onClick={() => void install()}>
      Install app
    </button>
  );
}
