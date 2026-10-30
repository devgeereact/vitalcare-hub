// @vitest-environment jsdom
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { SignInForm } from "./SignInForm";

type Result = { error: unknown };
const ok: Result = { error: null };
const fail: Result = { error: { code: "form_identifier_not_found" } };

const signIn = {
  status: "needs_first_factor" as string,
  create: vi.fn(async (): Promise<Result> => ok),
  emailCode: {
    sendCode: vi.fn(async (): Promise<Result> => ok),
    verifyCode: vi.fn(async (): Promise<Result> => ok),
  },
  finalize: vi.fn(async (_opts: unknown) => ({ error: null })),
};
let fetchStatus = "idle";

vi.mock("@clerk/react", () => ({
  useSignIn: () => ({ signIn, errors: { fields: {} }, fetchStatus }),
}));

function renderForm() {
  render(
    <MemoryRouter>
      <SignInForm />
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  signIn.status = "needs_first_factor";
  signIn.create.mockResolvedValue(ok);
  signIn.emailCode.sendCode.mockResolvedValue(ok);
  signIn.emailCode.verifyCode.mockResolvedValue(ok);
  fetchStatus = "idle";
  vi.spyOn(console, "error").mockImplementation(() => {});
});

describe("SignInForm", () => {
  it("asks for an email address only: no password, no social buttons", () => {
    renderForm();
    expect(screen.getByLabelText("Email address")).toBeInTheDocument();
    expect(document.querySelector('input[type="password"]')).toBeNull();
    expect(screen.queryByText(/google|facebook|apple|microsoft/i)).toBeNull();
    expect(screen.getByText(/There is no password/)).toBeInTheDocument();
  });

  it("puts keyboard focus in the email field", () => {
    renderForm();
    expect(screen.getByLabelText("Email address")).toHaveFocus();
  });

  it("disables the button until an address is entered", async () => {
    renderForm();
    const button = screen.getByRole("button", { name: "Send code" });
    expect(button).toBeDisabled();
    await userEvent.type(screen.getByLabelText("Email address"), "a@example.com");
    expect(button).toBeEnabled();
  });

  it("sends a code, then moves to the code step with focus on the code field", async () => {
    renderForm();
    await userEvent.type(screen.getByLabelText("Email address"), "  Person@Example.com ");
    await userEvent.click(screen.getByRole("button", { name: "Send code" }));
    expect(signIn.create).toHaveBeenCalledWith({ identifier: "Person@Example.com" });
    expect(signIn.emailCode.sendCode).toHaveBeenCalled();
    const code = await screen.findByLabelText("Code");
    expect(code).toHaveFocus();
    expect(code).toHaveAttribute("autocomplete", "one-time-code");
    expect(screen.getByText(/Person@Example.com/)).toBeInTheDocument();
  });

  it("shows a plain message and stays on the email step when the code cannot be sent", async () => {
    signIn.create.mockResolvedValue(fail);
    renderForm();
    await userEvent.type(screen.getByLabelText("Email address"), "x@example.com");
    await userEvent.click(screen.getByRole("button", { name: "Send code" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("We could not send a code");
    expect(screen.getByLabelText("Email address")).toBeInTheDocument();
    expect(signIn.emailCode.sendCode).not.toHaveBeenCalled();
  });

  it("does not echo the service's own error text to the person", async () => {
    signIn.create.mockResolvedValue({
      error: { message: "form_identifier_not_found: no such user internal detail" },
    });
    renderForm();
    await userEvent.type(screen.getByLabelText("Email address"), "x@example.com");
    await userEvent.click(screen.getByRole("button", { name: "Send code" }));
    const alert = await screen.findByRole("alert");
    expect(alert).not.toHaveTextContent(/internal|form_identifier/);
  });

  it("shows a connection message if the service cannot be reached", async () => {
    signIn.create.mockRejectedValue(new TypeError("network"));
    renderForm();
    await userEvent.type(screen.getByLabelText("Email address"), "x@example.com");
    await userEvent.click(screen.getByRole("button", { name: "Send code" }));
    expect(await screen.findByRole("alert")).toHaveTextContent(
      "We could not reach the sign-in service",
    );
  });

  async function toCodeStep() {
    renderForm();
    await userEvent.type(screen.getByLabelText("Email address"), "a@example.com");
    await userEvent.click(screen.getByRole("button", { name: "Send code" }));
    return screen.findByLabelText("Code");
  }

  it("rejects a wrong code with a plain message and does not finalise", async () => {
    signIn.emailCode.verifyCode.mockResolvedValue({ error: { code: "form_code_incorrect" } });
    const code = await toCodeStep();
    await userEvent.type(code, "000000");
    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("That code is not right");
    expect(signIn.finalize).not.toHaveBeenCalled();
  });

  it("does not finalise unless the sign-in is complete, even if verify returned no error", async () => {
    signIn.status = "needs_second_factor";
    const code = await toCodeStep();
    await userEvent.type(code, "123456");
    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));
    expect(await screen.findByRole("alert")).toBeInTheDocument();
    expect(signIn.finalize).not.toHaveBeenCalled();
  });

  it("finalises once the code is accepted and the sign-in is complete", async () => {
    signIn.status = "complete";
    const code = await toCodeStep();
    await userEvent.type(code, " 123456 ");
    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));
    expect(signIn.emailCode.verifyCode).toHaveBeenCalledWith({ code: "123456" });
    await waitFor(() => expect(signIn.finalize).toHaveBeenCalled());
  });

  it("offers a new code and a way back to change the address", async () => {
    await toCodeStep();
    await userEvent.click(screen.getByRole("button", { name: "Send a new code" }));
    expect(signIn.emailCode.sendCode).toHaveBeenCalledTimes(2);
    await userEvent.click(screen.getByRole("button", { name: "Use a different email address" }));
    expect(await screen.findByLabelText("Email address")).toBeInTheDocument();
  });

  it("disables the buttons while a request is in flight", async () => {
    fetchStatus = "fetching";
    renderForm();
    await userEvent.type(screen.getByLabelText("Email address"), "a@example.com");
    expect(screen.getByRole("button", { name: "Send code" })).toBeDisabled();
  });
});
