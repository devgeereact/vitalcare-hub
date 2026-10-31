import { Link, useSearchParams } from "react-router-dom";
import { dateTime, shortDate } from "../format";
import { FollowUpsPanel } from "../Panels";
import type { Certificate, Enquiry, FollowUp, Renewal, TrainingSession } from "../types";
import { LoadView, StateBadge, useMe } from "../ui";
import { useAdminGet } from "../useAdmin";

type Dashboard = {
  today: string;
  followUps: { overdue: number; dueToday: number; items: FollowUp[] };
  certificates: { expiring30: number; expired90: number; items: Renewal[] };
  enquiries: { new: number; items: Enquiry[] };
  sessions: { items: TrainingSession[] };
};

function parseDashboard(d: unknown): Dashboard {
  const x = d as Partial<Dashboard> | null;
  if (
    !x?.followUps ||
    !x.certificates ||
    !x.enquiries ||
    !x.sessions ||
    typeof x.today !== "string"
  ) {
    throw new Error("Unexpected response from the server");
  }
  return x as Dashboard;
}

/** What matters today: follow-ups due, certificates running out, new enquiries, the next sessions. */
export function DashboardPage() {
  const me = useMe();
  const [load, reload] = useAdminGet("admin/dashboard", parseDashboard);
  return (
    <>
      <h1>Today</h1>
      <hr className="rule" />
      <p>Hello {me.displayName || me.email}.</p>
      <LoadView load={load} label="today's summary" retry={reload}>
        {(d) => (
          <>
            <ul className="stats" aria-label="Summary">
              <li>
                <Link to="/admin/follow-ups">
                  <strong>{d.followUps.overdue}</strong> follow-ups overdue
                </Link>
              </li>
              <li>
                <Link to="/admin/follow-ups">
                  <strong>{d.followUps.dueToday}</strong> due today
                </Link>
              </li>
              <li>
                <Link to="/admin/enquiries?status=new">
                  <strong>{d.enquiries.new}</strong> new enquiries
                </Link>
              </li>
              <li>
                <Link to="/admin/renewals?window=30">
                  <strong>{d.certificates.expiring30}</strong> certificates expire within 30 days
                </Link>
              </li>
              <li>
                <Link to="/admin/renewals?window=expired">
                  <strong>{d.certificates.expired90}</strong> expired in the last 90 days
                </Link>
              </li>
            </ul>

            <FollowUpsPanel items={d.followUps.items} onChanged={reload} showSubject />

            <section className="panel" aria-labelledby="enq-h">
              <h2 id="enq-h">New enquiries</h2>
              {d.enquiries.items.length === 0 ? (
                <p className="hint">No new enquiries.</p>
              ) : (
                <ul className="cards">
                  {d.enquiries.items.map((e) => (
                    <li key={e.id}>
                      <Link to={`/admin/enquiries/${e.id}`}>{e.name}</Link>
                      <span className="hint"> {dateTime(e.createdAt)}</span>
                      <p>{e.message}</p>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section className="panel" aria-labelledby="exp-h">
              <h2 id="exp-h">Expiring soon</h2>
              {d.certificates.items.length === 0 ? (
                <p className="hint">No certificates expire in the next 30 days.</p>
              ) : (
                <RenewalTable
                  items={d.certificates.items}
                  caption="Certificates expiring within 30 days"
                />
              )}
            </section>

            <section className="panel" aria-labelledby="ses-h">
              <h2 id="ses-h">Sessions in the next two weeks</h2>
              {d.sessions.items.length === 0 ? (
                <p className="hint">No sessions scheduled.</p>
              ) : (
                <div className="table-wrap">
                  <table>
                    <caption className="visually-hidden">Upcoming sessions</caption>
                    <thead>
                      <tr>
                        <th scope="col">When</th>
                        <th scope="col">Course</th>
                        <th scope="col">Where</th>
                        <th scope="col">Booked</th>
                      </tr>
                    </thead>
                    <tbody>
                      {d.sessions.items.map((s) => (
                        <tr key={s.id}>
                          <td>
                            <Link to={`/admin/sessions/${s.id}`}>{dateTime(s.startsAt)}</Link>
                          </td>
                          <td>{s.courseTitle}</td>
                          <td>{s.venue ?? "Online"}</td>
                          <td>
                            {s.booked} of {s.capacity}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>
          </>
        )}
      </LoadView>
    </>
  );
}

export function RenewalTable({ items, caption }: { items: Renewal[]; caption: string }) {
  return (
    <div className="table-wrap">
      <table>
        <caption className="visually-hidden">{caption}</caption>
        <thead>
          <tr>
            <th scope="col">Person</th>
            <th scope="col">Organisation</th>
            <th scope="col">Course</th>
            <th scope="col">Valid until</th>
            <th scope="col">Reminders sent</th>
          </tr>
        </thead>
        <tbody>
          {items.map((r) => (
            <tr key={r.certificateId}>
              <td>
                <Link to={`/admin/people/${r.personId}`}>{r.personName}</Link>
                {!r.hasEmail && <span className="hint"> (no email)</span>}
              </td>
              <td>
                {r.organisationId ? (
                  <Link to={`/admin/organisations/${r.organisationId}`}>{r.organisationName}</Link>
                ) : (
                  ""
                )}
              </td>
              <td>
                <Link to={`/admin/certificates/${r.certificateId}`}>{r.courseTitle}</Link>
              </td>
              <td>
                {shortDate(r.expiresOn)}{" "}
                <span className="hint">
                  {r.daysLeft >= 0 ? `(${r.daysLeft} days)` : `(${-r.daysLeft} days ago)`}
                </span>
              </td>
              <td>
                {r.remindersSent.length
                  ? r.remindersSent.map((s) => `${s} days`).join(", ")
                  : "None"}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

type SearchResult = {
  people: {
    id: string;
    name: string;
    email: string | null;
    organisationName: string | null;
    status: string;
  }[];
  organisations: { id: string; name: string }[];
  certificates: Certificate[];
};

export function SearchPage() {
  const [params] = useSearchParams();
  const q = params.get("q") ?? "";
  const [load, reload] = useAdminGet(
    q.trim().length >= 2 ? "admin/search" : null,
    (d) => {
      const x = d as Partial<SearchResult>;
      if (
        !Array.isArray(x.people) ||
        !Array.isArray(x.organisations) ||
        !Array.isArray(x.certificates)
      ) {
        throw new Error("Unexpected response from the server");
      }
      return x as SearchResult;
    },
    { q },
  );
  return (
    <>
      <h1>Search</h1>
      <hr className="rule" />
      {q.trim().length < 2 ? (
        <p>Type at least two characters in the search box.</p>
      ) : (
        <LoadView load={load} label="results" retry={reload}>
          {(r) => (
            <div aria-live="polite">
              <p>
                Results for <strong>{q}</strong>
              </p>
              <h2>People</h2>
              {r.people.length === 0 ? (
                <p className="hint">No people found.</p>
              ) : (
                <ul>
                  {r.people.map((p) => (
                    <li key={p.id}>
                      <Link to={`/admin/people/${p.id}`}>{p.name}</Link>
                      <span className="hint">
                        {" "}
                        {[p.email, p.organisationName, p.status === "archived" ? "archived" : ""]
                          .filter(Boolean)
                          .join(", ")}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
              <h2>Organisations</h2>
              {r.organisations.length === 0 ? (
                <p className="hint">No organisations found.</p>
              ) : (
                <ul>
                  {r.organisations.map((o) => (
                    <li key={o.id}>
                      <Link to={`/admin/organisations/${o.id}`}>{o.name}</Link>
                    </li>
                  ))}
                </ul>
              )}
              <h2>Certificates</h2>
              {r.certificates.length === 0 ? (
                <p className="hint">No certificates found.</p>
              ) : (
                <ul>
                  {r.certificates.map((c) => (
                    <li key={c.id}>
                      <Link to={`/admin/certificates/${c.id}`}>
                        {c.code}, {c.personName}, {c.courseTitle}
                      </Link>{" "}
                      <StateBadge state={c.state} />
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </LoadView>
      )}
    </>
  );
}
