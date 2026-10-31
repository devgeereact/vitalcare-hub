import { useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { ApiError } from "@/shared/services/api";
import { shortDate } from "../format";
import { asPage, type Certificate, type Course, type Person, pick, type Renewal } from "../types";
import {
  type FieldDef,
  isOwner,
  LoadView,
  Pager,
  RecordForm,
  StateBadge,
  TextActionForm,
  useMe,
} from "../ui";
import { useAdminDownload, useAdminGet, useAdminSend } from "../useAdmin";
import { RenewalTable } from "./Dashboard";
import { AddBox, ListSearch, useListParams } from "./People";

export function CertificatesPage() {
  const me = useMe();
  const { params, q, page, set } = useListParams();
  const status = params.get("status") ?? "";
  const [load, reload] = useAdminGet("admin/certificates", (d) => asPage<Certificate>(d), {
    q,
    page,
    status,
  });
  return (
    <>
      <h1>Certificates</h1>
      <hr className="rule" />
      <p>
        Certificates are issued from a session's attendance. The code on each one can be checked by
        anyone on the public verify page.
      </p>
      {isOwner(me) && <ManualIssue onIssued={reload} />}
      <div className="filters">
        <ListSearch
          label="Search by name, course or code"
          value={q}
          onSearch={(v) => set({ q: v })}
        />
        <div className="field">
          <label htmlFor="cstatus">Show</label>
          <select
            id="cstatus"
            className="input"
            value={status}
            onChange={(e) => set({ status: e.target.value })}
          >
            <option value="">All</option>
            <option value="valid">Valid</option>
            <option value="expired">Expired</option>
            <option value="revoked">Revoked</option>
          </select>
        </div>
      </div>
      <LoadView load={load} label="certificates" retry={reload}>
        {(p) =>
          p.items.length === 0 ? (
            <p>No certificates match.</p>
          ) : (
            <>
              <div className="table-wrap">
                <table>
                  <caption className="visually-hidden">Certificates</caption>
                  <thead>
                    <tr>
                      <th scope="col">Code</th>
                      <th scope="col">Name</th>
                      <th scope="col">Course</th>
                      <th scope="col">Completed</th>
                      <th scope="col">Valid until</th>
                      <th scope="col">State</th>
                    </tr>
                  </thead>
                  <tbody>
                    {p.items.map((c) => (
                      <tr key={c.id}>
                        <td>
                          <Link to={`/admin/certificates/${c.id}`}>{c.code}</Link>
                        </td>
                        <td>
                          {c.personId ? (
                            <Link to={`/admin/people/${c.personId}`}>{c.personName}</Link>
                          ) : (
                            c.personName
                          )}
                        </td>
                        <td>{c.courseTitle}</td>
                        <td>{shortDate(c.completedOn)}</td>
                        <td>{c.expiresOn ? shortDate(c.expiresOn) : "No expiry"}</td>
                        <td>
                          <StateBadge state={c.state} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <Pager page={p.page} total={p.total} onPage={(n) => set({ page: String(n) })} />
            </>
          )
        }
      </LoadView>
    </>
  );
}

/** Owner only: a certificate with no attendance behind it, for example from a paper register. */
function ManualIssue({ onIssued }: { onIssued: () => void }) {
  const send = useAdminSend();
  const navigate = useNavigate();
  const [q, setQ] = useState("");
  const term = q.trim();
  const [people] = useAdminGet(
    term.length >= 2 ? "admin/people" : null,
    (d) => asPage<Person>(d).items,
    { q: term },
  );
  const [courses] = useAdminGet("admin/courses", (d) => asPage<Course>(d).items, { page: 1 });
  const fields: FieldDef[] = [
    {
      key: "personId",
      label: "Person",
      type: "select",
      required: true,
      options:
        people.kind === "ok"
          ? people.data.map((p) => [p.id, `${p.firstName} ${p.lastName}`] as const)
          : [],
      hint: "Search for the person below first.",
    },
    {
      key: "courseId",
      label: "Course",
      type: "select",
      required: true,
      options: courses.kind === "ok" ? courses.data.map((c) => [c.id, c.title] as const) : [],
    },
    { key: "completedOn", label: "Training date", type: "date", required: true },
    {
      key: "reason",
      label: "Why this is issued by hand",
      type: "textarea",
      required: true,
      hint: "For example: training on 3 March 2025 recorded on the paper register. At least 10 characters.",
    },
  ];
  const options = fields[0]?.options ?? [];
  const courseOptions = fields[1]?.options ?? [];
  return (
    <AddBox summary="Issue a certificate by hand (owner only)">
      <div className="field">
        <label htmlFor="mi-q">Find the person</label>
        <input
          id="mi-q"
          className="input"
          type="search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
      </div>
      <RecordForm
        key={`${options.length}-${courseOptions.length}-${options[0]?.[0] ?? ""}`}
        fields={fields}
        initial={{
          personId: options[0]?.[0] ?? "",
          courseId: courseOptions[0]?.[0] ?? "",
          completedOn: "",
          reason: "",
        }}
        submitLabel="Issue certificate"
        onSubmit={async (body) => {
          const r = await send("POST", "admin/certificates", body);
          onIssued();
          navigate(`/admin/certificates/${pick<Certificate>(r, "certificate").id}`);
        }}
      />
    </AddBox>
  );
}

export function CertificatePage() {
  const me = useMe();
  const { id = "" } = useParams();
  const [load, reload] = useAdminGet(`admin/certificates/${id}`, (d) =>
    pick<Certificate>(d, "certificate"),
  );
  const send = useAdminSend();
  const download = useAdminDownload();
  const [problem, setProblem] = useState<string | null>(null);
  return (
    <LoadView load={load} label="this certificate" retry={reload}>
      {(c) => (
        <>
          <p className="crumbs">
            <Link to="/admin/certificates">Certificates</Link>
          </p>
          <h1>Certificate {c.code}</h1>
          <hr className="rule" />
          <p>
            <StateBadge state={c.state} />
          </p>
          <dl className="facts">
            <dt>Name</dt>
            <dd>
              {c.personId ? (
                <Link to={`/admin/people/${c.personId}`}>{c.personName}</Link>
              ) : (
                c.personName
              )}
            </dd>
            <dt>Course</dt>
            <dd>{c.courseTitle}</dd>
            {c.hours !== null && (
              <>
                <dt>Hours</dt>
                <dd>{c.hours}</dd>
              </>
            )}
            <dt>Training date</dt>
            <dd>{shortDate(c.completedOn)}</dd>
            <dt>Issued on</dt>
            <dd>{shortDate(c.issuedOn) || "Not recorded"}</dd>
            <dt>Valid until</dt>
            <dd>{c.expiresOn ? shortDate(c.expiresOn) : "No expiry date"}</dd>
            <dt>How issued</dt>
            <dd>{c.byHand ? `By hand: ${c.issueReason ?? ""}` : "From session attendance"}</dd>
            {c.state === "revoked" && (
              <>
                <dt>Revoked on</dt>
                <dd>{shortDate(c.revokedOn)}</dd>
                <dt>Reason (staff only)</dt>
                <dd>{c.revokeReason}</dd>
              </>
            )}
          </dl>
          <div className="actions">
            {c.state !== "revoked" && (
              <button
                className="btn"
                type="button"
                onClick={() => {
                  setProblem(null);
                  download(`admin/certificates/${c.id}/pdf`).catch((err) =>
                    setProblem(
                      err instanceof ApiError ? err.message : "The download failed. Try again.",
                    ),
                  );
                }}
              >
                Download PDF
              </button>
            )}
            <a className="btn btn--quiet" href={`/verify/${c.code.replace(/-/g, "")}`}>
              Open the public check
            </a>
          </div>
          {problem && (
            <p className="notice notice--error" role="alert">
              <strong>Problem</strong>
              {problem}
            </p>
          )}
          {c.state !== "revoked" && isOwner(me) && (
            <section className="panel panel--danger" aria-labelledby="rev-h">
              <h2 id="rev-h">Revoke this certificate</h2>
              <p>
                The public check will then say Revoked. The person's name and your reason are never
                shown publicly. This cannot be undone; issue a new certificate if needed.
              </p>
              <TextActionForm
                label="Reason"
                hint="At least 10 characters. Kept for staff only."
                submitLabel="Revoke certificate"
                minLength={10}
                danger
                onSubmit={async (reason) => {
                  await send("POST", `admin/certificates/${c.id}/revoke`, { reason });
                  reload();
                }}
              />
            </section>
          )}
        </>
      )}
    </LoadView>
  );
}

export function RenewalsPage() {
  const { params, set } = useListParams();
  const window = params.get("window") ?? "30";
  const [load, reload] = useAdminGet("admin/renewals", (d) => pick<Renewal[]>(d, "items"), {
    window,
  });
  const tabs = [
    ["30", "Next 30 days"],
    ["60", "Next 60 days"],
    ["90", "Next 90 days"],
    ["expired", "Expired"],
  ] as const;
  return (
    <>
      <h1>Renewals</h1>
      <hr className="rule" />
      <p>
        The newest certificate for each person and course. Reminder emails go automatically 60, 30
        and 7 days before the date, to the person (unless they opted out) and, if the organisation
        asked for it, to its contact.
      </p>
      <nav className="tabs" aria-label="Renewal window">
        {tabs.map(([v, label]) => (
          <button
            key={v}
            type="button"
            className={`btn ${window === v ? "" : "btn--quiet"}`}
            aria-pressed={window === v}
            onClick={() => set({ window: v })}
          >
            {label}
          </button>
        ))}
      </nav>
      <LoadView load={load} label="renewals" retry={reload}>
        {(items) =>
          items.length === 0 ? (
            <p>Nothing in this window.</p>
          ) : (
            <RenewalTable items={items} caption="Renewals" />
          )
        }
      </LoadView>
    </>
  );
}
