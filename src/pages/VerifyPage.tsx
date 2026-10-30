import { type FormEvent, useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { Loading } from "../components/Loading";
import { claim } from "../content/claims";
import { ApiError } from "../services/api";
import { cleanCode, formatDate, type VerifyResult, verifyCertificate } from "../services/verify";

type State =
  | { kind: "idle" }
  | { kind: "loading" }
  | { kind: "result"; result: VerifyResult }
  | { kind: "rate-limited" }
  | { kind: "error" };

export function VerifyPage() {
  const { code } = useParams();
  const navigate = useNavigate();
  const [input, setInput] = useState(code ?? "");
  const [state, setState] = useState<State>({ kind: "idle" });

  // A code in the address (the certificate's QR code) is checked straight away.
  useEffect(() => {
    if (!code) {
      setState({ kind: "idle" });
      return;
    }
    const controller = new AbortController();
    setInput(code);
    setState({ kind: "loading" });
    verifyCertificate(code, controller.signal)
      .then((result) => setState({ kind: "result", result }))
      .catch((err) => {
        if (controller.signal.aborted) return;
        if (err instanceof ApiError && err.status === 429)
          return setState({ kind: "rate-limited" });
        console.error("[verify] failed", err);
        setState({ kind: "error" });
      });
    return () => controller.abort();
  }, [code]);

  function submit(event: FormEvent) {
    event.preventDefault();
    const cleaned = cleanCode(input);
    if (cleaned) navigate(`/verify/${cleaned}`);
  }

  return (
    <>
      <h1>Check a certificate</h1>
      <hr className="rule" />
      <p>Enter the code printed on the certificate, or scan its QR code.</p>

      <form onSubmit={submit} noValidate>
        <div className="field">
          <label htmlFor="code">Certificate code</label>
          <p className="hint" id="code-hint">
            Twelve letters and numbers, for example XXXX-XXXX-XXXX. Capitals and hyphens do not
            matter.
          </p>
          <input
            id="code"
            className="input"
            type="text"
            name="code"
            autoComplete="off"
            autoCapitalize="characters"
            spellCheck={false}
            aria-describedby="code-hint"
            value={input}
            onChange={(e) => setInput(e.target.value)}
          />
        </div>
        <button
          className="btn"
          type="submit"
          disabled={cleanCode(input) === "" || state.kind === "loading"}
        >
          Check certificate
        </button>
      </form>

      <div aria-live="polite" style={{ marginTop: "var(--space-5)" }}>
        {state.kind === "loading" && <Loading label="Checking the certificate" />}

        {state.kind === "result" && state.result.status === "not_issued" && (
          <div className="notice notice--warn">
            <strong>Not issued</strong>
            We have no issued certificate with that code. Check the code and try again. If you think
            this is wrong, contact us. {claim("contact_details")}
          </div>
        )}

        {state.kind === "result" && state.result.status !== "not_issued" && (
          <Result result={state.result} />
        )}

        {state.kind === "rate-limited" && (
          <div className="notice notice--error" role="alert">
            <strong>Problem</strong>
            There have been too many checks from your connection. Please wait a few minutes and try
            again.
          </div>
        )}

        {state.kind === "error" && (
          <div className="notice notice--error" role="alert">
            <strong>Problem</strong>
            We could not check that code. Check your connection and try again.
          </div>
        )}
      </div>
    </>
  );
}

function Result({ result }: { result: Extract<VerifyResult, { certificate: unknown }> }) {
  const c = result.certificate;
  const valid = result.status === "valid";
  return (
    <div className={`notice ${valid ? "notice--ok" : "notice--warn"}`}>
      <strong>{valid ? "Valid" : "Expired"}</strong>
      {valid
        ? `This certificate was issued by ${c.issuer} and is in date.`
        : `This certificate was issued by ${c.issuer}. It expired on ${formatDate(c.expiresOn ?? c.completedOn)}.`}
      <dl className="facts" style={{ marginTop: "var(--space-3)" }}>
        <dt>Name</dt>
        <dd>{c.learnerName}</dd>
        <dt>Course</dt>
        <dd>{c.courseTitle}</dd>
        <dt>Completed on</dt>
        <dd>{formatDate(c.completedOn)}</dd>
        {c.hours !== null && (
          <>
            <dt>Hours</dt>
            <dd>{c.hours}</dd>
          </>
        )}
        {valid && (
          <>
            <dt>Valid until</dt>
            <dd>{c.expiresOn ? formatDate(c.expiresOn) : "No expiry date is recorded"}</dd>
          </>
        )}
        <dt>Certificate code</dt>
        <dd>{c.code}</dd>
      </dl>
    </div>
  );
}
