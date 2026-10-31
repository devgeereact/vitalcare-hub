import { createContext, type FormEvent, type ReactNode, useContext, useId, useState } from "react";
import type { Me } from "@/features/account/me";
import { Loading } from "@/shared/components/Loading";
import { ApiError } from "@/shared/services/api";
import { STATE_LABEL } from "./format";
import type { CertificateState } from "./types";
import type { Load } from "./useAdmin";

/** The signed-in staff member, provided by AdminLayout once /api/me has answered. */
export const MeContext = createContext<Me | null>(null);

export function useMe(): Me {
  const me = useContext(MeContext);
  if (!me) throw new Error("useMe outside the admin layout");
  return me;
}

export const isOwner = (me: Me) => me.role === "super_admin";

/** Loading, error and permission states for any CMS load, then the content. */
export function LoadView<T>({
  load,
  label,
  retry,
  children,
}: {
  load: Load<T>;
  label: string;
  retry?: () => void;
  children: (data: T) => ReactNode;
}) {
  if (load.kind === "loading") return <Loading label={`Loading ${label}`} />;
  if (load.kind === "error") {
    const text =
      load.status === 404
        ? "This record does not exist, or it was removed."
        : load.status === 403
          ? "Your account does not have permission to see this."
          : load.message || "We could not load this. Check your connection and try again.";
    return (
      <div className="notice notice--error" role="alert">
        <strong>{load.status === 403 ? "No permission" : "Problem"}</strong>
        {text}
        {retry && load.status !== 404 && load.status !== 403 && (
          <div className="actions">
            <button className="btn btn--quiet" type="button" onClick={retry}>
              Try again
            </button>
          </div>
        )}
      </div>
    );
  }
  return <>{children(load.data)}</>;
}

export function Badge({
  tone,
  children,
}: {
  tone: "ok" | "warn" | "error" | "info";
  children: ReactNode;
}) {
  return <span className={`badge badge--${tone}`}>{children}</span>;
}

export function StateBadge({
  state,
}: {
  state: CertificateState | "in_date" | "due_soon" | "none";
}) {
  const map = {
    valid: ["ok", STATE_LABEL.valid],
    in_date: ["ok", "In date"],
    due_soon: ["warn", "Due soon"],
    expired: ["error", STATE_LABEL.expired],
    revoked: ["error", STATE_LABEL.revoked],
    not_issued: ["info", STATE_LABEL.not_issued],
    none: ["info", "No training"],
  } as const;
  const [tone, text] = map[state];
  return <Badge tone={tone}>{text}</Badge>;
}

export function Pager({
  page,
  total,
  perPage = 50,
  onPage,
}: {
  page: number;
  total: number;
  perPage?: number;
  onPage: (p: number) => void;
}) {
  const pages = Math.max(1, Math.ceil(total / perPage));
  if (pages <= 1) return <p className="hint">{total === 1 ? "1 record" : `${total} records`}</p>;
  return (
    <nav className="pager" aria-label="Pages">
      <button
        className="btn btn--quiet"
        type="button"
        disabled={page <= 1}
        onClick={() => onPage(page - 1)}
      >
        Previous
      </button>
      <span>
        Page {page} of {pages} ({total} records)
      </span>
      <button
        className="btn btn--quiet"
        type="button"
        disabled={page >= pages}
        onClick={() => onPage(page + 1)}
      >
        Next
      </button>
    </nav>
  );
}

// ---------------------------------------------------------------- forms

export type FieldDef = {
  key: string;
  label: string;
  type?:
    | "text"
    | "email"
    | "tel"
    | "url"
    | "textarea"
    | "select"
    | "checkbox"
    | "number"
    | "date"
    | "datetime-local"
    | "money";
  options?: readonly (readonly [string, string])[];
  hint?: string;
  required?: boolean;
  /** For "number": allow decimals. */
  step?: string;
  /** Send '' rather than null when empty (columns that hold empty text, not null). */
  keepEmpty?: boolean;
};

