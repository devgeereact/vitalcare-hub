import { describe, expect, it } from "vitest";
import { CLAIMS, REQUIRED_CLAIMS } from "../generated/claims";
import { claim } from "./claims";

describe("claim register", () => {
  it("returns proven wording", () => {
    expect(claim("company_identity")).toContain("15718997");
    expect(claim("contact_details")).toContain("info@vitalcare.uk");
  });

  it("does not export any claim that is still required", () => {
    for (const key of REQUIRED_CLAIMS) expect(Object.keys(CLAIMS)).not.toContain(key);
  });

  it("makes using an unproven claim a compile-time error", () => {
    // If either claim ever becomes PROVEN, these directives become "unused" and typecheck fails,
    // which is the prompt to remove them.
    // @ts-expect-error cpd_accreditation is REQUIRED, so it is not a valid ClaimKey
    void claim("cpd_accreditation");
    // @ts-expect-error the same for CSTF alignment
    void claim("cstf_alignment");
    expect(Object.keys(CLAIMS)).not.toContain("cpd_accreditation");
  });

  it("keeps every accreditation and credential claim unpublished until proof exists", () => {
    for (const key of [
      "cpd_accreditation",
      "cstf_alignment",
      "cqc_claims",
      "clinical_director_oversight",
      "trainer_credentials",
      "testimonials",
      "ico_registration",
    ]) {
      expect(REQUIRED_CLAIMS).toContain(key);
    }
  });
});
