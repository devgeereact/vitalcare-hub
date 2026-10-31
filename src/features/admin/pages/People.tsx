import { type FormEvent, type ReactNode, useState } from "react";
import { Link, useNavigate, useParams, useSearchParams } from "react-router-dom";
import {
  ATTENDANCE_LABEL,
  dateTime,
  KIND_LABEL,
  personName,
  RESULT_LABEL,
  shortDate,
} from "../format";
import { FollowUpsPanel, followUpsOf, NotesPanel, notesOf } from "../Panels";
import {
  asPage,
  type Certificate,
  type FollowUp,
  type Note,
  type Organisation,
  type Person,
  pick,
} from "../types";
import { type FieldDef, LoadView, Pager, RecordForm, StateBadge, toFormValues } from "../ui";
import { useAdminGet, useAdminSend } from "../useAdmin";

/** Search box and page number kept in the address, so a list can be bookmarked and the back button works. */
export function useListParams() {
  const [params, setParams] = useSearchParams();
  const q = params.get("q") ?? "";
  const page = Math.max(1, Number(params.get("page") ?? 1) || 1);
  const set = (next: Record<string, string>) => {
    const p = new URLSearchParams(params);
    for (const [k, v] of Object.entries(next)) {
      if (v) p.set(k, v);
      else p.delete(k);
    }
    if (!("page" in next)) p.delete("page");
    setParams(p);
  };
  return { params, q, page, set };
}

export function ListSearch({
  label,
  value,
  onSearch,
}: {
  label: string;
  value: string;
  onSearch: (q: string) => void;
}) {
  const [text, setText] = useState(value);
  function submit(e: FormEvent) {
    e.preventDefault();
    onSearch(text.trim());
  }
  return (
    <form className="list-search" onSubmit={submit}>
      <label htmlFor="list-q">{label}</label>
      <div className="inline">
        <input
          id="list-q"
          className="input"
          type="search"
          value={text}
          onChange={(e) => setText(e.target.value)}
        />
        <button className="btn" type="submit">
          Search
        </button>
      </div>
    </form>
  );
}

export function AddBox({ summary, children }: { summary: string; children: ReactNode }) {
  return (
    <details className="add-box">
      <summary>{summary}</summary>
      {children}
    </details>
  );
}

const organisationsOf = (d: unknown) => asPage<Organisation>(d).items;

export function useOrganisationOptions() {
  const [load] = useAdminGet("admin/organisations", organisationsOf, { page: 1 });
  return load.kind === "ok" ? load.data.map((o) => [o.id, o.name] as const) : [];
}

export function personFields(orgOptions: readonly (readonly [string, string])[]): FieldDef[] {
  return [
    { key: "firstName", label: "First name", required: true },
    { key: "lastName", label: "Last name", required: true },
    { key: "email", label: "Email address", type: "email", hint: "Renewal reminders go here." },
    { key: "phone", label: "Telephone", type: "tel" },
    { key: "jobTitle", label: "Job title" },
    { key: "organisationId", label: "Organisation", type: "select", options: orgOptions },
    {
      key: "remindersOptOut",
      label: "Do not send this person renewal reminders",
      type: "checkbox",
    },
    {
      key: "status",
      label: "Status",
      type: "select",
      required: true,
      options: [
        ["active", "Active"],
        ["archived", "Archived"],
      ],
    },
  ];
}