export type FormValues = Record<string, string | boolean>;

/** Form values from a record: everything becomes a string (or boolean for checkboxes). */
export function toFormValues(
  fields: FieldDef[],
  record: Record<string, unknown> | null,
): FormValues {
  const v: FormValues = {};
  for (const f of fields) {
    const raw = record?.[f.key];
    if (f.type === "checkbox") v[f.key] = raw === true;
    else if (f.type === "money") v[f.key] = typeof raw === "number" ? (raw / 100).toFixed(2) : "";
    else v[f.key] = raw === null || raw === undefined ? "" : String(raw);
  }
  return v;
}

/** The JSON body for the API: empty becomes null, numbers become numbers, pounds become pence. */
export function toBody(fields: FieldDef[], values: FormValues): Record<string, unknown> {
  const body: Record<string, unknown> = {};
  for (const f of fields) {
    const v = values[f.key];
    if (f.type === "checkbox") body[f.key] = v === true;
    else if (typeof v !== "string" || v.trim() === "") body[f.key] = f.keepEmpty ? "" : null;
    else if (f.type === "number") body[f.key] = Number(v);
    else if (f.type === "money") body[f.key] = Math.round(Number(v) * 100);
    else body[f.key] = v;
  }
  return body;
}

export function useFormState(initial: FormValues) {
  const [values, setValues] = useState<FormValues>(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);
  async function run(fn: () => Promise<void>) {
    setBusy(true);
    setErrors({});
    setProblem(null);
    setSaved(false);
    try {
      await fn();
      setSaved(true);
    } catch (err) {
      if (err instanceof ApiError) {
        setErrors(err.fields ?? {});
        setProblem(err.message);
      } else {
        console.error("[admin] save failed", err);
        setProblem("We could not save this. Check your connection and try again.");
      }
    } finally {
      setBusy(false);
    }
  }
  return { values, setValues, errors, problem, busy, saved, run };
}

/** A form built from field definitions. Field errors come from the API's 422 answer. */
export function RecordForm({
  fields,
  initial,
  submitLabel,
  onSubmit,
  readOnly = false,
  savedText = "Saved.",
  resetOnSave = false,
}: {
  fields: FieldDef[];
  initial: FormValues;
  submitLabel: string;
  onSubmit: (body: Record<string, unknown>) => Promise<void>;
  readOnly?: boolean;
  savedText?: string;
  resetOnSave?: boolean;
}) {
  const f = useFormState(initial);
  const base = useId();
  function submit(e: FormEvent) {
    e.preventDefault();
    void f.run(async () => {
      await onSubmit(toBody(fields, f.values));
      if (resetOnSave) f.setValues(initial);
    });
  }
  return (
    <form onSubmit={submit} noValidate className="record-form">
      {f.problem && (
        <p className="notice notice--error" role="alert">
          <strong>Problem</strong>
          {f.problem}
        </p>
      )}
      {fields.map((d) => {
        const id = `${base}-${d.key}`;
        const error = f.errors[d.key];
        const describedBy =
          [d.hint ? `${id}-hint` : "", error ? `${id}-error` : ""].filter(Boolean).join(" ") ||
          undefined;
        const value = f.values[d.key];
        const common = {
          id,
          name: d.key,
          disabled: readOnly,
          "aria-invalid": !!error,
          "aria-describedby": describedBy,
          required: d.required,
        };
        const set = (v: string | boolean) => f.setValues((prev) => ({ ...prev, [d.key]: v }));
        const cls = `input${error ? " input--error" : ""}`;
        return (
          <div className={`field${d.type === "checkbox" ? " field--check" : ""}`} key={d.key}>
            {d.type === "checkbox" ? (
              <>
                <input
                  {...common}
                  type="checkbox"
                  checked={value === true}
                  onChange={(e) => set(e.target.checked)}
                />
                <label htmlFor={id}>{d.label}</label>
              </>
            ) : (
              <label htmlFor={id}>
                {d.label}
                {d.required ? "" : " (optional)"}
              </label>
            )}
            {d.hint && (
              <p className="hint" id={`${id}-hint`}>
                {d.hint}
              </p>
            )}
            {d.type === "textarea" ? (
              <textarea
                {...common}
                className={cls}
                value={String(value ?? "")}
                onChange={(e) => set(e.target.value)}
              />
            ) : d.type === "select" ? (
              <select
                {...common}
                className={cls}
                value={String(value ?? "")}
                onChange={(e) => set(e.target.value)}
              >
                {!d.required && <option value="">None</option>}
                {d.options?.map(([v, label]) => (
                  <option key={v} value={v}>
                    {label}
                  </option>
                ))}
              </select>
            ) : d.type === "checkbox" ? null : (
              <input
                {...common}
                className={cls}
                type={d.type === "money" ? "text" : (d.type ?? "text")}
                inputMode={d.type === "money" || d.type === "number" ? "decimal" : undefined}
                step={d.step}
                value={String(value ?? "")}
                onChange={(e) => set(e.target.value)}
              />
            )}
            {error && (
              <p className="field-error" id={`${id}-error`}>
                {error}
              </p>
            )}
          </div>
        );
      })}
      {!readOnly && (
        <div className="actions">
          <button className="btn" type="submit" disabled={f.busy}>
            {f.busy ? "Saving" : submitLabel}
          </button>
        </div>
      )}
      <p aria-live="polite" className="hint">
        {f.saved && !f.busy ? savedText : ""}
      </p>
    </form>
  );
}

