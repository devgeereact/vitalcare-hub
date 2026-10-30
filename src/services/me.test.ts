import { describe, expect, it } from "vitest";
import { parseMe } from "./me";

const good = {
  user: { id: "i", email: "a@b.co", displayName: "A", role: "trainer", organisationId: null },
};

describe("parseMe", () => {
  it("accepts a valid response", () => {
    expect(parseMe(good).role).toBe("trainer");
  });

  it.each([
    ["null", null],
    ["no user", {}],
    ["unknown role", { user: { ...good.user, role: "owner" } }],
    ["retired role", { user: { ...good.user, role: "content_editor" } }],
    ["role not a string", { user: { ...good.user, role: 1 } }],
    ["email missing", { user: { ...good.user, email: undefined } }],
    ["organisation not null or string", { user: { ...good.user, organisationId: 5 } }],
  ])("rejects %s", (_label, data) => {
    expect(() => parseMe(data)).toThrow();
  });

  it("does not pass extra fields through", () => {
    const me = parseMe({ user: { ...good.user, isAdmin: true } });
    expect(Object.keys(me).sort()).toEqual([
      "displayName",
      "email",
      "id",
      "organisationId",
      "role",
    ]);
  });
});
