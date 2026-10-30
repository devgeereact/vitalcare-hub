import { Navigate } from "react-router-dom";
import { Loading } from "@/shared/components/Loading";
import { claim } from "@/shared/content/claims";
import { SignInForm } from "./SignInForm";
import { useSession } from "./session";

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
