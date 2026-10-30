import { Component, type ErrorInfo, type ReactNode } from "react";

type Props = { children: ReactNode };
type State = { failed: boolean };

/** Last-resort screen for render errors. Add approved, redacted error reporting in componentDidCatch. */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { failed: false };

  static getDerivedStateFromError(): State {
    return { failed: true };
  }

  componentDidCatch(_error: Error, _info: ErrorInfo): void {
    // e.g. report to Sentry once the owner approves it — never include user data or tokens.
  }

  render() {
    if (!this.state.failed) return this.props.children;
    return (
      <main role="alert">
        <h1>Something went wrong</h1>
        <p>Reload the page to try again. Your saved data is not affected.</p>
        <button type="button" onClick={() => window.location.reload()}>
          Reload
        </button>
      </main>
    );
  }
}
