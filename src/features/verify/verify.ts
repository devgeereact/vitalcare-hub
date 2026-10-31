import { apiFetch } from "@/shared/services/api";

export type VerifiedCertificate = {
  code: string;
  learnerName: string;
  courseTitle: string;
  hours: number | null;
  completedOn: string;
  expiresOn: string | null;
  issuer: string;
};

/** A revoked certificate: deliberately no name and no reason (D-018). */
export type RevokedCertificate = {
  code: string;
  courseTitle: string;
  completedOn: string;
  revokedOn: string;
  issuer: string;
};

export type VerifyResult =
  | { status: "not_issued" }
  | { status: "revoked"; certificate: RevokedCertificate }
  | { status: "valid" | "expired"; certificate: VerifiedCertificate };

/** Keeps only letters and digits, so the code is always a safe single path segment. */
export function cleanCode(raw: string): string {
  return raw.replace(/[^A-Za-z0-9]/g, "").toUpperCase();
}

const isDate = (v: unknown): v is string => typeof v === "string" && /^\d{4}-\d{2}-\d{2}$/.test(v);

/** Validates the API response at the boundary. Anything unexpected throws; it is never guessed at. */
export function parseVerify(data: unknown): VerifyResult {
  const d = data as { status?: unknown; certificate?: Record<string, unknown> } | null;
  if (d?.status === "not_issued") return { status: "not_issued" };
  const c = d?.certificate;
  if (
    d?.status === "revoked" &&
    c &&
    typeof c.code === "string" &&
    typeof c.courseTitle === "string" &&
    isDate(c.completedOn) &&
    isDate(c.revokedOn) &&
    typeof c.issuer === "string"
  ) {
    // Only these fields are kept, so nothing else the server might send can reach the page.
    return {
      status: "revoked",
      certificate: {
        code: c.code,
        courseTitle: c.courseTitle,
        completedOn: c.completedOn,
        revokedOn: c.revokedOn,
        issuer: c.issuer,
      },
    };
  }
  if (
    (d?.status === "valid" || d?.status === "expired") &&
    c &&
    typeof c.code === "string" &&
    typeof c.learnerName === "string" &&
    typeof c.courseTitle === "string" &&
    (c.hours === null || typeof c.hours === "number") &&
    isDate(c.completedOn) &&
    (c.expiresOn === null || isDate(c.expiresOn)) &&
    typeof c.issuer === "string"
  ) {
    return {
      status: d.status,
      certificate: {
        code: c.code,
        learnerName: c.learnerName,
        courseTitle: c.courseTitle,
        hours: c.hours,
        completedOn: c.completedOn,
        expiresOn: c.expiresOn,
        issuer: c.issuer,
      },
    };
  }
  throw new Error("Unexpected response from the server");
}

export async function verifyCertificate(code: string, signal?: AbortSignal): Promise<VerifyResult> {
  return parseVerify(await apiFetch<unknown>(`verify/${cleanCode(code)}`, { signal }));
}

/** "2027-03-03" as "3 March 2027", with no time zone shift. */
export function formatDate(iso: string): string {
  const [y, m, d] = iso.split("-").map(Number);
  return new Date(Date.UTC(y ?? 1970, (m ?? 1) - 1, d ?? 1)).toLocaleDateString("en-GB", {
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  });
}
