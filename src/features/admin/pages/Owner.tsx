import { useState } from "react";
import { Link } from "react-router-dom";
import { ROLE_LABEL } from "@/features/account/me";
import { ApiError } from "@/shared/services/api";
import { dateTime } from "../format";
import { type AuditEntry, asPage, pick, type StaffAccount } from "../types";
import {
  Badge,
  type FieldDef,
  isOwner,
  LoadView,
  Pager,
  RecordForm,
  toFormValues,
  useMe,
} from "../ui";
import { useAdminDownload, useAdminGet, useAdminSend } from "../useAdmin";
import { AddBox, useListParams } from "./People";

/** Screens only the owner can use. The API refuses the same calls for anyone else. */
function OwnerOnly({ children }: { children: React.ReactNode }) {
  const me = useMe();
  if (!isOwner(me)) {
    return (
      <p className="notice notice--info" role="status">
        <strong>Owner only</strong>
        This page is for the owner's account.
      </p>
    );
  }
  return <>{children}</>;
}

export function ExportsPage() {
  const download = useAdminDownload();
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  async function get(path: string) {
    setProblem(null);
    setBusy(path);
    try {
      await download(path);
    } catch (err) {
      setProblem(err instanceof ApiError ? err.message : "The download failed. Try again.");
    } finally {
      setBusy(null);
    }
  }
  return (
    <>
      <h1>Exports</h1>
      <hr className="rule" />
      <OwnerOnly>
        <p>
          Spreadsheet files (CSV) of the whole record. They hold personal data: keep them on a
          secured device and delete them when done. Cells that a spreadsheet could run as a formula
          are made safe. Each export is recorded in the audit log.
        </p>
        <div className="actions">
          <button
            className="btn"
            type="button"
            disabled={busy !== null}
            onClick={() => void get("admin/export/people.csv")}
          >
            {busy === "admin/export/people.csv" ? "Preparing" : "Download people"}
          </button>
          <button
            className="btn"
            type="button"
            disabled={busy !== null}
            onClick={() => void get("admin/export/certificates.csv")}
          >
            {busy === "admin/export/certificates.csv" ? "Preparing" : "Download certificates"}
          </button>
        </div>
        {problem && (
          <p className="notice notice--error" role="alert">
            <strong>Problem</strong>
            {problem}
          </p>
        )}
      </OwnerOnly>
    </>
  );
}

const ENTITY_LINK: Record<string, string> = {
  person: "/admin/people/",
  organisation: "/admin/organisations/",
  course: "/admin/courses/",
  session: "/admin/sessions/",
  certificate: "/admin/certificates/",
  enquiry: "/admin/enquiries/",
};

export function AuditPage() {
  const { page, set } = useListParams();
  const me = useMe();
  const [load, reload] = useAdminGet(
    isOwner(me) ? "admin/audit" : null,
    (d) => asPage<AuditEntry>(d),
    { page },
  );
  return (
    <>
      <h1>Audit log</h1>
      <hr className="rule" />
      <OwnerOnly>
        <p>
          Who changed what, and when. The log names the fields that changed, never their contents.
        </p>
        <LoadView load={load} label="the audit log" retry={reload}>
          {(p) => (
            <>
              <div className="table-wrap">
                <table>
                  <caption className="visually-hidden">Audit log</caption>
                  <thead>
                    <tr>
                      <th scope="col">When</th>
                      <th scope="col">Who</th>
                      <th scope="col">What</th>
                      <th scope="col">Record</th>
                      <th scope="col">Fields</th>
                    </tr>
                  </thead>
                  <tbody>
                    {p.items.map((a) => (
                      <tr key={a.id}>
                        <td>{dateTime(a.at)}</td>
                        <td>{a.actorName ?? "System"}</td>
                        <td>{a.action}</td>
                        <td>
                          {a.entityId && ENTITY_LINK[a.entityType] ? (
                            <Link to={`${ENTITY_LINK[a.entityType]}${a.entityId}`}>
                              {a.entityType}
                            </Link>
                          ) : (
                            a.entityType
                          )}
                        </td>
                        <td>{a.fields.join(", ")}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <Pager page={p.page} total={p.total} onPage={(n) => set({ page: String(n) })} />
            </>
          )}
        </LoadView>
      </OwnerOnly>
    </>
  );
}

const ROLE_OPTIONS = [
  ["admin", ROLE_LABEL.admin],
  ["super_admin", ROLE_LABEL.super_admin],
] as const;

const newUserFields: FieldDef[] = [
  { key: "displayName", label: "Name", required: true },
  {
    key: "email",
    label: "Email address",
    type: "email",
    required: true,
    hint: "They sign in with a code sent to this address.",
  },
  { key: "role", label: "Role", type: "select", required: true, options: ROLE_OPTIONS },
];

const editUserFields: FieldDef[] = [
  { key: "displayName", label: "Name", required: true },
  { key: "role", label: "Role", type: "select", required: true, options: ROLE_OPTIONS },
  {
    key: "status",
    label: "Status",
    type: "select",
    required: true,
    options: [
      ["active", "Active"],
      ["disabled", "Disabled"],
    ],
  },
];

export function StaffAccountsPage() {
  const me = useMe();
  const [load, reload] = useAdminGet(isOwner(me) ? "admin/users" : null, (d) =>
    pick<StaffAccount[]>(d, "items"),
  );
  const send = useAdminSend();
  return (
    <>
      <h1>Staff accounts</h1>
      <hr className="rule" />
      <OwnerOnly>
        <p>
          Only the people listed here can sign in. An administrator runs the office; an owner can
          also change courses, revoke certificates, export, read the audit log and manage these
          accounts.
        </p>
        <AddBox summary="Add a staff account">
          <RecordForm
            fields={newUserFields}
            initial={{ displayName: "", email: "", role: "admin" }}
            submitLabel="Add account"
            resetOnSave
            savedText="Account added. They can now sign in with an email code."
            onSubmit={async (body) => {
              await send("POST", "admin/users", body);
              reload();
            }}
          />
        </AddBox>
        <LoadView load={load} label="staff accounts" retry={reload}>
          {(users) => (
            <ul className="plain">
              {users.map((u) => (
                <li key={u.id} className="panel">
                  <h2 className="h3">
                    {u.displayName || u.email}{" "}
                    {u.status === "disabled" ? (
                      <Badge tone="error">Disabled</Badge>
                    ) : (
                      <Badge tone="ok">Active</Badge>
                    )}
                  </h2>
                  <p className="hint">
                    {u.email}.{" "}
                    {u.role in ROLE_LABEL ? ROLE_LABEL[u.role as keyof typeof ROLE_LABEL] : u.role}.{" "}
                    {u.linked
                      ? `Last signed in ${dateTime(u.lastSignInAt) || "not recorded"}.`
                      : "Has not signed in yet."}
                  </p>
                  {u.id === me.id ? (
                    <p className="hint">
                      This is you. Another owner must change your role or status.
                    </p>
                  ) : u.role === "admin" || u.role === "super_admin" ? (
                    <details>
                      <summary>Change {u.displayName || u.email}</summary>
                      <RecordForm
                        key={`${u.role}-${u.status}`}
                        fields={editUserFields}
                        initial={toFormValues(editUserFields, u)}
                        submitLabel="Save"
                        onSubmit={async (body) => {
                          await send("PATCH", `admin/users/${u.id}`, body);
                          reload();
                        }}
                      />
                    </details>
                  ) : (
                    <p className="hint">This role cannot sign in to the office system.</p>
                  )}
                </li>
              ))}
            </ul>
          )}
        </LoadView>
      </OwnerOnly>
    </>
  );
}
