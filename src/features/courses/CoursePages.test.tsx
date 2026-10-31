// @vitest-environment jsdom
import { render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { CourseDetailPage, CourseListPage } from "./CoursePages";

const course = {
  slug: "moving-and-handling",
  title: "Moving and Handling",
  summary: "First paragraph.\n\nSecond paragraph.",
  durationText: "3 hours",
  hours: 3,
  format: "blended",
  pricePence: 6000,
  priceNote: "per person",
  assessmentMethod: "Practical",
  renewalMonths: 12,
  accreditationNote: "",
};

function renderAt(path: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/courses" element={<CourseListPage />} />
        <Route path="/courses/:slug" element={<CourseDetailPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe("public course pages (read from the CMS)", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("lists published courses from the API", async () => {
    const f = vi.fn().mockResolvedValue(Response.json({ courses: [course] }));
    vi.stubGlobal("fetch", f);
    renderAt("/courses");
    expect(await screen.findByRole("link", { name: "Moving and Handling" })).toHaveAttribute(
      "href",
      "/courses/moving-and-handling",
    );
    expect(String(f.mock.calls[0]?.[0])).toBe("/api/courses");
    expect(screen.getByText(/£60\.00 per person/)).toBeInTheDocument();
  });

  it("says plainly when no course is published yet", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(Response.json({ courses: [] })));
    renderAt("/courses");
    expect(await screen.findByText(/course list is being prepared/)).toBeInTheDocument();
  });

  it("shows the owner's facts on the detail page, and hides empty ones", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(Response.json({ course })));
    renderAt("/courses/moving-and-handling");
    expect(
      await screen.findByRole("heading", { level: 1, name: "Moving and Handling" }),
    ).toBeInTheDocument();
    expect(screen.getByText("First paragraph.")).toBeInTheDocument();
    expect(screen.getByText("Second paragraph.")).toBeInTheDocument();
    expect(screen.getByText("In person and online")).toBeInTheDocument();
    expect(screen.getByText("Every year")).toBeInTheDocument();
    expect(screen.queryByText("Accreditation")).not.toBeInTheDocument();
  });

  it("shows not found for an unknown or unpublished course", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue(
          Response.json({ error: { code: "not_found", message: "Not found" } }, { status: 404 }),
        ),
    );
    renderAt("/courses/hidden-draft");
    expect(await screen.findByText("Course not found")).toBeInTheDocument();
  });

  it("shows an error, not a blank page, when the API fails", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new TypeError("network")));
    vi.spyOn(console, "error").mockImplementation(() => {});
    renderAt("/courses");
    expect(await screen.findByRole("alert")).toHaveTextContent("We could not load the courses");
  });
});
