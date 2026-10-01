type Props = { message: string; onRetry?: () => void };

/** Reusable error-state block for a failed request, with "Try again" where the request can be repeated. */
export default function ErrorState({ message, onRetry }: Props) {
  return (
    <div role="alert" className="rounded border border-red-200 bg-red-50 p-4 text-sm text-red-700">
      <p>{message}</p>
      {onRetry && (
        <button type="button" onClick={onRetry} className="mt-3 rounded border border-red-300 bg-white px-3 py-1 text-sm font-medium text-red-800 hover:bg-red-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600">
          Try again
        </button>
      )}
    </div>
  );
}
