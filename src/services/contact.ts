import { ApiError, apiFetch } from "./api";

export type ContactInput = {
  name: string;
  email: string;
  phone: string;
  message: string;
  turnstileToken: string;
  /** Honeypot. Real people never see this field, so it is always sent empty. */
  website: string;
};

export class ContactValidationError extends Error {
  constructor(readonly fields: Record<string, string>) {
    super("Validation failed");
    this.name = "ContactValidationError";
  }
}

/** Posts the form. A 422 becomes a ContactValidationError carrying the server's field messages. */
export async function submitContact(input: ContactInput, signal?: AbortSignal): Promise<void> {
  try {
    await apiFetch<unknown>("contact", { method: "POST", body: input, signal });
  } catch (err) {
    if (err instanceof ApiError && err.status === 422) {
      throw new ContactValidationError(err.fields ?? {});
    }
    throw err;
  }
}
