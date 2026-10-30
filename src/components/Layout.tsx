import { NavLink, Outlet } from "react-router-dom";
import { useSession } from "../auth/session";
import { claim } from "../content/claims";
import { UpdatePrompt } from "./UpdatePrompt";

/** Shell: skip link, header with the supplied logo, main landmark, footer with the legal entity line. */
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
            <NavLink to="/verify">Check a certificate</NavLink>
            <NavLink to="/contact">Contact</NavLink>
            {status === "signed-in" ? (
              <NavLink to="/app">Your account</NavLink>
            ) : (
              <NavLink to="/sign-in">Sign in</NavLink>
            )}
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
        </div>
      </footer>
    </>
  );
}
