import { useAuth } from "@clerk/react";
import { type ReactNode, useMemo } from "react";
import { type Session, SessionContext } from "./session";

/** Adapts Clerk's auth state to the app's own Session shape, so the rest of the app never imports Clerk. */
export function ClerkSession({ children }: { children: ReactNode }) {
  const { isLoaded, isSignedIn, getToken, signOut } = useAuth();
  const session = useMemo<Session>(
    () => ({
      status: !isLoaded ? "loading" : isSignedIn ? "signed-in" : "signed-out",
      getToken: () => getToken(),
      signOut: () => signOut(),
    }),
    [isLoaded, isSignedIn, getToken, signOut],
  );
  return <SessionContext.Provider value={session}>{children}</SessionContext.Provider>;
}
