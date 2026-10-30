import "@fontsource/dm-sans/latin-400.css";
import "@fontsource/dm-sans/latin-500.css";
import "@fontsource/dm-sans/latin-600.css";
import "@fontsource/dm-serif-display/latin-400.css";
import { ClerkProvider } from "@clerk/react";
import { type ReactNode, StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import { App } from "./App";
import { ClerkSession } from "./auth/ClerkSession";
import { SessionContext } from "./auth/session";
import { ErrorBoundary } from "./components/ErrorBoundary";
import { CLERK_PUBLISHABLE_KEY } from "./config";
import "./style.css";

const root = document.getElementById("root");
if (!root) throw new Error("Missing #root element in index.html");

// Without a publishable key the app still loads and says sign-in is not available.
const unconfigured = {
  status: "unconfigured" as const,
  getToken: async () => null,
  signOut: async () => {},
};

function Providers({ children }: { children: ReactNode }) {
  if (!CLERK_PUBLISHABLE_KEY) {
    return <SessionContext.Provider value={unconfigured}>{children}</SessionContext.Provider>;
  }
  return (
    <ClerkProvider publishableKey={CLERK_PUBLISHABLE_KEY} afterSignOutUrl="/">
      <ClerkSession>{children}</ClerkSession>
    </ClerkProvider>
  );
}

createRoot(root).render(
  <StrictMode>
    <ErrorBoundary>
      <BrowserRouter>
        <Providers>
          <App />
        </Providers>
      </BrowserRouter>
    </ErrorBoundary>
  </StrictMode>,
);
