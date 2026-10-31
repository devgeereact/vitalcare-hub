import { afterEach, describe, expect, it, vi } from "vitest";
import { type ApiError, apiFetch, apiUrl, queryString } from "./api";

describe("apiUrl", () => {
  it("joins the API root and a simple path", () => {
    expect(apiUrl("/api/", "flights")).toBe("/api/flights");
    expect(apiUrl("https://api.example.com/v1/", "trips/today")).toBe(
      "https://api.example.com/v1/trips/today",
    );
  });

  it("allows a dotted file segment such as people.csv", () => {
    expect(apiUrl("/api", "admin/export/people.csv")).toBe("/api/admin/export/people.csv");
  });

  it.each([
    "admin/..",
    "admin/./x",
    "a..b",
    ".hidden",
    "../private",
    "https://evil.example/x",
    "//evil.example",
    "a/../private",
    "a?x=y",
    "a#frag",
    "",
  ])("rejects %j", (path) => {
    expect(() => apiUrl("/api", path)).toThrow();
  });

  it("rejects a protocol-relative base", () => {
    expect(() => apiUrl("//evil.example", "x")).toThrow();
  });
});

describe("queryString", () => {
  it("encodes values and leaves out empty ones", () => {
    expect(queryString({ q: "a&b=c", page: 2, status: "", x: undefined })).toBe(
      "?q=a%26b%3Dc&page=2",
    );
    expect(queryString({})).toBe("");
  });
});

describe("apiFetch", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("returns parsed JSON on success", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(Response.json({ status: "ok" })));
    await expect(apiFetch<{ status: string }>("health")).resolves.toEqual({ status: "ok" });
  });

  it("throws ApiError with the server's error code", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue(
          Response.json(
            { error: { code: "UNAUTHENTICATED", message: "Sign in" } },
            { status: 401 },
          ),
        ),
    );
    await expect(apiFetch("me")).rejects.toMatchObject({
      name: "ApiError",
      status: 401,
      code: "UNAUTHENTICATED",
    } satisfies Partial<ApiError>);
  });

  it("sends the bearer token only when provided", async () => {
    const fetchMock = vi.fn().mockResolvedValue(Response.json({}));
    vi.stubGlobal("fetch", fetchMock);
    await apiFetch("me", { token: "test-token" });
    expect(fetchMock.mock.calls[0]?.[1]?.headers).toMatchObject({
      Authorization: "Bearer test-token",
    });
  });
});
