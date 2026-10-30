import { describe, expect, it } from "vitest";
import { cleanCode, formatDate, parseVerify } from "./verify";

const cert = {
  code: "ABCD-EFGH-JKMN",
  learnerName: "Lee Learner",
  courseTitle: "Basic Life Support",
  hours: 3.5,
  completedOn: "2026-01-10",
  expiresOn: "2027-03-03",
  issuer: "Vitalcare Training Hub Ltd",
};

describe("cleanCode", () => {
  it("keeps letters and digits only, in capitals, so it is a safe path segment", () => {
    expect(cleanCode(" abcd-efgh jkmn ")).toBe("ABCDEFGHJKMN");
    expect(cleanCode("../../etc/passwd")).toBe("ETCPASSWD");
    expect(cleanCode("A?b=c#d")).toBe("ABCD");
    expect(cleanCode("")).toBe("");
  });
});

describe("parseVerify", () => {
  it("accepts the three outcomes", () => {
    expect(parseVerify({ status: "not_issued" })).toEqual({ status: "not_issued" });
    expect(parseVerify({ status: "valid", certificate: cert }).status).toBe("valid");
    expect(parseVerify({ status: "expired", certificate: cert }).status).toBe("expired");
  });

  it("never lets a not-issued answer carry data", () => {
    expect(parseVerify({ status: "not_issued", certificate: cert })).toEqual({
      status: "not_issued",
    });
  });

  it("allows no hours and no expiry", () => {
    const r = parseVerify({
      status: "valid",
      certificate: { ...cert, hours: null, expiresOn: null },
    });
    expect(r).toMatchObject({ certificate: { hours: null, expiresOn: null } });
  });

  it.each([
    ["null", null],
    ["unknown status", { status: "maybe" }],
    ["valid without a certificate", { status: "valid" }],
    ["bad date", { status: "valid", certificate: { ...cert, completedOn: "10/01/2026" } }],
    ["hours as a string", { status: "valid", certificate: { ...cert, hours: "3" } }],
    ["name missing", { status: "valid", certificate: { ...cert, learnerName: undefined } }],
  ])("rejects %s", (_l, data) => {
    expect(() => parseVerify(data)).toThrow();
  });

  it("does not pass extra fields through", () => {
    const r = parseVerify({ status: "valid", certificate: { ...cert, learnerEmail: "x@y.z" } });
    expect(JSON.stringify(r)).not.toContain("learnerEmail");
  });
});

describe("formatDate", () => {
  it("formats a date-only string without shifting the day", () => {
    expect(formatDate("2027-03-03")).toBe("3 March 2027");
    expect(formatDate("2026-12-31")).toBe("31 December 2026");
  });
});
