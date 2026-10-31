// @vitest-environment jsdom
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { ApiError } from "@/shared/services/api";
import { dateTime, money, shortDate } from "./format";
import { asPage, pick } from "./types";
import { type FieldDef, RecordForm, toBody, toFormValues } from "./ui";

const fields: FieldDef[] = [
  { key: "title", label: "Title", required: true },
  { key: "priceNote", label: "Price note", keepEmpty: true },
  { key: "pricePence", label: "Price in pounds", type: "money" },
  { key: "renewalMonths", label: "Renewal", type: "number" },
  { key: "published", label: "Published", type: "checkbox" },
  { key: "email", label: "Email", type: "email" },
];

describe("form value conversion", () => {
  it("turns a record into strings and booleans, pence into pounds", () => {
    expect(
      toFormValues(fields, {
        title: "T",
        priceNote: "",
        pricePence: 4550,
        renewalMonths: 12,
        published: true,
        email: null,
      }),
    ).toEqual({
      title: "T",
      priceNote: "",
      pricePence: "45.50",
      renewalMonths: "12",
      published: true,
      email: "",
    });
  });

  it("sends null for empty, '' where the column keeps empty text, numbers as numbers, pounds as pence", () => {
    expect(
      toBody(fields, {
        title: " T ",
        priceNote: "",
        pricePence: "45.5",
        renewalMonths: "",
        published: false,
        email: "",
      }),
    ).toEqual({
      title: " T ",
      priceNote: "",
      pricePence: 4550,
      renewalMonths: null,
      published: false,
      email: null,
    });
  });

  it("rounds pounds to whole pence", () => {
    expect(toBody([{ key: "p", label: "P", type: "money" }], { p: "19.999" })).toEqual({ p: 2000 });
  });
});

describe("RecordForm", () => {
  it("shows the server's message beside each field and links it for screen readers", async () => {
    const onSubmit = vi.fn().mockRejectedValue(
      new ApiError(422, "invalid_input", "Check the highlighted fields and try again.", {
        title: "This is required.",
      }),
    );
    render(
      <RecordForm
        fields={fields}
        initial={toFormValues(fields, null)}
        submitLabel="Save"
        onSubmit={onSubmit}
      />,
    );
    await userEvent.click(screen.getByRole("button", { name: "Save" }));
    const input = screen.getByLabelText("Title");
    expect(await screen.findByText("This is required.")).toBeInTheDocument();
    expect(input).toHaveAttribute("aria-invalid", "true");
    expect(input.getAttribute("aria-describedby")).toContain(
      screen.getByText("This is required.").id,
    );
    expect(screen.getByRole("alert")).toHaveTextContent("Check the highlighted fields");
  });

  it("marks optional fields and says when it saved", async () => {
    const onSubmit = vi.fn().mockResolvedValue(undefined);
    render(
      <RecordForm
        fields={fields}
        initial={toFormValues(fields, { title: "x" })}
        submitLabel="Save"
        onSubmit={onSubmit}
      />,
    );
    expect(screen.getByLabelText("Email (optional)")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Save" }));
    expect(await screen.findByText("Saved.")).toBeInTheDocument();
    expect(onSubmit).toHaveBeenCalledWith(
      expect.objectContaining({ title: "x", published: false }),
    );
  });

  it("read-only mode shows values but no save button", () => {
    render(
      <RecordForm
        fields={fields}
        initial={toFormValues(fields, { title: "x" })}
        submitLabel="Save"
        readOnly
        onSubmit={vi.fn()}
      />,
    );
    expect(screen.getByLabelText("Title")).toBeDisabled();
    expect(screen.queryByRole("button", { name: "Save" })).not.toBeInTheDocument();
  });
});

describe("boundary checks and formatting", () => {
  it("refuses responses without the expected key or list shape", () => {
    expect(() => pick({}, "person")).toThrow();
    expect(() => pick(null, "person")).toThrow();
    expect(() => asPage({ items: "x", total: 1, page: 1 })).toThrow();
    expect(asPage({ items: [], total: 0, page: 1 }).items).toEqual([]);
  });

  it("formats dates and money without time zone shifts", () => {
    expect(shortDate("2026-03-29")).toBe("29 Mar 2026");
    expect(dateTime("2026-11-05T09:30")).toBe("5 Nov 2026, 09:30");
    expect(money(4500)).toBe("£45.00");
    expect(money(null)).toBe("");
  });
});
