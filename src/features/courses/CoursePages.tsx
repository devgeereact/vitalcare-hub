import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { Loading } from "@/shared/components/Loading";
import { claim } from "@/shared/content/claims";
import { ApiError } from "@/shared/services/api";
import {
  FORMAT_TEXT,
  fetchCourse,
  fetchCourses,
  type PublicCourse,
  priceText,
  renewalText,
} from "./courses";

type State<T> =
  | { kind: "loading" }
  | { kind: "ok"; data: T }
  | { kind: "not-found" }
  | { kind: "error" };

function useLoad<T>(load: (signal: AbortSignal) => Promise<T>, key: string): State<T> {
  const [state, setState] = useState<State<T>>({ kind: "loading" });
  // biome-ignore lint/correctness/useExhaustiveDependencies: `key` identifies the request; `load` is recreated each render
  useEffect(() => {
    const controller = new AbortController();
    setState({ kind: "loading" });
    load(controller.signal)
      .then((data) => setState({ kind: "ok", data }))
      .catch((err) => {
        if (controller.signal.aborted) return;
        if (err instanceof ApiError && err.status === 404) return setState({ kind: "not-found" });
        console.error("[courses] failed", err);
        setState({ kind: "error" });
      });
    return () => controller.abort();
  }, [key]);
  return state;
}

function Problem() {
  return (
    <p className="notice notice--error" role="alert">
      <strong>Problem</strong>
      We could not load the courses. Check your connection and try again.
    </p>
  );
}

/** Text the owner entered, with their line breaks kept as paragraphs. */
function Paragraphs({ text }: { text: string }) {
  return (
    <>
      {text
        .split(/\n{2,}/)
        .map((p) => p.trim())
        .filter(Boolean)
        .map((p, i) => (
          // biome-ignore lint/suspicious/noArrayIndexKey: paragraphs of fixed text have no other identity
          <p key={i} className="pre-line">
            {p}
          </p>
        ))}
    </>
  );
}

export function CourseListPage() {
  const state = useLoad((s) => fetchCourses(s), "list");
  return (
    <>
      <h1>Courses</h1>
      <hr className="rule" />
      {state.kind === "loading" && <Loading label="Loading courses" />}
      {(state.kind === "error" || state.kind === "not-found") && <Problem />}
      {state.kind === "ok" &&
        (state.data.length === 0 ? (
          <p>
            Our course list is being prepared. To ask about training now,{" "}
            <Link to="/contact">contact us</Link>.
          </p>
        ) : (
          <ul className="course-list">
            {state.data.map((c) => (
              <li key={c.slug} className="card">
                <h2 className="h3">
                  <Link to={`/courses/${c.slug}`}>{c.title}</Link>
                </h2>
                <p className="hint">
                  {[FORMAT_TEXT[c.format], c.durationText, priceText(c)]
                    .filter(Boolean)
                    .join(" · ")}
                </p>
              </li>
            ))}
          </ul>
        ))}
    </>
  );
}

export function CourseDetailPage() {
  const { slug = "" } = useParams();
  const state = useLoad((s) => fetchCourse(slug, s), slug);
  if (state.kind === "loading") return <Loading label="Loading the course" />;
  if (state.kind === "error") return <Problem />;
  if (state.kind === "not-found") {
    return (
      <>
        <h1>Course not found</h1>
        <hr className="rule" />
        <p>
          We could not find that course. See <Link to="/courses">all courses</Link>.
        </p>
      </>
    );
  }
  const c: PublicCourse = state.data;
  return (
    <>
      <p className="crumbs">
        <Link to="/courses">Courses</Link>
      </p>
      <h1>{c.title}</h1>
      <hr className="rule" />
      <Paragraphs text={c.summary} />
      <dl className="facts" style={{ marginTop: "var(--space-5)" }}>
        <dt>Format</dt>
        <dd>{FORMAT_TEXT[c.format]}</dd>
        {c.durationText && (
          <>
            <dt>Duration</dt>
            <dd>{c.durationText}</dd>
          </>
        )}
        <dt>Price</dt>
        <dd>{priceText(c)}</dd>
        {c.assessmentMethod && (
          <>
            <dt>Assessment</dt>
            <dd>{c.assessmentMethod}</dd>
          </>
        )}
        <dt>Renewal</dt>
        <dd>{renewalText(c.renewalMonths)}</dd>
        {c.accreditationNote && (
          <>
            <dt>Accreditation</dt>
            <dd className="pre-line">{c.accreditationNote}</dd>
          </>
        )}
      </dl>
      <div className="actions">
        <Link className="btn" to="/contact">
          Ask about this course
        </Link>
      </div>
      <p className="hint" style={{ marginTop: "var(--space-4)" }}>
        {claim("contact_details")}
      </p>
    </>
  );
}
