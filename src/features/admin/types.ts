// Shapes returned by /api/admin (docs/API.md). Each page checks the top-level key it needs (see `pick`).

export type Page<T> = { items: T[]; total: number; page: number };

export type Person = {
  id: string;
  firstName: string;
  lastName: string;
  email: string | null;
  phone: string | null;
  jobTitle: string | null;
  organisationId: string | null;
  organisationName: string | null;
  remindersOptOut: boolean;
  status: "active" | "archived";
};

export type Organisation = {
  id: string;
  name: string;
  kind: "care_home" | "employer" | "other";
  contactName: string | null;
  contactEmail: string | null;
  phone: string | null;
  address: string | null;
  reminderCopy: boolean;
  status: "active" | "disabled";
  peopleCount?: number;
};

export type Course = {
  id: string;
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
  published: boolean;
  sortOrder: number;
  publishedAt: string | null;
};

export type TrainingSession = {
  id: string;
  courseId: string;
  courseTitle: string;
  startsAt: string;
  endsAt: string;
  venue: string | null;
  onlineLink: string | null;
  trainerName: string;
  capacity: number;
  booked: number;
  status: "scheduled" | "cancelled" | "completed";
};

export type Attendee = {
  id: string;
  personId: string;
  personName: string;
  organisationName: string | null;
  attendance: "booked" | "attended" | "no_show" | "cancelled";
  result: "pass" | "fail" | "not_assessed" | null;
  certificateId: string | null;
  certificateCode: string | null;
};

export type CertificateState = "valid" | "expired" | "revoked" | "not_issued";

export type Certificate = {
  id: string;
  code: string;
  personId: string | null;
  personName: string;
  courseId: string | null;
  courseTitle: string;
  hours: number | null;
  completedOn: string;
  issuedOn: string | null;
  expiresOn: string | null;
  state: CertificateState;
  byHand: boolean;
  issueReason: string | null;
  revokedOn: string | null;
  revokeReason: string | null;
};

export type Note = {
  id: string;
  kind: "note" | "reply";
  body: string;
  authorName: string;
  createdAt: string;
};

export type SubjectType = "person" | "organisation" | "enquiry";

export type FollowUp = {
  id: string;
  subjectType: SubjectType;
  subjectId: string;
  subjectLabel: string | null;
  title: string;
  dueOn: string;
  assigneeId: string | null;
  assigneeName: string | null;
  done: boolean;
  doneAt: string | null;
  overdue: boolean;
};

export type Enquiry = {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  message: string;
  status: "new" | "in_progress" | "closed";
  assigneeId: string | null;
  assigneeName: string | null;
  createdAt: string;
  closedAt: string | null;
};

export type Renewal = {
  certificateId: string;
  code: string;
  personId: string;
  personName: string;
  hasEmail: boolean;
  organisationId: string | null;
  organisationName: string | null;
  courseTitle: string;
  expiresOn: string;
  daysLeft: number;
  remindersSent: number[];
};

export type StaffMember = { id: string; displayName: string };

export type StaffAccount = {
  id: string;
  email: string;
  displayName: string;
  role: string;
  status: "active" | "disabled";
  linked: boolean;
  lastSignInAt: string | null;
};

export type AuditEntry = {
  id: number;
  at: string;
  actorName: string | null;
  action: string;
  entityType: string;
  entityId: string | null;
  fields: string[];
};

/** Reads one top-level key from a response, refusing anything that is not an object holding it. */
export function pick<T>(data: unknown, key: string): T {
  if (typeof data !== "object" || data === null || !(key in data)) {
    throw new Error("Unexpected response from the server");
  }
  return (data as Record<string, unknown>)[key] as T;
}

/** A paged list response: refuses anything without an items array and numeric totals. */
export function asPage<T>(data: unknown): Page<T> {
  const d = data as Partial<Page<T>> | null;
  if (!d || !Array.isArray(d.items) || typeof d.total !== "number" || typeof d.page !== "number") {
    throw new Error("Unexpected response from the server");
  }
  return { items: d.items, total: d.total, page: d.page };
}
