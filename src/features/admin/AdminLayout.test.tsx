// @vitest-environment jsdom
import { render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { type Session, SessionContext } from "@/features/auth/session";
import { AdminLayout } from "./AdminLayout";

function renderAdmin(session: Partial<Session> = {}) {
  const value: Session = {
    status: "signed-in",
    getToken: async () => "tok-aaaaaaaaaaaaaaaaaaaaaaaa",
    signOut: vi.fn(async () => {}),
    ...session,
  };
  render(
    <SessionContext.Provider value={value}>
      <MemoryRouter initialEntries={["/admin"]}>
        <Routes>
          <Route path="/admin" element={<AdminLayout />}>
            <Route index element={<p>Dashboard content</p>} />
          </Route>
          <Route path="/sign-in" element={<p>Sign in page</p>} />
        </Routes>
      </MemoryRouter>
    </SessionContext.Provider>,
  );
}

const me = (role: string) => ({
  user: {
    id: "u1",
    email: "office@example.com",
    displayName: "Office User",
    role,
    organisationId: null,
  },
});

describe("AdminLayout (the office gate)", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("sends a signed-out visitor to sign in without calling the API", async () => {
    const f = vi.fn();
    vi.stubGlobal("fetch", f);
    renderAdmin({ status: "signed-out" });
    expect(await screen.findByText("Sign in page")).toBeInTheDocument();
    expect(f).not.toHaveBeenCalled();
  });

  it("shows no access, and no office content, for an email without a staff account", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue(
          Response.json({ error: { code: "no_access", message: "no" } }, { status: 403 }),
        ),
    );
    renderAdmin();
    expect(await screen.findByText("Your email address does not have access")).toBeInTheDocument();
    expect(screen.getByText(/Learners do not need an account/)).toBeInTheDocument();
    expect(screen.queryByText("Dashboard content")).not.toBeInTheDocument();
    expect(screen.queryByRole("navigation", { name: "Office" })).not.toBeInTheDocument();
  });

  it("lets an administrator in, without the owner-only links", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(Response.json(me("admin"))));
    renderAdmin();
    expect(await screen.findByText("Dashboard content")).toBeInTheDocument();
    const nav = screen.getByRole("navigation", { name: "Office" });
    expect(nav).toHaveTextContent("People");
    expect(nav).toHaveTextContent("Certificates");
    expect(nav).not.toHaveTextContent("Audit log");
    expect(nav).not.toHaveTextContent("Exports");
    expect(nav).not.toHaveTextContent("Staff accounts");
  });

  it("shows the owner-only links to the owner", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(Response.json(me("super_admin"))));
    renderAdmin();
    const nav = await screen.findByRole("navigation", { name: "Office" });
    expect(nav).toHaveTextContent("Audit log");
    expect(nav).toHaveTextContent("Exports");
    expect(nav).toHaveTextContent("Staff accounts");
  });

  it("has a skip link and a labelled search box", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(Response.json(me("admin"))));
    renderAdmin();
    await screen.findByText("Dashboard content");
    expect(screen.getByRole("link", { name: "Skip to the main content" })).toHaveAttribute(
      "href",
      "#main",
    );
    expect(
      screen.getByLabelText("Search people, organisations and certificates"),
    ).toBeInTheDocument();
  });

  it("shows an error with a retry when the account cannot be loaded", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new TypeError("network")));
    vi.spyOn(console, "error").mockImplementation(() => {});
    renderAdmin();
    expect(await screen.findByRole("alert")).toHaveTextContent("We could not load your account");
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });
});
