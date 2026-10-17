type Props = { message: string; onRetry?: () => void };

/** Reusable error-state block for a failed request, with "Try again" where the request can be repeated. */
export default function ErrorState({ message, onRetry }: Props) {
  return (
    <div role="alert" className="rounded-2xl border border-red-200 bg-red-50/80 p-4 text-sm text-red-700">
      <p>{message}</p>
      {onRetry && (
        <button type="button" onClick={onRetry} className="mt-3 rounded-lg border border-red-200 bg-white px-3 py-1.5 text-sm font-semibold text-red-800 shadow-sm transition-colors hover:bg-red-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
          Try again
        </button>
      )}
    </div>
  );
}