/** A small form for one action that needs a reason or a body (revoke, reply, note). */
export function TextActionForm({
  label,
  hint,
  submitLabel,
  minLength,
  onSubmit,
  danger = false,
}: {
  label: string;
  hint?: string;
  submitLabel: string;
  minLength?: number;
  onSubmit: (text: string) => Promise<void>;
  danger?: boolean;
}) {
  const fields: FieldDef[] = [{ key: "text", label, type: "textarea", hint, required: true }];
  const f = useFormState({ text: "" });
  const id = useId();
  function submit(e: FormEvent) {
    e.preventDefault();
    const text = String(f.values.text).trim();
    void f.run(async () => {
      if (minLength && text.length < minLength) {
        throw new ApiError(422, "invalid_input", "Check the highlighted field.", {
          text: `Write at least ${minLength} characters.`,
        });
      }
      await onSubmit(text);
      f.setValues({ text: "" });
    });
  }
  const error = f.errors.text ?? f.errors.body ?? f.errors.reason;
  return (
    <form onSubmit={submit} noValidate>
      <div className="field">
        <label htmlFor={id}>{fields[0]?.label}</label>
        {hint && (
          <p className="hint" id={`${id}-hint`}>
            {hint}
          </p>
        )}
        <textarea
          id={id}
          className={`input${error ? " input--error" : ""}`}
          value={String(f.values.text)}
          onChange={(e) => f.setValues({ text: e.target.value })}
          aria-invalid={!!error}
          aria-describedby={
            [hint ? `${id}-hint` : "", error ? `${id}-error` : ""].filter(Boolean).join(" ") ||
            undefined
          }
          required
        />
        {error && (
          <p className="field-error" id={`${id}-error`}>
            {error}
          </p>
        )}
      </div>
      {f.problem && !error && (
        <p className="notice notice--error" role="alert">
          <strong>Problem</strong>
          {f.problem}
        </p>
      )}
      <button className={`btn${danger ? " btn--danger" : ""}`} type="submit" disabled={f.busy}>
        {f.busy ? "Working" : submitLabel}
      </button>
      <p aria-live="polite" className="hint">
        {f.saved && !f.busy ? "Done." : ""}
      </p>
    </form>
  );
}
