import { Navigate } from "react-router-dom";
import { SignInForm } from "../auth/SignInForm";
import { useSession } from "../auth/session";
import { Loading } from "../components/Loading";
import { claim } from "../content/claims";

export function SignInPage() {
  const { status } = useSession();

  if (status === "loading") return <Loading label="Checking your sign-in" />;
  if (status === "signed-in") return <Navigate to="/app" replace />;

  return (
    <>
      <h1>Sign in</h1>
      <hr className="rule" />
      {status === "unconfigured" ? (
        <p className="notice notice--info" role="status">
          <strong>Sign-in is not available yet</strong>
          Please contact us instead. {claim("contact_details")}
        </p>
      ) : (
        <SignInForm />
      )}
    </>
  );
}
