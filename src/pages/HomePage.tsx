import { Link } from "react-router-dom";
import { APP_NAME } from "../config";
import { claim } from "../content/claims";

export function HomePage() {
  return (
    <>
      <h1>{APP_NAME}</h1>
      <hr className="rule" />
      <p>Care training with a record you can check.</p>
      <p>
        This site is being rebuilt. Courses and certificate checking will be added here as they are
        ready. To book training or ask a question now, contact us.
      </p>
      <p>{claim("contact_details")}</p>
      <div className="actions">
        <Link className="btn" to="/sign-in">
          Sign in
        </Link>
      </div>
    </>
  );
}
