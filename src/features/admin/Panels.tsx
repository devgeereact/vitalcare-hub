import { useState } from "react";
import { Link } from "react-router-dom";
import { dateTime, shortDate } from "./format";
import { type FollowUp, type Note, pick, type StaffMember, type SubjectType } from "./types";
import { type FieldDef, RecordForm, TextActionForm } from "./ui";
import { useAdminGet, useAdminSend } from "./useAdmin";

/** Note history, newest first, with a box to add one. Notes are never edited or deleted. */
export function NotesPanel({
  notes,
  addPath,
  onAdded,
  title = "Notes",
}: {
  notes: Note[];
  addPath: string;
  onAdded: () => void;
  title?: string;
}) {
  const send = useAdminSend();
  return (
    <section aria-labelledby="notes-h" className="panel">
      <h2 id="notes-h">{title}</h2>
      <TextActionForm
        label="Add a note"
        hint="Notes are kept as a history and cannot be edited later."
        submitLabel="Add note"
        onSubmit={async (body) => {
          await send("POST", addPath, { body });
          onAdded();
        }}
      />
      {notes.length === 0 ? (
        <p className="hint">No notes yet.</p>
      ) : (
        <ol className="history">
          {notes.map((n) => (
            <li key={n.id}>
              <p className="history__meta">
                {n.kind === "reply" ? "Email reply" : "Note"} by {n.authorName},{" "}
                {dateTime(n.createdAt)}
              </p>
              <p className="history__body">{n.body}</p>
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}

export function useStaff(): StaffMember[] {
  const [load] = useAdminGet("admin/staff", (d) => pick<StaffMember[]>(d, "items"));
  return load.kind === "ok" ? load.data : [];
}

/** Follow-ups for one record (or all), with done toggles and an add form. */
export function FollowUpsPanel({
  items,
  subject,
  onChanged,
  showSubject = false,
}: {
  items: FollowUp[];
  subject?: { type: SubjectType; id: string };
  onChanged: () => void;
  showSubject?: boolean;
}) {
  const send = useAdminSend();
  const staff = useStaff();
  const [problem, setProblem] = useState<string | null>(null);
  const fields: FieldDef[] = [
    { key: "title", label: "What needs doing", required: true },
    { key: "dueOn", label: "Due on", type: "date", required: true },
    {
      key: "assigneeId",
      label: "Assigned to",
      type: "select",
      options: staff.map((s) => [s.id, s.displayName] as const),
    },
  ];
  async function toggle(f: FollowUp) {
    setProblem(null);
    try {
      await send("PATCH", `admin/follow-ups/${f.id}`, { done: !f.done });
      onChanged();
    } catch {
      setProblem("We could not update that follow-up. Try again.");
    }
  }
  return (
    <section aria-labelledby="fu-h" className="panel">
      <h2 id="fu-h">Follow-ups</h2>
      {problem && (
        <p className="notice notice--error" role="alert">
          <strong>Problem</strong>
          {problem}
        </p>
      )}
      {items.length === 0 ? (
        <p className="hint">Nothing to follow up.</p>
      ) : (
        <ul className="checklist">
          {items.map((f) => (
            <li key={f.id} className={f.overdue ? "is-overdue" : undefined}>
              <input
                type="checkbox"
                id={`fu-${f.id}`}
                checked={f.done}
                onChange={() => void toggle(f)}
              />
              <label htmlFor={`fu-${f.id}`}>
                {f.title}
                <span className="hint">
                  {" "}
                  due {shortDate(f.dueOn)}
                  {f.overdue ? " (overdue)" : ""}
                  {f.assigneeName ? `, ${f.assigneeName}` : ", unassigned"}
                </span>
              </label>
              {showSubject && f.subjectLabel && (
                <Link className="checklist__subject" to={subjectLink(f.subjectType, f.subjectId)}>
                  {f.subjectLabel}
                </Link>
              )}
            </li>
          ))}
        </ul>
      )}
      {subject && (
        <details className="add-box">
          <summary>Add a follow-up</summary>
          <RecordForm
            key={staff.length}
            fields={fields}
            initial={{ title: "", dueOn: "", assigneeId: "" }}
            submitLabel="Add follow-up"
            resetOnSave
            savedText="Follow-up added."
            onSubmit={async (body) => {
              await send("POST", "admin/follow-ups", {
                ...body,
                subjectType: subject.type,
                subjectId: subject.id,
              });
              onChanged();
            }}
          />
        </details>
      )}
    </section>
  );
}

export function subjectLink(type: SubjectType, id: string): string {
  return type === "person"
    ? `/admin/people/${id}`
    : type === "organisation"
      ? `/admin/organisations/${id}`
      : `/admin/enquiries/${id}`;
}

export const followUpsOf = (d: unknown) => pick<FollowUp[]>(d, "followUps");
export const notesOf = (d: unknown) => pick<Note[]>(d, "notes");
