import { Link } from "react-router-dom";
import { APP_NAME } from "@/shared/config";
import { claim } from "@/shared/content/claims";

export function HomePage() {
  return (
    <>
      <h1>{APP_NAME}</h1>
      <hr className="rule" />
      <p>Care training with a record you can check.</p>
      <p>
        See our courses, check a certificate by its code, or contact us to book training or ask a
        question. You do not need an account.
      </p>
      <p>{claim("contact_details")}</p>
      <div className="actions">
        <Link className="btn" to="/courses">
          Courses
        </Link>
        <Link className="btn btn--quiet" to="/verify">
          Check a certificate
        </Link>
        <Link className="btn btn--quiet" to="/contact">
          Contact us
        </Link>
      </div>
    </>
  );
}
