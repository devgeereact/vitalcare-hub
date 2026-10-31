import { Link, Navigate } from "react-router-dom";
import { Loading } from "@/shared/components/Loading";
import { claim } from "@/shared/content/claims";
import { SignInForm } from "./SignInForm";
import { useSession } from "./session";

export function SignInPage() {
  const { status } = useSession();

  if (status === "loading") return <Loading label="Checking your sign-in" />;
  if (status === "signed-in") return <Navigate to="/admin" replace />;

  return (
    <>
      <h1>Staff sign in</h1>
      <hr className="rule" />
      <p>
        For Vitalcare office staff only. Learners do not need an account: you can{" "}
        <Link to="/verify">check a certificate</Link> without signing in.
      </p>
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
