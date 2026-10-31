import { apiFetch } from "@/shared/services/api";

/**
 * A published course as the public API gives it. Every value is the owner's own statement, entered in
 * the CMS (D-018); nothing here is hard-coded copy.
 */
export type PublicCourse = {
  slug: string;
  title: string;
  summary: string;
  durationText: string;
  hours: number | null;
  format: "in_person" | "online" | "blended";
  pricePence: number | null;
  priceNote: string;
  assessmentMethod: string;
  renewalMonths: number | null;
  accreditationNote: string;
};

const FORMATS = ["in_person", "online", "blended"] as const;
const str = (v: unknown): v is string => typeof v === "string";
const numOrNull = (v: unknown): v is number | null =>
  v === null || (typeof v === "number" && Number.isFinite(v));

/** Validates one course at the boundary; anything unexpected throws, never guessed at. */
export function parseCourse(v: unknown): PublicCourse {
  const c = v as Record<string, unknown> | null;
  if (
    !c ||
    !str(c.slug) ||
    !/^[a-z0-9]+(-[a-z0-9]+)*$/.test(c.slug) ||
    !str(c.title) ||
    !str(c.summary) ||
    !str(c.durationText) ||
    !numOrNull(c.hours) ||
    !(FORMATS as readonly unknown[]).includes(c.format) ||
    !numOrNull(c.pricePence) ||
    !str(c.priceNote) ||
    !str(c.assessmentMethod) ||
    !numOrNull(c.renewalMonths) ||
    !str(c.accreditationNote)
  ) {
    throw new Error("Unexpected response from the server");
  }
  return {
    slug: c.slug,
    title: c.title,
    summary: c.summary,
    durationText: c.durationText,
    hours: c.hours,
    format: c.format as PublicCourse["format"],
    pricePence: c.pricePence,
    priceNote: c.priceNote,
    assessmentMethod: c.assessmentMethod,
    renewalMonths: c.renewalMonths,
    accreditationNote: c.accreditationNote,
  };
}

export function parseCourseList(data: unknown): PublicCourse[] {
  const list = (data as { courses?: unknown } | null)?.courses;
  if (!Array.isArray(list)) throw new Error("Unexpected response from the server");
  return list.map(parseCourse);
}

export async function fetchCourses(signal?: AbortSignal): Promise<PublicCourse[]> {
  return parseCourseList(await apiFetch<unknown>("courses", { signal }));
}

export async function fetchCourse(slug: string, signal?: AbortSignal): Promise<PublicCourse> {
  if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(slug)) throw new Error("Not a course address");
  const data = await apiFetch<{ course?: unknown }>(`courses/${slug}`, { signal });
  return parseCourse(data?.course);
}

export const FORMAT_TEXT = {
  in_person: "In person",
  online: "Online",
  blended: "In person and online",
} as const;

export function priceText(c: Pick<PublicCourse, "pricePence" | "priceNote">): string {
  if (c.pricePence === null) return "Price on request";
  const pounds = new Intl.NumberFormat("en-GB", { style: "currency", currency: "GBP" }).format(
    c.pricePence / 100,
  );
  return c.priceNote ? `${pounds} ${c.priceNote}` : pounds;
}

export function renewalText(months: number | null): string {
  if (months === null) return "No renewal period is set";
  if (months % 12 === 0) return months === 12 ? "Every year" : `Every ${months / 12} years`;
  return months === 1 ? "Every month" : `Every ${months} months`;
}