export function PeoplePage() {
  const { params, q, page, set } = useListParams();
  const status = params.get("status") ?? "active";
  const organisationId = params.get("organisationId") ?? undefined;
  const [load, reload] = useAdminGet("admin/people", (d) => asPage<Person>(d), {
    q,
    page,
    status,
    organisationId,
  });
  const send = useAdminSend();
  const navigate = useNavigate();
  const orgs = useOrganisationOptions();
  const fields = personFields(orgs);
  return (
    <>
      <h1>People</h1>
      <hr className="rule" />
      <AddBox summary="Add a person">
        <RecordForm
          key={orgs.length}
          fields={fields}
          initial={toFormValues(fields, { status: "active" })}
          submitLabel="Add person"
          onSubmit={async (body) => {
            const r = await send("POST", "admin/people", body);
            navigate(`/admin/people/${pick<Person>(r, "person").id}`);
          }}
        />
      </AddBox>
      <div className="filters">
        <ListSearch
          label="Search by name, email or telephone"
          value={q}
          onSearch={(v) => set({ q: v })}
        />
        <div className="field">
          <label htmlFor="status-filter">Show</label>
          <select
            id="status-filter"
            className="input"
            value={status}
            onChange={(e) => set({ status: e.target.value })}
          >
            <option value="active">Active</option>
            <option value="archived">Archived</option>
          </select>
        </div>
      </div>
      <LoadView load={load} label="people" retry={reload}>
        {(p) =>
          p.items.length === 0 ? (
            <p>No people match.</p>
          ) : (
            <>
              <div className="table-wrap">
                <table>
                  <caption className="visually-hidden">People</caption>
                  <thead>
                    <tr>
                      <th scope="col">Name</th>
                      <th scope="col">Organisation</th>
                      <th scope="col">Email</th>
                      <th scope="col">Job title</th>
                    </tr>
                  </thead>
                  <tbody>
                    {p.items.map((x) => (
                      <tr key={x.id}>
                        <td>
                          <Link to={`/admin/people/${x.id}`}>{personName(x)}</Link>
                        </td>
                        <td>{x.organisationName ?? ""}</td>
                        <td>{x.email ?? ""}</td>
                        <td>{x.jobTitle ?? ""}</td>
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

type PersonDetail = {
  person: Person;
  attendance: {
    attendeeId: string;
    sessionId: string;
    courseTitle: string;
    startsAt: string;
    attendance: keyof typeof ATTENDANCE_LABEL;
    result: keyof typeof RESULT_LABEL | null;
    certificateId: string | null;
  }[];
  certificates: Certificate[];
  notes: Note[];
  followUps: FollowUp[];
};

export function PersonPage() {
  const { id = "" } = useParams();
  const [load, reload] = useAdminGet(`admin/people/${id}`, (d) => {
    pick(d, "person");
    notesOf(d);
    followUpsOf(d);
    return d as PersonDetail;
  });
  const send = useAdminSend();
  const orgs = useOrganisationOptions();
  const fields = personFields(orgs);
  return (
    <LoadView load={load} label="this person" retry={reload}>
      {(d) => (
        <>
          <p className="crumbs">
            <Link to="/admin/people">People</Link>
          </p>
          <h1>{personName(d.person)}</h1>
          <hr className="rule" />
          {d.person.organisationId && (
            <p>
              Works at{" "}
              <Link to={`/admin/organisations/${d.person.organisationId}`}>
                {d.person.organisationName}
              </Link>
            </p>
          )}
          <div className="two-col">
            <section className="panel" aria-labelledby="details-h">
              <h2 id="details-h">Details</h2>
              <RecordForm
                key={`${d.person.id}-${orgs.length}`}
                fields={fields}
                initial={toFormValues(fields, d.person)}
                submitLabel="Save changes"
                onSubmit={async (body) => {
                  await send("PATCH", `admin/people/${id}`, body);
                  reload();
                }}
              />
            </section>
            <div>
              <section className="panel" aria-labelledby="certs-h">
                <h2 id="certs-h">Certificates</h2>
                {d.certificates.length === 0 ? (
                  <p className="hint">No certificates yet.</p>
                ) : (
                  <ul className="plain">
                    {d.certificates.map((c) => (
                      <li key={c.id}>
                        <Link to={`/admin/certificates/${c.id}`}>{c.courseTitle}</Link>{" "}
                        <StateBadge state={c.state} />
                        <span className="hint">
                          {" "}
                          {c.expiresOn ? `valid until ${shortDate(c.expiresOn)}` : "no expiry"}
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </section>
              <section className="panel" aria-labelledby="att-h">
                <h2 id="att-h">Training sessions</h2>
                {d.attendance.length === 0 ? (
                  <p className="hint">Not booked on any session.</p>
                ) : (
                  <ul className="plain">
                    {d.attendance.map((a) => (
                      <li key={a.attendeeId}>
                        <Link to={`/admin/sessions/${a.sessionId}`}>
                          {a.courseTitle}, {dateTime(a.startsAt)}
                        </Link>
                        <span className="hint">
                          {" "}
                          {ATTENDANCE_LABEL[a.attendance]}
                          {a.result ? `, ${RESULT_LABEL[a.result]}` : ""}
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </section>
            </div>
          </div>
          <FollowUpsPanel items={d.followUps} subject={{ type: "person", id }} onChanged={reload} />
          <NotesPanel notes={d.notes} addPath={`admin/people/${id}/notes`} onAdded={reload} />
        </>
      )}
    </LoadView>
  );
}

// ---------------------------------------------------------------- organisations

const orgFields: FieldDef[] = [
  { key: "name", label: "Name", required: true },
  {
    key: "kind",
    label: "Type",
    type: "select",
    required: true,
    options: Object.entries(KIND_LABEL),
  },
  { key: "contactName", label: "Contact name" },
  { key: "contactEmail", label: "Contact email", type: "email" },
  { key: "phone", label: "Telephone", type: "tel" },
  { key: "address", label: "Address", type: "textarea" },
  {
    key: "reminderCopy",
    label: "Copy renewal reminders for their staff to the contact email",
    type: "checkbox",
  },
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

export function OrganisationsPage() {
  const { q, page, set } = useListParams();
  const [load, reload] = useAdminGet("admin/organisations", (d) => asPage<Organisation>(d), {
    q,
    page,
  });
  const send = useAdminSend();
  const navigate = useNavigate();
  return (
    <>
      <h1>Organisations</h1>
      <hr className="rule" />
      <AddBox summary="Add an organisation">
        <RecordForm
          fields={orgFields}
          initial={toFormValues(orgFields, { kind: "care_home", status: "active" })}
          submitLabel="Add organisation"
          onSubmit={async (body) => {
            const r = await send("POST", "admin/organisations", body);
            navigate(`/admin/organisations/${pick<Organisation>(r, "organisation").id}`);
          }}
        />
      </AddBox>
      <ListSearch
        label="Search by name or contact email"
        value={q}
        onSearch={(v) => set({ q: v })}
      />
      <LoadView load={load} label="organisations" retry={reload}>
        {(p) =>
          p.items.length === 0 ? (
            <p>No organisations match.</p>
          ) : (
            <>
              <div className="table-wrap">
                <table>
                  <caption className="visually-hidden">Organisations</caption>
                  <thead>
                    <tr>
                      <th scope="col">Name</th>
                      <th scope="col">Type</th>
                      <th scope="col">People</th>
                      <th scope="col">Contact</th>
                    </tr>
                  </thead>
                  <tbody>
                    {p.items.map((o) => (
                      <tr key={o.id}>
                        <td>
                          <Link to={`/admin/organisations/${o.id}`}>{o.name}</Link>
                          {o.status === "disabled" && <span className="hint"> (disabled)</span>}
                        </td>
                        <td>{KIND_LABEL[o.kind]}</td>
                        <td>{o.peopleCount ?? 0}</td>
                        <td>{o.contactName ?? o.contactEmail ?? ""}</td>
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

type StaffRow = {
  id: string;
  name: string;
  jobTitle: string | null;
  status: string;
  summary: "none" | "in_date" | "due_soon" | "expired";
  certificates: {
    id: string;
    code: string;
    courseTitle: string;
    expiresOn: string | null;
    state: "in_date" | "due_soon" | "expired";
  }[];
};

export function OrganisationPage() {
  const { id = "" } = useParams();
  const [load, reload] = useAdminGet(`admin/organisations/${id}`, (d) => {
    pick(d, "organisation");
    pick(d, "staff");
    return d as {
      organisation: Organisation;
      staff: StaffRow[];
      notes: Note[];
      followUps: FollowUp[];
    };
  });
  const send = useAdminSend();
  return (
    <LoadView load={load} label="this organisation" retry={reload}>
      {(d) => (
        <>
          <p className="crumbs">
            <Link to="/admin/organisations">Organisations</Link>
          </p>
          <h1>{d.organisation.name}</h1>
          <hr className="rule" />
          <section className="panel" aria-labelledby="staff-h">
            <h2 id="staff-h">Staff and training</h2>
            <p className="hint">
              Each person's current certificate per course. Due soon means it expires within 30
              days. <Link to={`/admin/people?organisationId=${id}`}>Show as a list</Link>
            </p>
            {d.staff.length === 0 ? (
              <p className="hint">No people linked to this organisation yet.</p>
            ) : (
              <div className="table-wrap">
                <table>
                  <caption className="visually-hidden">Staff and their training</caption>
                  <thead>
                    <tr>
                      <th scope="col">Name</th>
                      <th scope="col">Overall</th>
                      <th scope="col">Certificates</th>
                    </tr>
                  </thead>
                  <tbody>
                    {d.staff.map((s) => (
                      <tr key={s.id}>
                        <td>
                          <Link to={`/admin/people/${s.id}`}>{s.name}</Link>
                          {s.jobTitle && <span className="hint"> {s.jobTitle}</span>}
                          {s.status === "archived" && <span className="hint"> (archived)</span>}
                        </td>
                        <td>
                          <StateBadge state={s.summary} />
                        </td>
                        <td>
                          {s.certificates.length === 0
                            ? "None"
                            : s.certificates.map((c) => (
                                <div key={c.id}>
                                  <Link to={`/admin/certificates/${c.id}`}>{c.courseTitle}</Link>{" "}
                                  <span className="hint">
                                    {c.expiresOn ? `until ${shortDate(c.expiresOn)}` : "no expiry"}
                                  </span>{" "}
                                  <StateBadge state={c.state} />
                                </div>
                              ))}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
          <section className="panel" aria-labelledby="odetails-h">
            <h2 id="odetails-h">Details</h2>
            <RecordForm
              key={d.organisation.id}
              fields={orgFields}
              initial={toFormValues(orgFields, d.organisation)}
              submitLabel="Save changes"
              onSubmit={async (body) => {
                await send("PATCH", `admin/organisations/${id}`, body);
                reload();
              }}
            />
          </section>
          <FollowUpsPanel
            items={d.followUps}
            subject={{ type: "organisation", id }}
            onChanged={reload}
          />
          <NotesPanel
            notes={d.notes}
            addPath={`admin/organisations/${id}/notes`}
            onAdded={reload}
          />
        </>
      )}
    </LoadView>
  );
}
