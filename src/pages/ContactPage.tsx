import { type FormEvent, useState } from "react";
import { TurnstileWidget } from "../components/TurnstileWidget";
import { TURNSTILE_SITE_KEY } from "../config";
import { claim } from "../content/claims";
import { ApiError } from "../services/api";
import { ContactValidationError, submitContact } from "../services/contact";

type Fields = { name: string; email: string; phone: string; message: string };
type Status = "idle" | "sending" | "sent";

const GENERAL_ERROR =
  "We could not send your message. Check your connection and try again, or contact us directly.";

export function ContactPage() {
  const [values, setValues] = useState<Fields>({ name: "", email: "", phone: "", message: "" });
  const [honeypot, setHoneypot] = useState("");
  const [token, setToken] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [status, setStatus] = useState<Status>("idle");

  const set = (key: keyof Fields) => (e: { target: { value: string } }) =>
    setValues((v) => ({ ...v, [key]: e.target.value }));

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!token) return;
    setStatus("sending");
    setErrors({});
    setProblem(null);
    try {
      await submitContact({ ...values, turnstileToken: token, website: honeypot });
      setStatus("sent");
    } catch (err) {
      setStatus("idle");
      setToken(null); // a Turnstile token is single use
      if (err instanceof ContactValidationError) {
        setErrors(err.fields);
        setProblem("Check the highlighted fields and try again.");
      } else if (err instanceof ApiError && err.status === 429) {
        setProblem(
          "You have sent several messages recently. Please wait a while, or contact us directly.",
        );
      } else if (err instanceof ApiError && err.status === 403) {
        setProblem("We could not check that you are a person. Please try again.");
      } else {
        console.error("[contact] failed", err);
        setProblem(GENERAL_ERROR);
      }
    }
  }

  if (status === "sent") {
    return (
      <>
        <h1>Contact us</h1>
        <hr className="rule" />
        <p className="notice notice--ok" role="status">
          <strong>Message sent</strong>
          Thank you. We have received your message.
        </p>
        <p>{claim("contact_details")}</p>
      </>
    );
  }

  return (
    <>
      <h1>Contact us</h1>
      <hr className="rule" />
      <p>{claim("contact_details")}</p>

      {!TURNSTILE_SITE_KEY ? (
        <p className="notice notice--info" role="status">
          <strong>The contact form is not available yet</strong>
          Please email or telephone us instead, using the details above.
        </p>
      ) : (
        <form onSubmit={submit} noValidate style={{ marginTop: "var(--space-5)" }}>
          {problem && (
            <p className="notice notice--error" role="alert">
              <strong>Problem</strong>
              {problem}
            </p>
          )}

          <Field id="name" label="Your name" error={errors.name}>
            <input
              id="name"
              className={cls(errors.name)}
              name="name"
              autoComplete="name"
              value={values.name}
              onChange={set("name")}
              aria-invalid={!!errors.name}
              aria-describedby={errors.name ? "name-error" : undefined}
              required
            />
          </Field>
          <Field id="email" label="Email address" error={errors.email}>
            <input
              id="email"
              className={cls(errors.email)}
              type="email"
              name="email"
              autoComplete="email"
              inputMode="email"
              value={values.email}
              onChange={set("email")}
              aria-invalid={!!errors.email}
              aria-describedby={errors.email ? "email-error" : undefined}
              required
            />
          </Field>
          <Field id="phone" label="Telephone (optional)" error={errors.phone}>
            <input
              id="phone"
              className={cls(errors.phone)}
              type="tel"
              name="phone"
              autoComplete="tel"
              inputMode="tel"
              value={values.phone}
              onChange={set("phone")}
              aria-invalid={!!errors.phone}
              aria-describedby={errors.phone ? "phone-error" : undefined}
            />
          </Field>
          <Field id="message" label="Your message" error={errors.message}>
            <textarea
              id="message"
              className={`${cls(errors.message)} `}
              name="message"
              value={values.message}
              onChange={set("message")}
              aria-invalid={!!errors.message}
              aria-describedby={errors.message ? "message-error" : undefined}
              required
            />
          </Field>

          {/* Honeypot: hidden from people and from assistive technology. */}
          <div className="hp" aria-hidden="true">
            <label htmlFor="website">Leave this field empty</label>
            <input
              id="website"
              name="website"
              tabIndex={-1}
              autoComplete="off"
              value={honeypot}
              onChange={(e) => setHoneypot(e.target.value)}
            />
          </div>

          <div className="field">
            <TurnstileWidget siteKey={TURNSTILE_SITE_KEY} onToken={setToken} />
          </div>

          <button className="btn" type="submit" disabled={status === "sending" || !token}>
            {status === "sending" ? "Sending" : "Send message"}
          </button>
          {!token && (
            <p className="hint" style={{ marginTop: "var(--space-2)" }}>
              Complete the check above to send your message.
            </p>
          )}
        </form>
      )}
    </>
  );
}

const cls = (error?: string) => `input${error ? " input--error" : ""}`;

function Field({
  id,
  label,
  error,
  children,
}: {
  id: string;
  label: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      {children}
      {error && (
        <p className="field-error" id={`${id}-error`}>
          {error}
        </p>
      )}
    </div>
  );
}
