import { Link, useNavigate, useParams } from "react-router-dom";
import { FORMAT_LABEL, money } from "../format";
import { asPage, type Course, pick } from "../types";
import { Badge, type FieldDef, isOwner, LoadView, RecordForm, toFormValues, useMe } from "../ui";
import { useAdminGet, useAdminSend } from "../useAdmin";
import { AddBox, ListSearch, useListParams } from "./People";

/** Every course fact is shown on the public site exactly as entered here (D-018). */
export const courseFields: FieldDef[] = [
  { key: "title", label: "Title", required: true },
  {
    key: "slug",
    label: "Web address",
    required: true,
    hint: "Lowercase words joined by hyphens. The page will be vitalcare.uk/courses/ followed by this.",
  },
  {
    key: "summary",
    label: "Summary",
    type: "textarea",
    required: true,
    hint: "What the course covers and who it is for. Plain facts only: CQC does not approve training.",
  },
  {
    key: "durationText",
    label: "Duration",
    hint: "For example: Half a day, or 3 hours.",
    keepEmpty: true,
  },
  { key: "hours", label: "Hours (printed on the certificate)", type: "number", step: "0.25" },
  {
    key: "format",
    label: "Format",
    type: "select",
    required: true,
    options: Object.entries(FORMAT_LABEL),
  },
  {
    key: "pricePence",
    label: "Price in pounds",
    type: "money",
    hint: 'For example 45.00. Leave empty to say "price on request".',
  },
  { key: "priceNote", label: "Price note", hint: "For example: per person.", keepEmpty: true },
  { key: "assessmentMethod", label: "How it is assessed", keepEmpty: true },
  {
    key: "renewalMonths",
    label: "Renewal period in months",
    type: "number",
    hint: "Certificates expire this many months after the training date. Leave empty for no expiry.",
  },
  {
    key: "accreditationNote",
    label: "Accreditation note",
    type: "textarea",
    keepEmpty: true,
    hint: "Only state what you can prove, and keep the proof. Leave empty if there is none.",
  },
  {
    key: "sortOrder",
    label: "Order in the list",
    type: "number",
    hint: "Lower numbers come first.",
  },
  { key: "published", label: "Published on the public site", type: "checkbox" },
];

export function CoursesPage() {
  const me = useMe();
  const { q, page, set } = useListParams();
  const [load, reload] = useAdminGet("admin/courses", (d) => asPage<Course>(d), { q, page });
  const send = useAdminSend();
  const navigate = useNavigate();
  return (
    <>
      <h1>Courses</h1>
      <hr className="rule" />
      <p>Published courses appear on the public course pages with the facts entered here.</p>
      {isOwner(me) ? (
        <AddBox summary="Add a course">
          <RecordForm
            fields={courseFields}
            initial={toFormValues(courseFields, {
              format: "in_person",
              sortOrder: 0,
              published: false,
            })}
            submitLabel="Add course"
            onSubmit={async (body) => {
              const r = await send("POST", "admin/courses", body);
              navigate(`/admin/courses/${pick<Course>(r, "course").id}`);
            }}
          />
        </AddBox>
      ) : (
        <p className="notice notice--info">
          <strong>Owner only</strong>
          Course facts are public statements, so only the owner can add or change them.
        </p>
      )}
      <ListSearch label="Search courses" value={q} onSearch={(v) => set({ q: v })} />
      <LoadView load={load} label="courses" retry={reload}>
        {(p) =>
          p.items.length === 0 ? (
            <p>No courses yet.</p>
          ) : (
            <div className="table-wrap">
              <table>
                <caption className="visually-hidden">Courses</caption>
                <thead>
                  <tr>
                    <th scope="col">Title</th>
                    <th scope="col">Public</th>
                    <th scope="col">Format</th>
                    <th scope="col">Price</th>
                    <th scope="col">Renewal</th>
                  </tr>
                </thead>
                <tbody>
                  {p.items.map((c) => (
                    <tr key={c.id}>
                      <td>
                        <Link to={`/admin/courses/${c.id}`}>{c.title}</Link>
                      </td>
                      <td>
                        {c.published ? (
                          <Badge tone="ok">Published</Badge>
                        ) : (
                          <Badge tone="info">Draft</Badge>
                        )}
                      </td>
                      <td>{FORMAT_LABEL[c.format]}</td>
                      <td>{c.pricePence !== null ? money(c.pricePence) : "On request"}</td>
                      <td>{c.renewalMonths ? `${c.renewalMonths} months` : "No expiry"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )
        }
      </LoadView>
    </>
  );
}

export function CoursePage() {
  const me = useMe();
  const { id = "" } = useParams();
  const [load, reload] = useAdminGet(`admin/courses/${id}`, (d) => pick<Course>(d, "course"));
  const send = useAdminSend();
  return (
    <LoadView load={load} label="this course" retry={reload}>
      {(c) => (
        <>
          <p className="crumbs">
            <Link to="/admin/courses">Courses</Link>
          </p>
          <h1>{c.title}</h1>
          <hr className="rule" />
          <p>
            {c.published ? (
              <>
                <Badge tone="ok">Published</Badge>{" "}
                <a href={`/courses/${c.slug}`}>View the public page</a>
              </>
            ) : (
              <Badge tone="info">Draft: not on the public site</Badge>
            )}
          </p>
          {!isOwner(me) && (
            <p className="notice notice--info">
              <strong>Read only</strong>
              Only the owner can change course facts.
            </p>
          )}
          <RecordForm
            key={`${c.id}-${c.published}`}
            fields={courseFields}
            initial={toFormValues(courseFields, c)}
            readOnly={!isOwner(me)}
            submitLabel="Save course"
            onSubmit={async (body) => {
              await send("PATCH", `admin/courses/${id}`, body);
              reload();
            }}
          />
        </>
      )}
    </LoadView>
  );
}
