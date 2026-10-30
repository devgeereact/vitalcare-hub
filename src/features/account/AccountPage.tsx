import { Navigate } from "react-router-dom";
import { useSession } from "@/features/auth/session";
import { Loading } from "@/shared/components/Loading";
import { claim } from "@/shared/content/claims";
import { ROLE_LABEL } from "./me";
import { useMe } from "./useMe";

export function AccountPage() {
  const { status, signOut } = useSession();
  const state = useMe();

  if (status === "loading") return <Loading label="Checking your sign-in" />;
  if (status !== "signed-in") return <Navigate to="/sign-in" replace />;

  return (
    <>
      <h1>Your account</h1>
      <hr className="rule" />
      {state.kind === "loading" && <Loading label="Loading your account" />}

      {state.kind === "error" && (
        <>
          <p className="notice notice--error" role="alert">
            <strong>Problem</strong>
            We could not load your account. Check your connection and try again.
          </p>
          <button className="btn" type="button" onClick={state.retry}>
            Try again
          </button>
        </>
      )}

      {state.kind === "no-access" && (
        <p className="notice notice--info" role="status">
          <strong>Your email address does not have access</strong>
          You signed in, but this email address is not set up on the system. If it should be,
          contact us. {claim("contact_details")}
        </p>
      )}

      {state.kind === "ok" && (
        <dl className="facts">
          <dt>Name</dt>
          <dd>{state.me.displayName || "Not set"}</dd>
          <dt>Email address</dt>
          <dd>{state.me.email}</dd>
          <dt>Role</dt>
          <dd>{ROLE_LABEL[state.me.role]}</dd>
        </dl>
      )}

      <div className="actions">
        <button className="btn btn--quiet" type="button" onClick={() => void signOut()}>
          Sign out
        </button>
      </div>
    </>
  );
}
