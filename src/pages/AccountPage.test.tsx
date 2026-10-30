// @vitest-environment jsdom
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { type Session, SessionContext } from "../auth/session";
import { AccountPage } from "./AccountPage";

function renderAccount(session: Partial<Session> = {}) {
  const value: Session = {
    status: "signed-in",
    getToken: async () => "tok-aaaaaaaaaaaaaaaaaaaaaaaa",
    signOut: vi.fn(async () => {}),
    ...session,
  };
  render(
    <SessionContext.Provider value={value}>
      <MemoryRouter initialEntries={["/app"]}>
        <Routes>
          <Route path="/app" element={<AccountPage />} />
          <Route path="/sign-in" element={<p>Sign in page</p>} />
        </Routes>
      </MemoryRouter>
    </SessionContext.Provider>,
  );
  return value;
}

const user = {
  id: "i",
  email: "learner@example.com",
  displayName: "Lee Learner",
  role: "learner",
  organisationId: null,
};

describe("AccountPage", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("shows the person's details in plain English", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(Response.json({ user })));
    renderAccount();
    expect(await screen.findByText("Lee Learner")).toBeInTheDocument();
    expect(screen.getByText("Learner")).toBeInTheDocument();
    expect(screen.getByText("learner@example.com")).toBeInTheDocument();
  });

  it("sends the session token as a bearer header", async () => {
    const fetchMock = vi.fn().mockResolvedValue(Response.json({ user }));
    vi.stubGlobal("fetch", fetchMock);
    renderAccount();
    await screen.findByText("Lee Learner");
    const init = fetchMock.mock.calls[0]?.[1] as RequestInit;
    expect((init.headers as Record<string, string>).Authorization).toBe(
      "Bearer tok-aaaaaaaaaaaaaaaaaaaaaaaa",
    );
  });

  it("shows a no-access message, not an error, for a valid identity with no row", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue(
          Response.json({ error: { code: "no_access", message: "no" } }, { status: 403 }),
        ),
    );
    renderAccount();
    expect(await screen.findByText("Your email address does not have access")).toBeInTheDocument();
    expect(screen.getByText(/info@vitalcare\.uk/)).toBeInTheDocument();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });

  it("shows an error with a retry that works", async () => {
    const fetchMock = vi
      .fn()
      .mockRejectedValueOnce(new TypeError("network"))
      .mockResolvedValueOnce(Response.json({ user }));
    vi.stubGlobal("fetch", fetchMock);
    vi.spyOn(console, "error").mockImplementation(() => {});
    renderAccount();
    expect(await screen.findByRole("alert")).toHaveTextContent("We could not load your account");
    await userEvent.click(screen.getByRole("button", { name: "Try again" }));
    expect(await screen.findByText("Lee Learner")).toBeInTheDocument();
  });

  it("treats a malformed response as an error, never as a signed-in user", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(Response.json({ user: { role: "super_admin" } })),
    );
    vi.spyOn(console, "error").mockImplementation(() => {});
    renderAccount();
    expect(await screen.findByRole("alert")).toBeInTheDocument();
    expect(screen.queryByText("Super administrator")).not.toBeInTheDocument();
  });

  it("sends a signed-out visitor to the sign-in page without calling the API", async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);
    renderAccount({ status: "signed-out" });
    expect(await screen.findByText("Sign in page")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("shows a loading skeleton while the sign-in state resolves", () => {
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);
    renderAccount({ status: "loading" });
    expect(screen.getByText("Checking your sign-in")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("signs out when asked", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(Response.json({ user })));
    const session = renderAccount();
    await screen.findByText("Lee Learner");
    await userEvent.click(screen.getByRole("button", { name: "Sign out" }));
    await waitFor(() => expect(session.signOut).toHaveBeenCalled());
  });
});
