type Props = { title: string; description?: string };

/** Reusable empty-state block — used across future modules' list pages. */
export default function EmptyState({ title, description }: Props) {
  return (
    <div className="rounded border border-dashed p-8 text-center text-gray-500">
      <p className="font-medium">{title}</p>
      {description && <p className="mt-1 text-sm">{description}</p>}
    </div>
  );
}
