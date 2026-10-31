import { Link, NavLink, Outlet } from "react-router-dom";
import { useSession } from "@/features/auth/session";
import { claim } from "@/shared/content/claims";
import { UpdatePrompt } from "./UpdatePrompt";

/** Shell: skip link, header with the supplied logo, main landmark, footer with the legal entity line.
 * Learners never sign in (D-018); the only sign-in link is the staff one in the footer. */
export function Layout() {
  const { status } = useSession();
  return (
    <>
      <a className="skip-link" href="#main">
        Skip to the main content
      </a>
      <header className="site-header">
        <div className="site-header__inner">
          <NavLink className="brand" to="/" aria-label="Vitalcare Training Hub, home">
            <img
              src="/logos/logo-horizontal-navy.svg"
              alt="Vitalcare Training Hub"
              width={225}
              height={75}
            />
          </NavLink>
          <nav className="nav" aria-label="Main">
            <NavLink to="/" end>
              Home
            </NavLink>
            <NavLink to="/courses">Courses</NavLink>
            <NavLink to="/verify">Check a certificate</NavLink>
            <NavLink to="/contact">Contact</NavLink>
            {status === "signed-in" && <NavLink to="/admin">Office</NavLink>}
          </nav>
        </div>
      </header>
      <UpdatePrompt />
      <main id="main" className="page" tabIndex={-1}>
        <Outlet />
      </main>
      <footer className="site-footer">
        <div className="site-footer__inner">
          <p>{claim("company_identity")}</p>
          <p>{claim("contact_details")}</p>
          {status !== "signed-in" && (
            <p>
              <Link to="/sign-in">Staff sign in</Link>
            </p>
          )}
        </div>
      </footer>
    </>
  );
}
