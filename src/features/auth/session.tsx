import { createContext, useContext } from "react";

export type SessionStatus = "unconfigured" | "loading" | "signed-out" | "signed-in";

export type Session = {
  status: SessionStatus;
  /** A fresh session token for the API, or null when signed out. */
  getToken: () => Promise<string | null>;
  signOut: () => Promise<void>;
};

const signedOut: Session = {
  status: "signed-out",
  getToken: async () => null,
  signOut: async () => {},
};

export const SessionContext = createContext<Session>(signedOut);

export function useSession(): Session {
  return useContext(SessionContext);
}
