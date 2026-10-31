import { useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { ApiError } from "@/shared/services/api";
import { ATTENDANCE_LABEL, dateTime, personName, RESULT_LABEL } from "../format";
import {
  type Attendee,
  asPage,
  type Course,
  type Person,
  pick,
  type TrainingSession,
} from "../types";
import { Badge, type FieldDef, LoadView, Pager, RecordForm, toFormValues } from "../ui";
import { useAdminGet, useAdminSend } from "../useAdmin";
import { AddBox, useListParams } from "./People";

function useCourseOptions() {
  const [load] = useAdminGet("admin/courses", (d) => asPage<Course>(d).items, { page: 1 });
  return load.kind === "ok" ? load.data.map((c) => [c.id, c.title] as const) : [];
}

function sessionFields(courses: readonly (readonly [string, string])[]): FieldDef[] {
  return [
    { key: "courseId", label: "Course", type: "select", required: true, options: courses },
    { key: "startsAt", label: "Starts (UK time)", type: "datetime-local", required: true },
    { key: "endsAt", label: "Ends (UK time)", type: "datetime-local", required: true },
    { key: "venue", label: "Venue", hint: "Enter a venue, an online link, or both." },
    {
      key: "onlineLink",
      label: "Online link",
      type: "url",
      hint: "Starts with https://. Only staff see it here.",
    },
    { key: "trainerName", label: "Trainer", keepEmpty: true },
    { key: "capacity", label: "Places", type: "number", required: true },
    {
      key: "status",
      label: "Status",
      type: "select",
      required: true,
      options: [
        ["scheduled", "Scheduled"],
        ["completed", "Completed"],
        ["cancelled", "Cancelled"],
      ],
    },
  ];
}

const STATUS_TONE = { scheduled: "info", completed: "ok", cancelled: "error" } as const;

export function SessionsPage() {
  const { params, page, set } = useListParams();
  const when = params.get("when") ?? "upcoming";
  const [load, reload] = useAdminGet("admin/sessions", (d) => asPage<TrainingSession>(d), {
    when,
    page,
  });
  const courses = useCourseOptions();
  const fields = sessionFields(courses);
  const send = useAdminSend();
  const navigate = useNavigate();
  return (
    <>
      <h1>Sessions</h1>
      <hr className="rule" />
      <AddBox summary="Schedule a session">
        {courses.length === 0 ? (
          <p className="hint">Add a course first.</p>
        ) : (
          <RecordForm
            key={courses.length}
            fields={fields}
            initial={toFormValues(fields, {
              capacity: 12,
              status: "scheduled",
              courseId: courses[0]?.[0],
            })}
            submitLabel="Schedule session"
            onSubmit={async (body) => {
              const r = await send("POST", "admin/sessions", body);
              navigate(`/admin/sessions/${pick<TrainingSession>(r, "session").id}`);
            }}
          />
        )}
      </AddBox>
      <div className="field">
        <label htmlFor="when">Show</label>
        <select
          id="when"
          className="input"
          value={when}
          onChange={(e) => set({ when: e.target.value })}
        >
          <option value="upcoming">Upcoming</option>
          <option value="past">Past</option>
          <option value="all">All</option>
        </select>
      </div>
      <LoadView load={load} label="sessions" retry={reload}>
        {(p) =>
          p.items.length === 0 ? (
            <p>No sessions.</p>
          ) : (
            <>
              <div className="table-wrap">
                <table>
                  <caption className="visually-hidden">Sessions</caption>
                  <thead>
                    <tr>
                      <th scope="col">When</th>
                      <th scope="col">Course</th>
                      <th scope="col">Where</th>
                      <th scope="col">Booked</th>
                      <th scope="col">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {p.items.map((s) => (
                      <tr key={s.id}>
                        <td>
                          <Link to={`/admin/sessions/${s.id}`}>{dateTime(s.startsAt)}</Link>
                        </td>
                        <td>{s.courseTitle}</td>
                        <td>{s.venue ?? "Online"}</td>
                        <td>
                          {s.booked} of {s.capacity}
                        </td>
                        <td>
                          <Badge tone={STATUS_TONE[s.status]}>
                            {s.status[0]?.toUpperCase() + s.status.slice(1)}
                          </Badge>
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

type SessionDetail = { session: TrainingSession; attendees: Attendee[] };

export function SessionPage() {
  const { id = "" } = useParams();
  const [load, reload] = useAdminGet(`admin/sessions/${id}`, (d) => {
    pick(d, "session");
    pick(d, "attendees");
    return d as SessionDetail;
  });
  const courses = useCourseOptions();
  const fields = sessionFields(courses);
  const send = useAdminSend();
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);

  async function act(fn: () => Promise<unknown>, ok: string) {
    setMessage(null);
    try {
      await fn();
      setMessage({ tone: "ok", text: ok });
      reload();
    } catch (err) {
      setMessage({
        tone: "error",
        text: err instanceof ApiError ? err.message : "That did not work. Try again.",
      });
    }
  }

  return (
    <LoadView load={load} label="this session" retry={reload}>
      {(d) => (
        <>
          <p className="crumbs">
            <Link to="/admin/sessions">Sessions</Link>
          </p>
          <h1>{d.session.courseTitle}</h1>
          <hr className="rule" />
          <p>
            {dateTime(d.session.startsAt)} to {d.session.endsAt.split("T")[1]},{" "}
            {d.session.venue ?? "online"}. {d.session.booked} of {d.session.capacity} places booked.{" "}
            <Badge tone={STATUS_TONE[d.session.status]}>{d.session.status}</Badge>
          </p>
          {d.session.onlineLink && (
            <p>
              Online link: <a href={d.session.onlineLink}>{d.session.onlineLink}</a>
            </p>
          )}
          <div aria-live="polite">
            {message && (
              <p
                className={`notice notice--${message.tone}`}
                role={message.tone === "error" ? "alert" : "status"}
              >
                <strong>{message.tone === "ok" ? "Done" : "Problem"}</strong>
                {message.text}
              </p>
            )}
          </div>

          <section className="panel" aria-labelledby="att-h">
            <h2 id="att-h">Attendance</h2>
            {d.attendees.length === 0 ? (
              <p className="hint">Nobody is booked yet.</p>
            ) : (
              <div className="table-wrap">
                <table>
                  <caption className="visually-hidden">Attendees and results</caption>
                  <thead>
                    <tr>
                      <th scope="col">Person</th>
                      <th scope="col">Attendance</th>
                      <th scope="col">Result</th>
                      <th scope="col">Certificate</th>
                    </tr>
                  </thead>
                  <tbody>
                    {d.attendees.map((a) => (
                      <tr key={a.id}>
                        <td>
                          <Link to={`/admin/people/${a.personId}`}>{a.personName}</Link>
                          {a.organisationName && (
                            <span className="hint"> {a.organisationName}</span>
                          )}
                        </td>
                        <td>
                          <label className="visually-hidden" htmlFor={`att-${a.id}`}>
                            Attendance for {a.personName}
                          </label>
                          <select
                            id={`att-${a.id}`}
                            className="input input--compact"
                            value={a.attendance}
                            disabled={!!a.certificateId}
                            onChange={(e) =>
                              void act(
                                () =>
                                  send("PATCH", `admin/attendees/${a.id}`, {
                                    attendance: e.target.value,
                                    ...(e.target.value !== "attended" ? { result: null } : {}),
                                  }),
                                `Attendance saved for ${a.personName}.`,
                              )
                            }
                          >
                            {Object.entries(ATTENDANCE_LABEL).map(([v, l]) => (
                              <option key={v} value={v}>
                                {l}
                              </option>
                            ))}
                          </select>
                        </td>
                        <td>
                          <label className="visually-hidden" htmlFor={`res-${a.id}`}>
                            Result for {a.personName}
                          </label>
                          <select
                            id={`res-${a.id}`}
                            className="input input--compact"
                            value={a.result ?? ""}
                            disabled={a.attendance !== "attended" || !!a.certificateId}
                            onChange={(e) =>
                              void act(
                                () =>
                                  send("PATCH", `admin/attendees/${a.id}`, {
                                    result: e.target.value || null,
                                  }),
                                `Result saved for ${a.personName}.`,
                              )
                            }
                          >
                            <option value="">Not recorded</option>
                            {Object.entries(RESULT_LABEL).map(([v, l]) => (
                              <option key={v} value={v}>
                                {l}
                              </option>
                            ))}
                          </select>
                        </td>
                        <td>
                          {a.certificateId ? (
                            <Link to={`/admin/certificates/${a.certificateId}`}>
                              {a.certificateCode}
                            </Link>
                          ) : a.attendance === "attended" &&
                            (a.result === "pass" || a.result === "not_assessed") ? (
                            <button
                              className="btn btn--small"
                              type="button"
                              onClick={() =>
                                void act(
                                  () => send("POST", "admin/certificates", { attendeeId: a.id }),
                                  `Certificate issued for ${a.personName}.`,
                                )
                              }
                            >
                              Issue certificate
                              <span className="visually-hidden"> for {a.personName}</span>
                            </button>
                          ) : (
                            <span className="hint">Needs attendance and a pass</span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <BookPerson
              onBook={(personId) =>
                act(() => send("POST", `admin/sessions/${id}/attendees`, { personId }), "Booked.")
              }
            />
          </section>

          <section className="panel" aria-labelledby="sd-h">
            <h2 id="sd-h">Session details</h2>
            <RecordForm
              key={`${d.session.id}-${courses.length}-${d.session.status}`}
              fields={fields}
              initial={toFormValues(fields, d.session)}
              submitLabel="Save session"
              onSubmit={async (body) => {
                await send("PATCH", `admin/sessions/${id}`, body);
                reload();
              }}
            />
          </section>
        </>
      )}
    </LoadView>
  );
}

/** Find a person by name or email and book them. */
function BookPerson({ onBook }: { onBook: (personId: string) => Promise<void> }) {
  const [q, setQ] = useState("");
  const [query, setQuery] = useState("");
  const [load] = useAdminGet(
    query.length >= 2 ? "admin/people" : null,
    (d) => asPage<Person>(d).items,
    { q: query, status: "active" },
  );
  return (
    <AddBox summary="Book a person">
      <form
        className="list-search"
        onSubmit={(e) => {
          e.preventDefault();
          setQuery(q.trim());
        }}
      >
        <label htmlFor="book-q">Find a person by name or email</label>
        <div className="inline">
          <input
            id="book-q"
            className="input"
            type="search"
            value={q}
            onChange={(e) => setQ(e.target.value)}
          />
          <button className="btn" type="submit">
            Find
          </button>
        </div>
      </form>
      {query.length >= 2 && (
        <LoadView load={load} label="people">
          {(people) =>
            people.length === 0 ? (
              <p className="hint">
                Nobody found. <Link to="/admin/people">Add the person first</Link>.
              </p>
            ) : (
              <ul className="plain">
                {people.map((p) => (
                  <li key={p.id}>
                    {personName(p)}
                    {p.organisationName && <span className="hint"> {p.organisationName}</span>}{" "}
                    <button
                      className="btn btn--small"
                      type="button"
                      onClick={() => void onBook(p.id)}
                    >
                      Book<span className="visually-hidden"> {personName(p)}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )
          }
        </LoadView>
      )}
    </AddBox>
  );
}
