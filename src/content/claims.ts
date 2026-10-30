import { CLAIMS, type ClaimKey } from "../generated/claims";

/**
 * The only way copy may state a company fact. ClaimKey contains PROVEN claims only, so using a
 * claim that is still REQUIRED in docs/claims.yml is a compile-time error.
 */
export function claim(key: ClaimKey): string {
  return CLAIMS[key];
}
