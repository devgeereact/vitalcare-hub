// @vitest-environment jsdom
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const cfg = vi.hoisted(() => ({ key: "site-key" }));
vi.mock("../config", () => ({
  APP_NAME: "x",
  APP_DESCRIPTION: "x",
  API_BASE: "/api",
  CLERK_PUBLISHABLE_KEY: "",
  get TURNSTILE_SITE_KEY() {
    return cfg.key;
  },
}));
vi.mock("../components/TurnstileWidget", () => ({
  TurnstileWidget: ({ onToken }: { onToken: (t: string | null) => void }) => (
    <button type="button" onClick={() => onToken("good-token")}>
      pass-check
    </button>
  ),
}));

import { ContactPage } from "./ContactPage";

const reply = (body: unknown, status = 200) =>
  vi.fn().mockResolvedValue(Response.json(body, { status }));

async function fill() {
  await userEvent.type(screen.getByLabelText("Your name"), "Pat Example");
  await userEvent.type(screen.getByLabelText("Email address"), "pat@example.com");
  await userEvent.type(
    screen.getByLabelText("Your message"),
    "Please send details of your courses.",
  );
}

describe("ContactPage", () => {
  beforeEach(() => {
    cfg.key = "site-key";
  });
  afterEach(() => vi.unstubAllGlobals());

  it("says the form is unavailable, and still shows how to get in touch, when Turnstile is not configured", () => {
    cfg.key = "";
    render(<ContactPage />);
    expect(screen.getByText("The contact form is not available yet")).toBeInTheDocument();
    expect(screen.getAllByText(/info@vitalcare\.uk/).length).toBeGreaterThan(0);
    expect(screen.queryByLabelText("Your name")).not.toBeInTheDocument();
  });

  it("cannot be sent until the check is passed", async () => {
    const f = vi.fn();
    vi.stubGlobal("fetch", f);
    render(<ContactPage />);
    await fill();
    expect(screen.getByRole("button", { name: "Send message" })).toBeDisabled();
    await userEvent.click(screen.getByRole("button", { name: "pass-check" }));
    expect(screen.getByRole("button", { name: "Send message" })).toBeEnabled();
    expect(f).not.toHaveBeenCalled();
  });

  it("sends the form with the token and an empty honeypot, then confirms", async () => {
    const f = reply({ received: true }, 201);
    vi.stubGlobal("fetch", f);
    render(<ContactPage />);
    await fill();
    await userEvent.click(screen.getByRole("button", { name: "pass-check" }));
    await userEvent.click(screen.getByRole("button", { name: "Send message" }));
    expect(await screen.findByText("Message sent")).toBeInTheDocument();
    const init = f.mock.calls[0]?.[1] as RequestInit;
    expect(String(f.mock.calls[0]?.[0])).toBe("/api/contact");
    expect(init.method).toBe("POST");
    const body = JSON.parse(String(init.body));
    expect(body).toMatchObject({
      name: "Pat Example",
      email: "pat@example.com",
      turnstileToken: "good-token",
      website: "",
    });
  });

  it("hides the honeypot from people and assistive technology", () => {
    render(<ContactPage />);
    const trap = document.getElementById("website");
    expect(trap?.closest("[aria-hidden='true']")).not.toBeNull();
    expect(trap).toHaveAttribute("tabindex", "-1");
  });

  it("shows the server's field messages next to the fields and keeps what was typed", async () => {
    vi.stubGlobal(
      "fetch",
      reply(
        {
          error: {
            code: "invalid_input",
            message: "x",
            fields: { email: "Enter a valid email address." },
          },
        },
        422,
      ),
    );
    render(<ContactPage />);
    await fill();
    await userEvent.click(screen.getByRole("button", { name: "pass-check" }));
    await userEvent.click(screen.getByRole("button", { name: "Send message" }));
    expect(await screen.findByText("Enter a valid email address.")).toBeInTheDocument();
    expect(screen.getByLabelText("Email address")).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByLabelText("Your name")).toHaveValue("Pat Example");
  });

  it("makes the person pass the check again after a failed attempt (tokens are single use)", async () => {
    vi.stubGlobal("fetch", reply({ error: { code: "verification_failed", message: "x" } }, 403));
    render(<ContactPage />);
    await fill();
    await userEvent.click(screen.getByRole("button", { name: "pass-check" }));
    await userEvent.click(screen.getByRole("button", { name: "Send message" }));
    expect(await screen.findByRole("alert")).toHaveTextContent(
      "could not check that you are a person",
    );
    await waitFor(() =>
      expect(screen.getByRole("button", { name: "Send message" })).toBeDisabled(),
    );
  });

  it("explains a rate limit and offers the direct contact details", async () => {
    vi.stubGlobal("fetch", reply({ error: { code: "rate_limited", message: "x" } }, 429));
    render(<ContactPage />);
    await fill();
    await userEvent.click(screen.getByRole("button", { name: "pass-check" }));
    await userEvent.click(screen.getByRole("button", { name: "Send message" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("contact us directly");
  });

  it("shows a plain error if the network fails and does not claim success", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new TypeError("network")));
    vi.spyOn(console, "error").mockImplementation(() => {});
    render(<ContactPage />);
    await fill();
    await userEvent.click(screen.getByRole("button", { name: "pass-check" }));
    await userEvent.click(screen.getByRole("button", { name: "Send message" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("We could not send your message");
    expect(screen.queryByText("Message sent")).not.toBeInTheDocument();
  });
});
