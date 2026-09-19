type Props = { message: string };

/** Reusable error-state block for a failed request. */
export default function ErrorState({ message }: Props) {
  return (
    <div className="rounded border border-red-200 bg-red-50 p-4 text-sm text-red-700">
      {message}
    </div>
  );
}
