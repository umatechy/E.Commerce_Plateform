type Props = { title: string; description?: string };

/** Reusable empty-state block — used across future modules' list pages. */
export default function EmptyState({ title, description }: Props) {
  return (
    <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-slate-500">
      <p className="font-medium">{title}</p>
      {description && <p className="mt-1 text-sm">{description}</p>}
    </div>
  );
}
