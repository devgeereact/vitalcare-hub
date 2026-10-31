import { Link, useParams } from "react-router-dom";
import { dateTime, ENQUIRY_LABEL } from "../format";
import { FollowUpsPanel, NotesPanel, useStaff } from "../Panels";
import { asPage, type Enquiry, type FollowUp, type Note, pick } from "../types";
import {
  Badge,
  type FieldDef,
  LoadView,
  Pager,
  RecordForm,
  TextActionForm,
  toFormValues,
} from "../ui";
import { useAdminGet, useAdminSend } from "../useAdmin";
import { ListSearch, useListParams } from "./People";

const TONE = { new: "warn", in_progress: "info", closed: "ok" } as const;

export function EnquiriesPage() {
  const { params, q, page, set } = useListParams();
  const status = params.get("status") ?? "";
  const [load, reload] = useAdminGet("admin/enquiries", (d) => asPage<Enquiry>(d), {
    q,
    page,
    status,
  });
  return (
    <>
      <h1>Enquiries</h1>
      <hr className="rule" />
      <div className="filters">
        <ListSearch label="Search enquiries" value={q} onSearch={(v) => set({ q: v })} />
        <div className="field">
          <label htmlFor="estatus">Show</label>
          <select
            id="estatus"
            className="input"
            value={status}
            onChange={(e) => set({ status: e.target.value })}
          >
            <option value="">All</option>
            <option value="new">New</option>
            <option value="in_progress">In progress</option>
            <option value="closed">Closed</option>
          </select>
        </div>
      </div>
      <LoadView load={load} label="enquiries" retry={reload}>
        {(p) =>
          p.items.length === 0 ? (
            <p>No enquiries.</p>
          ) : (
            <>
              <div className="table-wrap">
                <table>
                  <caption className="visually-hidden">Enquiries</caption>
                  <thead>
                    <tr>
                      <th scope="col">From</th>
                      <th scope="col">Received</th>
                      <th scope="col">Status</th>
                      <th scope="col">Assigned to</th>
                    </tr>
                  </thead>
                  <tbody>
                    {p.items.map((e) => (
                      <tr key={e.id}>
                        <td>
                          <Link to={`/admin/enquiries/${e.id}`}>{e.name}</Link>
                          <span className="hint"> {e.message.slice(0, 80)}</span>
                        </td>
                        <td>{dateTime(e.createdAt)}</td>
                        <td>
                          <Badge tone={TONE[e.status]}>{ENQUIRY_LABEL[e.status]}</Badge>
                        </td>
                        <td>{e.assigneeName ?? "Nobody"}</td>
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

export function EnquiryPage() {
  const { id = "" } = useParams();
  const [load, reload] = useAdminGet(`admin/enquiries/${id}`, (d) => {
    pick(d, "enquiry");
    return d as { enquiry: Enquiry; notes: Note[]; followUps: FollowUp[] };
  });
  const send = useAdminSend();
  const staff = useStaff();
  const fields: FieldDef[] = [
    {
      key: "status",
      label: "Status",
      type: "select",
      required: true,
      options: Object.entries(ENQUIRY_LABEL),
    },
    {
      key: "assigneeId",
      label: "Assigned to",
      type: "select",
      options: staff.map((s) => [s.id, s.displayName] as const),
    },
  ];
  return (
    <LoadView load={load} label="this enquiry" retry={reload}>
      {(d) => (
        <>
          <p className="crumbs">
            <Link to="/admin/enquiries">Enquiries</Link>
          </p>
          <h1>Enquiry from {d.enquiry.name}</h1>
          <hr className="rule" />
          <dl className="facts">
            <dt>Received</dt>
            <dd>{dateTime(d.enquiry.createdAt)}</dd>
            <dt>Email</dt>
            <dd>
              <a href={`mailto:${d.enquiry.email}`}>{d.enquiry.email}</a>
            </dd>
            <dt>Telephone</dt>
            <dd>{d.enquiry.phone ?? "Not given"}</dd>
          </dl>
          <section className="panel" aria-labelledby="msg-h">
            <h2 id="msg-h">Message</h2>
            <p className="history__body">{d.enquiry.message}</p>
          </section>
          <section className="panel" aria-labelledby="st-h">
            <h2 id="st-h">Status</h2>
            <RecordForm
              key={`${d.enquiry.status}-${d.enquiry.assigneeId}-${staff.length}`}
              fields={fields}
              initial={toFormValues(fields, d.enquiry)}
              submitLabel="Save"
              onSubmit={async (body) => {
                await send("PATCH", `admin/enquiries/${id}`, body);
                reload();
              }}
            />
          </section>
          <section className="panel" aria-labelledby="reply-h">
            <h2 id="reply-h">Reply by email</h2>
            <p className="hint">
              Sent from the office address to {d.enquiry.email}, with the company details added
              below your text. Replies come back to the office inbox.
            </p>
            <TextActionForm
              label="Your reply"
              submitLabel="Send reply"
              onSubmit={async (body) => {
                await send("POST", `admin/enquiries/${id}/reply`, { body });
                reload();
              }}
            />
          </section>
          <FollowUpsPanel
            items={d.followUps}
            subject={{ type: "enquiry", id }}
            onChanged={reload}
          />
          <NotesPanel
            title="History"
            notes={d.notes}
            addPath={`admin/enquiries/${id}/notes`}
            onAdded={reload}
          />
        </>
      )}
    </LoadView>
  );
}

export function FollowUpsPage() {
  const { params, set } = useListParams();
  const scope = params.get("scope") ?? "open";
  const [load, reload] = useAdminGet("admin/follow-ups", (d) => pick<FollowUp[]>(d, "items"), {
    scope,
  });
  return (
    <>
      <h1>Follow-ups</h1>
      <hr className="rule" />
      <p>Add follow-ups from a person, organisation or enquiry page.</p>
      <div className="field">
        <label htmlFor="scope">Show</label>
        <select
          id="scope"
          className="input"
          value={scope}
          onChange={(e) => set({ scope: e.target.value })}
        >
          <option value="open">All open</option>
          <option value="mine">Assigned to me</option>
          <option value="done">Done</option>
        </select>
      </div>
      <LoadView load={load} label="follow-ups" retry={reload}>
        {(items) => <FollowUpsPanel items={items} onChanged={reload} showSubject />}
      </LoadView>
    </>
  );
}
