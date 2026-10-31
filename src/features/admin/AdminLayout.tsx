import { type FormEvent, useState } from "react";
import { Navigate, NavLink, Outlet, useNavigate } from "react-router-dom";
import { ROLE_LABEL } from "@/features/account/me";
import { useMe as useMeLoad } from "@/features/account/useMe";
import { useSession } from "@/features/auth/session";
import { Loading } from "@/shared/components/Loading";
import { claim } from "@/shared/content/claims";
import { MeContext } from "./ui";

const NAV: { to: string; label: string; owner?: boolean }[] = [
  { to: "/admin", label: "Today" },
  { to: "/admin/enquiries", label: "Enquiries" },
  { to: "/admin/follow-ups", label: "Follow-ups" },
  { to: "/admin/people", label: "People" },
  { to: "/admin/organisations", label: "Organisations" },
  { to: "/admin/sessions", label: "Sessions" },
  { to: "/admin/certificates", label: "Certificates" },
  { to: "/admin/renewals", label: "Renewals" },
  { to: "/admin/courses", label: "Courses" },
  { to: "/admin/exports", label: "Exports", owner: true },
  { to: "/admin/audit", label: "Audit log", owner: true },
  { to: "/admin/staff", label: "Staff accounts", owner: true },
];

/**
 * The CMS shell. Only signed-in staff with an admin or owner row get past it (the API enforces the same:
 * this screen is convenience, never the control). Everyone else sees why, with no data.
 */
export function AdminLayout() {
  const { status, signOut } = useSession();
  const state = useMeLoad();
  const navigate = useNavigate();
  const [q, setQ] = useState("");

  if (status === "loading")
    return (
      <Shell>
        <Loading label="Checking your sign-in" />
      </Shell>
    );
  if (status === "unconfigured") {
    return (
      <Shell>
        <p className="notice notice--info" role="status">
          <strong>Sign-in is not available yet</strong>
          The office system needs sign-in to be set up first.
        </p>
      </Shell>
    );
  }
  if (status !== "signed-in") return <Navigate to="/sign-in" replace />;
  if (state.kind === "loading")
    return (
      <Shell>
        <Loading label="Loading your account" />
      </Shell>
    );
  if (state.kind === "error") {
    return (
      <Shell>
        <p className="notice notice--error" role="alert">
          <strong>Problem</strong>
          We could not load your account. Check your connection and try again.
        </p>
        <button className="btn" type="button" onClick={state.retry}>
          Try again
        </button>
      </Shell>
    );
  }
  if (state.kind === "no-access") {
    return (
      <Shell>
        <h1>No access</h1>
        <hr className="rule" />
        <p className="notice notice--info" role="status">
          <strong>Your email address does not have access</strong>
          This system is for Vitalcare staff. Learners do not need an account: certificates can be
          checked on the public site. {claim("contact_details")}
        </p>
        <button className="btn btn--quiet" type="button" onClick={() => void signOut()}>
          Sign out
        </button>
      </Shell>
    );
  }

  const me = state.me;
  const owner = me.role === "super_admin";
  function search(e: FormEvent) {
    e.preventDefault();
    const t = q.trim();
    if (t.length >= 2) navigate(`/admin/search?q=${encodeURIComponent(t)}`);
  }

  return (
    <MeContext.Provider value={me}>
      <a className="skip-link" href="#main">
        Skip to the main content
      </a>
      <header className="admin-header">
        <div className="admin-header__inner">
          <NavLink className="brand" to="/admin" aria-label="Vitalcare office, today">
            <img
              src="/logos/logo-horizontal-navy.svg"
              alt="Vitalcare Training Hub"
              width={225}
              height={75}
            />
          </NavLink>
          <form className="admin-search" onSubmit={search}>
            <label className="visually-hidden" htmlFor="admin-search">
              Search people, organisations and certificates
            </label>
            <input
              id="admin-search"
              className="input"
              type="search"
              placeholder="Search names, emails, codes"
              value={q}
              onChange={(e) => setQ(e.target.value)}
            />
            <button className="btn" type="submit">
              Search
            </button>
          </form>
          <div className="admin-user">
            <NavLink to="/admin/account">
              {me.displayName || me.email}
              <span className="visually-hidden">, your account</span>
            </NavLink>
            <span className="hint">{ROLE_LABEL[me.role]}</span>
          </div>
        </div>
        <nav className="admin-nav" aria-label="Office">
          {NAV.filter((n) => !n.owner || owner).map((n) => (
            <NavLink key={n.to} to={n.to} end={n.to === "/admin"}>
              {n.label}
            </NavLink>
          ))}
        </nav>
      </header>
      <main id="main" className="page page--admin" tabIndex={-1}>
        <Outlet />
      </main>
    </MeContext.Provider>
  );
}

function Shell({ children }: { children: React.ReactNode }) {
  return (
    <main id="main" className="page" tabIndex={-1}>
      {children}
    </main>
  );
}
