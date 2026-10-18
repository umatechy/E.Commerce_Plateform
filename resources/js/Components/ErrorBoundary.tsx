import { Component, type ErrorInfo, type ReactNode } from 'react';
import { reportError } from '@/lib/errorReporter';

/**
 * UI-25 — when a page breaks while drawing, the admin frame stays and the
 * page shows what happened and a way out instead of going blank; the error
 * is reported to Umar Techy (lib/errorReporter). `fallback` gets a retry.
 */
type Props = { children: ReactNode; fallback: (retry: () => void) => ReactNode };
type State = { failed: boolean };

export default class ErrorBoundary extends Component<Props, State> {
  state: State = { failed: false };

  static getDerivedStateFromError(): State {
    return { failed: true };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    reportError(error, info.componentStack ?? undefined);
  }

  render(): ReactNode {
    return this.state.failed ? this.props.fallback(() => this.setState({ failed: false })) : this.props.children;
  }
}
