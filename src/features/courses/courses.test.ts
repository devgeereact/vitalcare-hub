import { describe, expect, it } from "vitest";
import { parseCourse, parseCourseList, priceText, renewalText } from "./courses";

const good = {
  slug: "basic-life-support",
  title: "Basic Life Support",
  summary: "Adult basic life support.",
  durationText: "Half a day",
  hours: 3.5,
  format: "in_person",
  pricePence: 4500,
  priceNote: "per person",
  assessmentMethod: "Practical observation",
  renewalMonths: 12,
  accreditationNote: "",
};

describe("parseCourse", () => {
  it("accepts the public shape", () => {
    expect(parseCourse(good).title).toBe("Basic Life Support");
  });

  it("keeps only the public fields", () => {
    const c = parseCourse({ ...good, id: "x", published: false, publishedAt: "2026-01-01" });
    expect(Object.keys(c).sort()).toEqual(Object.keys(good).sort());
  });

  it.each([
    ["no slug", { ...good, slug: undefined }],
    ["hostile slug", { ...good, slug: "../admin" }],
    ["unknown format", { ...good, format: "video" }],
    ["price as text", { ...good, pricePence: "45" }],
    ["renewal as text", { ...good, renewalMonths: "12" }],
    ["summary missing", { ...good, summary: null }],
  ])("rejects %s", (_l, v) => {
    expect(() => parseCourse(v)).toThrow();
  });

  it("rejects a list response without a courses array", () => {
    expect(() => parseCourseList({ items: [] })).toThrow();
    expect(parseCourseList({ courses: [] })).toEqual([]);
  });
});

describe("wording helpers", () => {
  it("prices in pounds with the owner's note, or on request", () => {
    expect(priceText({ pricePence: 4500, priceNote: "per person" })).toBe("£45.00 per person");
    expect(priceText({ pricePence: 0, priceNote: "" })).toBe("£0.00");
    expect(priceText({ pricePence: null, priceNote: "per person" })).toBe("Price on request");
  });

  it("says the renewal period plainly", () => {
    expect(renewalText(12)).toBe("Every year");
    expect(renewalText(36)).toBe("Every 3 years");
    expect(renewalText(18)).toBe("Every 18 months");
    expect(renewalText(1)).toBe("Every month");
    expect(renewalText(null)).toBe("No renewal period is set");
  });
});
