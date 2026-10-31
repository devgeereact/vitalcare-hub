import type { CertificateState } from "./types";

const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})/;

/** "2027-03-03" as "3 Mar 2027", with no time zone shift. */
export function shortDate(iso: string | null | undefined): string {
  if (!iso) return "";
  const m = ISO_DATE.exec(iso);
  if (!m) return iso;
  return new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]))).toLocaleDateString(
    "en-GB",
    { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" },
  );
}

/** "2026-11-05T09:30" (London local, from the API) as "5 Nov 2026, 09:30". */
export function dateTime(local: string | null | undefined): string {
  if (!local) return "";
  const [d, t] = local.split("T");
  return t ? `${shortDate(d)}, ${t.slice(0, 5)}` : shortDate(d);
}

/** Pence as pounds: 4500 as "£45.00". */
export function money(pence: number | null | undefined): string {
  if (pence === null || pence === undefined) return "";
  return new Intl.NumberFormat("en-GB", { style: "currency", currency: "GBP" }).format(pence / 100);
}

export const STATE_LABEL: Record<CertificateState, string> = {
  valid: "Valid",
  expired: "Expired",
  revoked: "Revoked",
  not_issued: "Not issued",
};

export const FORMAT_LABEL = {
  in_person: "In person",
  online: "Online",
  blended: "Blended",
} as const;

export const KIND_LABEL = { care_home: "Care home", employer: "Employer", other: "Other" } as const;

export const ATTENDANCE_LABEL = {
  booked: "Booked",
  attended: "Attended",
  no_show: "Did not attend",
  cancelled: "Cancelled",
} as const;

export const RESULT_LABEL = { pass: "Pass", fail: "Fail", not_assessed: "Not assessed" } as const;

export const ENQUIRY_LABEL = { new: "New", in_progress: "In progress", closed: "Closed" } as const;

export function personName(p: { firstName: string; lastName: string }): string {
  return `${p.firstName} ${p.lastName}`.trim();
}
