import { FormEvent, useRef, useState } from 'react';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { FormError } from '@/Components/ui/Form';
import { StatCard } from '@/Components/ui/Page';
import { toast } from '@/Components/ui/toast';
import { adminErrorMessage, adminFetch } from '@/lib/adminApi';
import { PRODUCT_IMPORT_TEMPLATE, type ProductImportPreview, type ProductImportResult } from '@/lib/catalog';

/**
 * Phase B40 (Module 06 §48–50): choose a CSV file → the server checks every
 * row and shows what will happen → confirm → a report. Nothing is saved
 * before the confirmation; a product is matched by id or SKU, so importing
 * the same file again updates instead of duplicating.
 */
export default function ProductImportDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const file = useRef<HTMLInputElement>(null);
  const [preview, setPreview] = useState<ProductImportPreview | null>(null);
  const [result, setResult] = useState<ProductImportResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function check(event: FormEvent) {
    event.preventDefault();
    const chosen = file.current?.files?.[0];
    if (!chosen) {
      setError('Choose a CSV file first.');

      return;
    }
    setBusy(true);
    setError(null);
    const body = new FormData();
    body.append('file', chosen);
    try {
      setPreview((await adminFetch<{ data: ProductImportPreview }>('/products/import', { method: 'POST', body, timeoutMs: 120000 })).data);
    } catch (e) {
      setError(adminErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  async function confirm() {
    if (!preview) return;
    setBusy(true);
    setError(null);
    try {
      const done = (await adminFetch<{ data: ProductImportResult }>(`/products/import/${preview.id}/confirm`, { method: 'POST', timeoutMs: 180000 })).data;
      setResult(done);
      toast.success(`${done.created} added, ${done.updated} updated.`);
      onDone();
    } catch (e) {
      setError(adminErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  const ready = preview ? preview.totals.create + preview.totals.update : 0;
  const problems = preview?.rows.filter((row) => row.status === 'invalid') ?? [];

  return (
    <Dialog open wide title="Import products" onClose={onClose} busy={busy}>
      {result ? (
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-4">
            <StatCard label="Products added" value={result.created} />
            <StatCard label="Products updated" value={result.updated} />
            <StatCard label="Variants added" value={result.variants_created} />
            <StatCard label="Variants updated" value={result.variants_updated} />
          </div>
          {result.skipped.length > 0 && (
            <div role="status" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm">
              <p className="font-medium">{result.skipped.length} rows were not imported:</p>
              <ul className="mt-1 list-disc ps-5">
                {result.skipped.map((s) => <li key={s.row}>Row {s.row}: {s.reason}</li>)}
              </ul>
            </div>
          )}
          <div className="flex justify-end"><Button variant="primary" onClick={onClose}>Done</Button></div>
        </div>
      ) : preview === null ? (
        <form onSubmit={check} className="space-y-4" noValidate>
          <p className="text-sm text-slate-700">
            A CSV file (a spreadsheet saved as CSV) whose first row names the columns. A row with an <span className="font-mono">id</span> or the{' '}
            <span className="font-mono">sku</span> of an existing product updates it — an empty cell keeps the current value; other rows add products. Variants are rows with{' '}
            <span className="font-mono">parent_sku</span> and <span className="font-mono">options</span> such as “Size: M; Colour: Red”. Lists (tags, categories) are separated by “;”. A
            category may be a path, “Clothing &gt; Men”. Up to 2,000 rows, 4 MB. Nothing is saved until you confirm the check.
          </p>
          <p className="text-sm text-slate-700">Tip: export your products first — the exported file has the same columns and can be edited and imported back.</p>
          <a href={`data:text/csv;charset=utf-8,${encodeURIComponent(PRODUCT_IMPORT_TEMPLATE)}`} download="products-template.csv" className={`inline-block rounded text-sm font-medium text-indigo-700 underline ${FOCUS_RING}`}>
            Download an example file
          </a>
          <div>
            <label htmlFor="product-import" className="block text-sm font-medium text-slate-700">CSV file</label>
            <input id="product-import" ref={file} type="file" accept=".csv,text/csv" className="mt-1 block w-full text-sm" />
          </div>
          {error && <FormError message={error} />}
          <div className="flex justify-end gap-2">
            <Button onClick={onClose} disabled={busy}>Cancel</Button>
            <Button type="submit" variant="primary" busy={busy} busyLabel="Checking…">Check the file</Button>
          </div>
        </form>
      ) : (
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-4">
            <StatCard label="Rows" value={preview.totals.rows} hint={preview.totals.variants > 0 ? `${preview.totals.variants} of them variants` : undefined} />
            <StatCard label="Will be added" value={preview.totals.create} />
            <StatCard label="Will be updated" value={preview.totals.update} />
            <StatCard label="With problems" value={preview.totals.invalid} hint="Skipped." />
          </div>
          {(preview.new_brands.length > 0 || preview.new_categories.length > 0) && (
            <div className="rounded-md border border-slate-200 p-3 text-sm">
              {preview.new_brands.length > 0 && <p><span className="font-medium">New brands:</span> {preview.new_brands.join(', ')}</p>}
              {preview.new_categories.length > 0 && <p><span className="font-medium">New categories:</span> {preview.new_categories.join(', ')}</p>}
            </div>
          )}
          {preview.notices.map((notice) => <p key={notice} role="status" className="rounded-md bg-amber-50 p-2 text-sm text-amber-900">{notice}</p>)}
          {problems.length > 0 && (
            <div className="max-h-72 overflow-y-auto rounded-md border border-slate-200">
              <table className="w-full text-start text-sm">
                <caption className="sr-only">Rows that will not be imported</caption>
                <thead className="bg-slate-50 text-xs uppercase text-slate-600">
                  <tr><th scope="col" className="px-3 py-2 text-start">Row</th><th scope="col" className="px-3 py-2 text-start">Product</th><th scope="col" className="px-3 py-2 text-start">Why</th></tr>
                </thead>
                <tbody>
                  {problems.map((row) => (
                    <tr key={row.row} className="border-t border-slate-100">
                      <td className="px-3 py-2">{row.row}</td>
                      <td className="break-all px-3 py-2">{row.label || row.sku || '—'}</td>
                      <td className="px-3 py-2">{row.messages.join(' ')}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {error && <FormError message={error} />}
          <div className="flex justify-end gap-2">
            <Button onClick={() => setPreview(null)} disabled={busy}>Choose another file</Button>
            <Button variant="primary" onClick={confirm} busy={busy} busyLabel="Importing…" disabled={ready === 0}>
              Import {ready} row{ready === 1 ? '' : 's'}
            </Button>
          </div>
        </div>
      )}
    </Dialog>
  );
}
