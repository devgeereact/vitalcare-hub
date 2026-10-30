import { Link } from "react-router-dom";

export function NotFoundPage() {
  return (
    <>
      <h1>Page not found</h1>
      <hr className="rule" />
      <p>We could not find that page. It may have moved, or the address may be wrong.</p>
      <Link className="btn" to="/">
        Go to the home page
      </Link>
    </>
  );
}
