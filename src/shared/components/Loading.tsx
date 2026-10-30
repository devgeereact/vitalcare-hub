/** Skeleton, not a spinner. The status text is for screen readers. */
export function Loading({ label = "Loading" }: { label?: string }) {
  return (
    <output aria-live="polite" aria-busy="true">
      <span className="visually-hidden">{label}</span>
      <span className="skeleton" />
      <span className="skeleton" />
      <span className="skeleton" />
    </output>
  );
}
