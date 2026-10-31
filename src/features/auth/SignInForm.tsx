import { useSignIn } from "@clerk/react";
import { type FormEvent, useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";

type Step = "email" | "code";

const SEND_FAILED = "We could not send a code. Check the email address and try again.";
const CODE_WRONG = "That code is not right. Check it and try again.";
const UNREACHABLE = "We could not reach the sign-in service. Check your connection and try again.";

/** Email code sign-in. No password and no social buttons: the instance allows email code only. */
export function SignInForm() {
  const { signIn, fetchStatus } = useSignIn();
  const navigate = useNavigate();
  const [step, setStep] = useState<Step>("email");
  const [email, setEmail] = useState("");
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const field = useRef<HTMLInputElement>(null);
  const busy = fetchStatus === "fetching";

  // Move focus to the field for the new step so keyboard and screen reader users land in the right place.
  // biome-ignore lint/correctness/useExhaustiveDependencies: focus must move when the step changes
  useEffect(() => {
    field.current?.focus();
  }, [step]);

  async function sendCode(event?: FormEvent) {
    event?.preventDefault();
    setError(null);
    try {
      const created = await signIn.create({ identifier: email.trim() });
      if (created.error) return setError(SEND_FAILED);
      const sent = await signIn.emailCode.sendCode();
      if (sent.error) return setError(SEND_FAILED);
      setCode("");
      setStep("code");
    } catch (err) {
      console.error("[sign-in] send code failed", err);
      setError(UNREACHABLE);
    }
  }

  async function verify(event: FormEvent) {
    event.preventDefault();
    setError(null);
    try {
      const result = await signIn.emailCode.verifyCode({ code: code.trim() });
      if (result.error) return setError(CODE_WRONG);
      if (signIn.status !== "complete") return setError(CODE_WRONG);
      await signIn.finalize({
        navigate: ({ decorateUrl }) => {
          const url = decorateUrl("/admin");
          if (url.startsWith("http")) window.location.href = url;
          else navigate(url);
        },
      });
    } catch (err) {
      console.error("[sign-in] verify failed", err);
      setError(UNREACHABLE);
    }
  }

  function startAgain() {
    setError(null);
    setCode("");
    setStep("email");
  }

  return (
    <div className="card">
      {error && (
        <p className="notice notice--error" role="alert">
          <strong>Problem</strong>
          {error}
        </p>
      )}

      {step === "email" ? (
        <form onSubmit={sendCode} noValidate>
          <div className="field">
            <label htmlFor="email">Email address</label>
            <p className="hint" id="email-hint">
              We will send a code to this address. There is no password.
            </p>
            <input
              id="email"
              ref={field}
              className="input"
              type="email"
              name="email"
              autoComplete="email"
              inputMode="email"
              aria-describedby="email-hint"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
          </div>
          <button className="btn" type="submit" disabled={busy || email.trim() === ""}>
            Send code
          </button>
        </form>
      ) : (
        <form onSubmit={verify} noValidate>
          <div className="field">
            <label htmlFor="code">Code</label>
            <p className="hint" id="code-hint">
              Enter the code we sent to {email.trim()}.
            </p>
            <input
              id="code"
              ref={field}
              className="input"
              type="text"
              name="code"
              autoComplete="one-time-code"
              inputMode="numeric"
              aria-describedby="code-hint"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              required
            />
          </div>
          <div className="actions">
            <button className="btn" type="submit" disabled={busy || code.trim() === ""}>
              Sign in
            </button>
            <button
              className="btn btn--quiet"
              type="button"
              onClick={() => void sendCode()}
              disabled={busy}
            >
              Send a new code
            </button>
            <button className="btn btn--quiet" type="button" onClick={startAgain}>
              Use a different email address
            </button>
          </div>
        </form>
      )}
    </div>
  );
}
