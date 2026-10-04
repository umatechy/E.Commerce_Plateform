import { useId, useRef, useState } from 'react';
import Button from '@/Components/ui/Button';
import { FOCUS_RING } from '@/Components/ui/Button';
import { adminErrorMessage, adminFetch, wasCancelled } from '@/lib/adminApi';

export type MediaPurpose = 'logo' | 'favicon' | 'banner' | 'social';

/**
 * Phase B37: an image field that takes either an address or a JPG/PNG file
 * chosen from the computer. The file goes to POST /api/v1/store/media (the
 * server re-encodes it and keeps it in the store's own folder) and the field
 * then holds the address the server gave back: a path on this site for the
 * theme (`path`), or a full address where one is needed (`url`, e.g. social
 * sharing). The value is only saved with the form, like a typed address.
 */
export default function ImageUploadField({
  label,
  value,
  onChange,
  purpose,
  use = 'path',
  disabled = false,
  hint,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  purpose: MediaPurpose;
  use?: 'path' | 'url';
  disabled?: boolean;
  hint?: string;
}) {
  const id = useId();
  const picker = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function upload(file: File | undefined) {
    if (!file) return;
    if (!['image/jpeg', 'image/png'].includes(file.type)) {
      setError('Choose a JPG or PNG image.');

      return;
    }
    setBusy(true);
    setError(null);
    try {
      const body = new FormData();
      body.append('purpose', purpose);
      body.append('file', file);
      const result = await adminFetch<{ data: { path: string; url: string } }>('/store/media', { method: 'POST', body, timeoutMs: 60000 });
      onChange(use === 'url' ? result.data.url : result.data.path);
    } catch (e) {
      if (!wasCancelled(e)) setError(adminErrorMessage(e));
    } finally {
      setBusy(false);
      if (picker.current) picker.current.value = '';
    }
  }

  return (
    <div>
      <label htmlFor={id} className="block text-sm font-medium text-slate-700">
        {label} <span className="font-normal text-slate-500">(optional)</span>
      </label>
      <div className="mt-1 flex items-start gap-3">
        <div className="flex h-14 w-14 flex-none items-center justify-center overflow-hidden rounded border border-slate-200 bg-slate-50">
          {value ? <img src={value} alt={`${label} preview`} className="max-h-full max-w-full object-contain" /> : <span className="text-[10px] text-slate-400">None</span>}
        </div>
        <div className="min-w-0 flex-1 space-y-2">
          <input
            id={id}
            value={value}
            disabled={disabled}
            onChange={(e) => onChange(e.target.value)}
            placeholder="https://… or choose a file"
            className={`block w-full rounded-md border border-slate-300 px-3 py-2 text-sm ${FOCUS_RING}`}
            aria-describedby={`${id}-hint`}
          />
          <div className="flex flex-wrap items-center gap-2">
            <input ref={picker} type="file" accept="image/jpeg,image/png" className="sr-only" aria-label={`Choose a file for ${label}`} onChange={(e) => void upload(e.target.files?.[0])} disabled={disabled || busy} />
            <Button size="sm" disabled={disabled} busy={busy} busyLabel="Uploading…" onClick={() => picker.current?.click()}>
              Choose file (JPG, PNG)
            </Button>
            {value && !disabled && (
              <Button size="sm" variant="ghost" onClick={() => onChange('')}>
                Remove<span className="sr-only"> {label}</span>
              </Button>
            )}
          </div>
          <p id={`${id}-hint`} className="text-xs text-slate-500">
            {hint ?? 'Up to 5 MB. Saved with the form.'}
          </p>
          {error && (
            <p role="alert" className="text-sm font-medium text-red-700">
              {error}
            </p>
          )}
        </div>
      </div>
    </div>
  );
}
