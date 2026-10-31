// @vitest-environment jsdom
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { VerifyPage } from "./VerifyPage";

const cert = {
  code: "ABCD-EFGH-JKMN",
  learnerName: "Lee Learner",
  courseTitle: "Basic Life Support",
  hours: 3.5,
  completedOn: "2026-01-10",
  expiresOn: "2027-03-03",
  issuer: "Vitalcare Training Hub Ltd",
};

function renderAt(path: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/verify" element={<VerifyPage />} />
        <Route path="/verify/:code" element={<VerifyPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

const reply = (body: unknown, status = 200) =>
  vi.fn().mockResolvedValue(Response.json(body, { status }));

describe("VerifyPage", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("shows the form and calls nothing on arrival without a code", () => {
    const f = vi.fn();
    vi.stubGlobal("fetch", f);
    renderAt("/verify");
    expect(screen.getByLabelText("Certificate code")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Check certificate" })).toBeDisabled();
    expect(f).not.toHaveBeenCalled();
  });

  it("checks a code from the address straight away (the QR code case)", async () => {
    const f = reply({ status: "valid", certificate: cert });
    vi.stubGlobal("fetch", f);
    renderAt("/verify/ABCDEFGHJKMN");
    expect(await screen.findByText("Valid")).toBeInTheDocument();
    expect(String(f.mock.calls[0]?.[0])).toBe("/api/verify/ABCDEFGHJKMN");
    expect(screen.getByText("Lee Learner")).toBeInTheDocument();
    expect(screen.getByText("Basic Life Support")).toBeInTheDocument();
    expect(screen.getByText("3 March 2027")).toBeInTheDocument();
    expect(screen.getByText("ABCD-EFGH-JKMN")).toBeInTheDocument();
  });

  it("shows expired with the date, in words not only colour", async () => {
    vi.stubGlobal("fetch", reply({ status: "expired", certificate: cert }));
    renderAt("/verify/ABCDEFGHJKMN");
    expect(await screen.findByText("Expired")).toBeInTheDocument();
    expect(screen.getByText(/It expired on 3 March 2027/)).toBeInTheDocument();
    expect(screen.queryByText("Valid until")).not.toBeInTheDocument();
  });

  it("shows no expiry plainly when there is none", async () => {
    vi.stubGlobal(
      "fetch",
      reply({ status: "valid", certificate: { ...cert, expiresOn: null, hours: null } }),
    );
    renderAt("/verify/ABCDEFGHJKMN");
    expect(await screen.findByText("No expiry date is recorded")).toBeInTheDocument();
    expect(screen.queryByText("Hours")).not.toBeInTheDocument();
  });

  it("says not issued without saying why, and shows how to get in touch", async () => {
    vi.stubGlobal("fetch", reply({ status: "not_issued" }));
    renderAt("/verify/ABCDEFGHJKMN");
    expect(await screen.findByText("Not issued")).toBeInTheDocument();
    expect(screen.getByText(/info@vitalcare\.uk/)).toBeInTheDocument();
    expect(
      screen.queryByText(/withdrawn|draft|pending|unknown|not found/i),
    ).not.toBeInTheDocument();
  });

  it("explains a rate limit kindly", async () => {
    vi.stubGlobal("fetch", reply({ error: { code: "rate_limited", message: "x" } }, 429));
    renderAt("/verify/ABCDEFGHJKMN");
    expect(await screen.findByRole("alert")).toHaveTextContent("too many checks");
  });

  it("treats a malformed response as an error, never as a result", async () => {
    vi.stubGlobal("fetch", reply({ status: "valid" }));
    vi.spyOn(console, "error").mockImplementation(() => {});
    renderAt("/verify/ABCDEFGHJKMN");
    expect(await screen.findByRole("alert")).toHaveTextContent("We could not check that code");
    expect(screen.queryByText("Valid")).not.toBeInTheDocument();
  });

  it("treats a network failure as an error", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new TypeError("network")));
    vi.spyOn(console, "error").mockImplementation(() => {});
    renderAt("/verify/ABCDEFGHJKMN");
    expect(await screen.findByRole("alert")).toBeInTheDocument();
  });

  it("turns what was typed into a clean address and checks it", async () => {
    const f = reply({ status: "not_issued" });
    vi.stubGlobal("fetch", f);
    renderAt("/verify");
    await userEvent.type(screen.getByLabelText("Certificate code"), "abcd-efgh jkmn");
    await userEvent.click(screen.getByRole("button", { name: "Check certificate" }));
    expect(await screen.findByText("Not issued")).toBeInTheDocument();
    expect(String(f.mock.calls[0]?.[0])).toBe("/api/verify/ABCDEFGHJKMN");
  });

  it("cannot be tricked into requesting another path", async () => {
    const f = reply({ status: "not_issued" });
    vi.stubGlobal("fetch", f);
    renderAt("/verify");
    await userEvent.type(screen.getByLabelText("Certificate code"), "../../admin?x=1");
    await userEvent.click(screen.getByRole("button", { name: "Check certificate" }));
    await screen.findByText("Not issued");
    expect(String(f.mock.calls[0]?.[0])).toBe("/api/verify/ADMINX1");
  });

  it("shows Revoked in words, with no name", async () => {
    vi.stubGlobal(
      "fetch",
      reply({
        status: "revoked",
        certificate: {
          code: "ABCD-EFGH-JKMN",
          courseTitle: "Basic Life Support",
          completedOn: "2026-01-10",
          revokedOn: "2026-10-31",
          issuer: "Vitalcare Training Hub Ltd",
        },
      }),
    );
    renderAt("/verify/ABCDEFGHJKMN");
    expect(await screen.findByText("Revoked")).toBeInTheDocument();
    expect(screen.getByText(/has been revoked\. It is no longer valid/)).toBeInTheDocument();
    expect(screen.getByText("31 October 2026")).toBeInTheDocument();
    expect(screen.queryByText("Name")).not.toBeInTheDocument();
  });
});
