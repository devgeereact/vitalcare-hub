import { apiFetch } from "./api";

export const ROLES = ["super_admin", "admin", "manager", "trainer", "learner"] as const;
export type Role = (typeof ROLES)[number];

export type Me = {
  id: string;
  email: string;
  displayName: string;
  role: Role;
  organisationId: string | null;
};

const isRole = (v: unknown): v is Role =>
  typeof v === "string" && (ROLES as readonly string[]).includes(v);

/** Validates the API response at the boundary; anything unexpected is an error, never a guess. */
export function parseMe(data: unknown): Me {
  const u = (data as { user?: Record<string, unknown> } | null)?.user;
  if (
    !u ||
    typeof u.id !== "string" ||
    typeof u.email !== "string" ||
    typeof u.displayName !== "string" ||
    !isRole(u.role) ||
    !(u.organisationId === null || typeof u.organisationId === "string")
  ) {
    throw new Error("Unexpected response from the server");
  }
  return {
    id: u.id,
    email: u.email,
    displayName: u.displayName,
    role: u.role,
    organisationId: u.organisationId,
  };
}

export async function fetchMe(token: string, signal?: AbortSignal): Promise<Me> {
  return parseMe(await apiFetch<unknown>("me", { token, signal }));
}

export const ROLE_LABEL: Record<Role, string> = {
  super_admin: "Super administrator",
  admin: "Administrator",
  manager: "Manager",
  trainer: "Trainer",
  learner: "Learner",
};
