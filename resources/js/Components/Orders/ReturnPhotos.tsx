import { useEffect, useRef, useState } from 'react';
import Button from '@/Components/ui/Button';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { Card } from '@/Components/ui/Page';
import { useAction } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import type { ReturnPhoto, ReturnRecord } from '@/lib/returns';

/**
 * Module 09 §45 "Images" (Phase B34): the photos of a return, for staff.
 * The files are private: each is fetched from the API with the session
 * and shown from an object URL, so no public address of it exists. The
 * server re-encodes every upload and decides who may add or remove one.
 */
const WHO: Record<string, string> = { customer: 'Customer', guest: 'Customer (guest)', staff: 'Your team' };

function Photo({ returnId, photo }: { returnId: string; photo: ReturnPhoto }) {
  const [url, setUrl] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let made: string | null = null;
    let gone = false;
    fetch(`/api/v1/returns/${returnId}/photos/${photo.id}`, { credentials: 'same-origin', headers: { Accept: 'image/*', 'X-Requested-With': 'XMLHttpRequest' } })
      .then((response) => (response.ok ? response.blob() : Promise.reject(new Error(String(response.status)))))
      .then((blob) => {
        if (gone) return;
        made = URL.createObjectURL(blob);
        setUrl(made);
      })
      .catch(() => setFailed(true));

    return () => {
      gone = true;
      if (made) URL.revokeObjectURL(made);
    };
  }, [returnId, photo.id]);

  if (failed) return <span className="flex h-28 w-28 items-center justify-center rounded-md border border-slate-200 text-xs text-slate-500">Could not load</span>;

  return url ? (
    <a href={url} target="_blank" rel="noreferrer" className="block rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
      <img src={url} alt={`Photo added by ${WHO[photo.uploaded_by]?.toLowerCase() ?? 'someone'} (opens full size)`} className="h-28 w-28 rounded-md border border-slate-200 object-cover" />
    </a>
  ) : (
    <span className="block h-28 w-28 animate-pulse rounded-md bg-slate-100" aria-hidden="true" />
  );
}

export default function ReturnPhotos({ record, canManage, onChanged }: { record: ReturnRecord; canManage: boolean; onChanged: (record: ReturnRecord) => void }) {
  const photos = record.photos ?? [];
  const picker = useRef<HTMLInputElement>(null);
  const [removing, setRemoving] = useState<ReturnPhoto | null>(null);
  const { busy, run } = useAction();

  function upload(file: File | undefined) {
    if (!file) return;
    const body = new FormData();
    body.append('photo', file);
    void run('upload', () => adminFetch<{ data: ReturnRecord }>(`/returns/${record.id}/photos`, { method: 'POST', body }), { success: 'Photo added.' }).then((result) => {
      if (picker.current) picker.current.value = '';
      if (result) onChanged(result.data);
    });
  }

  return (
    <Card title="Photos" description="Seen by your team and by the customer who asked. They are never public.">
      {photos.length === 0 ? (
        <p className="text-sm text-slate-600">No photos.</p>
      ) : (
        <ul className="flex flex-wrap gap-3">
          {photos.map((photo) => (
            <li key={photo.id} className="text-xs text-slate-600">
              <Photo returnId={record.id} photo={photo} />
              <span className="mt-1 flex items-center justify-between gap-1">
                {WHO[photo.uploaded_by] ?? photo.uploaded_by}
                {canManage && <Button size="sm" variant="ghost" onClick={() => setRemoving(photo)}>Remove<span className="sr-only"> this photo</span></Button>}
              </span>
            </li>
          ))}
        </ul>
      )}
      {canManage && record.can.add_photos && (
        <div className="mt-3">
          <input ref={picker} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" aria-label="Choose a photo to add" onChange={(event) => upload(event.target.files?.[0])} />
          <Button size="sm" busy={busy === 'upload'} busyLabel="Adding…" onClick={() => picker.current?.click()}>Add a photo</Button>
          <span className="ml-2 text-xs text-slate-500">JPEG, PNG or WebP, up to 5 MB.</span>
        </div>
      )}
      <ConfirmDialog
        open={removing !== null}
        title="Remove this photo?"
        confirmLabel="Remove photo"
        busy={busy === 'remove'}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing &&
          void run('remove', () => adminFetch<{ data: ReturnRecord }>(`/returns/${record.id}/photos/${removing.id}`, { method: 'DELETE' }), { success: 'Photo removed.' }).then((result) => {
            setRemoving(null);
            if (result) onChanged(result.data);
          })
        }
      >
        <p>The file is deleted for good.</p>
      </ConfirmDialog>
    </Card>
  );
}
