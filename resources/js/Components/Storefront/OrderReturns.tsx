import { Link } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { formatDate } from '@/lib/datetime';
import { formatMoney } from '@/lib/money';
import { errorMessage, storefrontFetch, storefrontObjectUrl, validationErrors } from '@/Storefront/api';
import type { Shell } from '@/Storefront/types';
import { useT, type Translate } from '@/Storefront/i18n';
import { REASON_LABELS, RESOLUTION_LABELS, RETURN_REASONS, RETURN_RESOLUTIONS, RETURN_STATUS_LABELS, type Returnable, type ReturnPhoto, type ReturnRecord } from '@/lib/returns';

/**
 * Module 09 §45 (Phases B33–B34): the returns of one order, for the
 * person whose order it is — a signed-in customer on their order page,
 * or a guest holding the link sent to the order's email.
 *
 * What can be returned, whether the store takes requests here, the
 * return period and every amount come from the server. The request form
 * is shown only when the store allows it, else the person is pointed to
 * the store's support. Photos are private: they are read through the
 * API and shown from an object URL, never from a public address.
 */
export type ReturnsApi = {
  /** GET: what can be returned and the returns so far. POST to `create`: a new request. */
  returnable: string;
  create: string;
  /** The path of one return's action or photo, e.g. `${one(id)}/cancel`. */
  one: (returnId: string) => string;
  /** Extra headers on every call (a guest's link token). */
  headers?: Record<string, string>;
  /** Where "contact the store" leads. */
  contactHref: string;
};

/** The API of a signed-in customer's order. */
export function customerReturnsApi(shell: Shell, orderId: string): ReturnsApi {
  const order = encodeURIComponent(orderId);

  return {
    returnable: `/customer/orders/${order}/returnable`,
    create: `/customer/orders/${order}/returns`,
    one: (id) => `/customer/returns/${id}`,
    contactHref: `${shell.base_path}/account/support/new?order=${order}`,
  };
}

/** The API of a guest's order, opened by the token of the emailed link. */
export function guestReturnsApi(shell: Shell, token: string): ReturnsApi {
  return {
    returnable: '/storefront/returns/guest',
    create: '/storefront/returns/guest',
    one: (id) => `/storefront/returns/guest/${id}`,
    headers: { 'X-Return-Token': token },
    contactHref: `${shell.base_path}/contact`,
  };
}

function newKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

const PHOTO_TYPES = 'image/jpeg,image/png,image/webp';

async function uploadPhotos(shell: Shell, api: ReturnsApi, returnId: string, files: File[], t: Translate): Promise<string | null> {
  for (const file of files) {
    const body = new FormData();
    body.append('photo', file);
    try {
      await storefrontFetch(shell, `${api.one(returnId)}/photos`, { method: 'POST', body, headers: api.headers });
    } catch (e) {
      return t('"{file}" could not be added: {reason}', { file: file.name, reason: validationErrors(e).photo?.[0] ?? errorMessage(e) });
    }
  }

  return null;
}

/** One private photo, loaded through the API. */
function Photo({ shell, api, returnId, photo }: { shell: Shell; api: ReturnsApi; returnId: string; photo: ReturnPhoto }) {
  const [url, setUrl] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);
  const t = useT();
  const path = `${api.one(returnId)}/photos/${photo.id}`;
  const token = api.headers?.['X-Return-Token'];

  useEffect(() => {
    let made: string | null = null;
    let gone = false;
    storefrontObjectUrl(shell, path, token ? { 'X-Return-Token': token } : {})
      .then((objectUrl) => {
        if (gone) {
          URL.revokeObjectURL(objectUrl);

          return;
        }
        made = objectUrl;
        setUrl(objectUrl);
      })
      .catch(() => setFailed(true));

    return () => {
      gone = true;
      if (made) URL.revokeObjectURL(made);
    };
  }, [shell, path, token]);

  if (failed) return <span className="flex h-20 w-20 items-center justify-center rounded-sf border border-sf-border text-xs text-sf-muted">{t('Not available')}</span>;

  return url ? (
    <a href={url} target="_blank" rel="noreferrer" className="block">
      <img src={url} alt={t('Photo attached to this return')} width={80} height={80} className="h-20 w-20 rounded-sf border border-sf-border object-cover" />
    </a>
  ) : (
    <span className="block h-20 w-20 animate-pulse rounded-sf bg-sf-surface" aria-hidden="true" />
  );
}

function RequestForm({ shell, api, info, onDone }: { shell: Shell; api: ReturnsApi; info: Returnable; onDone: (note: string | null) => void }) {
  const lines = info.lines.filter((line) => line.returnable > 0);
  const maxPhotos = info.max_photos ?? 0;
  const [quantities, setQuantities] = useState<Record<number, string>>({});
  const [resolution, setResolution] = useState('refund');
  const [reason, setReason] = useState('');
  const [description, setDescription] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [key] = useState(newKey);
  const t = useT();

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    const items = lines.map((line) => ({ order_item_id: line.order_item_id, quantity: Number(quantities[line.order_item_id] ?? '0') })).filter((item) => item.quantity > 0);
    if (items.length === 0) {
      setError(t('Enter how many of at least one item you want to return.'));

      return;
    }
    if (reason === '') {
      setError(t('Please choose a reason.'));

      return;
    }
    if (files.length > maxPhotos) {
      setError(t('You can add up to {count} photos.', { count: maxPhotos }));

      return;
    }
    setBusy(true);
    setError(null);
    try {
      const created = await storefrontFetch<{ data: ReturnRecord }>(shell, api.create, {
        method: 'POST',
        headers: api.headers,
        body: { items, resolution, reason, description: description === '' ? null : description, idempotency_key: key },
      });
      // The request exists now; a photo that fails is reported, not a reason to lose the request.
      onDone(await uploadPhotos(shell, api, created.data.id, files, t));
    } catch (e) {
      const fields = validationErrors(e);
      setError(Object.values(fields)[0]?.[0] ?? errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} className="space-y-4 rounded-sf border border-sf-border p-4" noValidate>
      <h3 className="font-semibold">{t('Request a return')}</h3>
      <p className="text-sm text-sf-muted">{t('You can ask within {days} days of delivery. The store checks your request and tells you how to send the items back.', { days: info.window_days })}</p>
      {error && <p className="rounded-sf bg-red-50 p-3 text-sm text-sf-error" role="alert">{error}</p>}
      {lines.map((line) => (
        <label key={line.order_item_id} className="flex items-center justify-between gap-3 text-sm">
          <span>
            {line.name}
            <span className="block text-sf-muted">{t('Up to {count}', { count: line.returnable })}</span>
          </span>
          <input type="number" min={0} max={line.returnable} inputMode="numeric" aria-label={t('How many of {name} to return', { name: line.name })} value={quantities[line.order_item_id] ?? ''} onChange={(e) => setQuantities((current) => ({ ...current, [line.order_item_id]: e.target.value }))} className="w-24 rounded-sf border border-sf-border px-3 py-2" />
        </label>
      ))}
      <label className="block text-sm">
        <span className="mb-1 block text-sf-muted">{t('What would you like?')}</span>
        <select value={resolution} onChange={(e) => setResolution(e.target.value)} className="w-full rounded-sf border border-sf-border px-3 py-2">
          {RETURN_RESOLUTIONS.map((value) => <option key={value} value={value}>{t(RESOLUTION_LABELS[value])}</option>)}
        </select>
      </label>
      <label className="block text-sm">
        <span className="mb-1 block text-sf-muted">{t('Reason')}</span>
        <select value={reason} onChange={(e) => setReason(e.target.value)} required className="w-full rounded-sf border border-sf-border px-3 py-2">
          <option value="">{t('Choose a reason')}</option>
          {RETURN_REASONS.map((value) => <option key={value} value={value}>{t(REASON_LABELS[value])}</option>)}
        </select>
      </label>
      <label className="block text-sm">
        <span className="mb-1 block text-sf-muted">{t('Tell us more (optional)')}</span>
        <textarea value={description} onChange={(e) => setDescription(e.target.value)} maxLength={2000} rows={3} className="w-full rounded-sf border border-sf-border px-3 py-2" />
      </label>
      {maxPhotos > 0 && (
        <label className="block text-sm">
          <span className="mb-1 block text-sf-muted">{t('Photos (optional, up to {count}; JPEG, PNG or WebP)', { count: maxPhotos })}</span>
          <input type="file" accept={PHOTO_TYPES} multiple onChange={(e) => setFiles(Array.from(e.target.files ?? []))} className="block w-full text-sm" />
          {files.length > 0 && <span className="mt-1 block text-sf-muted">{t('{count} chosen. Only the store sees them.', { count: files.length })}</span>}
        </label>
      )}
      <button type="submit" disabled={busy} className="sf-btn rounded-sf bg-sf-primary px-4 py-2 font-semibold text-white disabled:opacity-60">
        {busy ? t('Sending…') : t('Send request')}
      </button>
    </form>
  );
}

function ReturnCard({ shell, api, record, maxPhotos, onChanged }: { shell: Shell; api: ReturnsApi; record: ReturnRecord; maxPhotos: number; onChanged: () => void }) {
  const [carrier, setCarrier] = useState('');
  const [tracking, setTracking] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const picker = useRef<HTMLInputElement>(null);
  const photos = record.photos ?? [];
  const t = useT();

  function act(step: 'shipped' | 'cancel', body: Record<string, unknown> = {}) {
    setBusy(step);
    setError(null);
    storefrontFetch(shell, `${api.one(record.id)}/${step}`, { method: 'POST', body, headers: api.headers })
      .then(onChanged)
      .catch((e) => setError(errorMessage(e)))
      .finally(() => setBusy(null));
  }

  function addPhotos(files: File[]) {
    if (files.length === 0) return;
    setBusy('photos');
    setError(null);
    void uploadPhotos(shell, api, record.id, files, t).then((problem) => {
      setError(problem);
      setBusy(null);
      if (picker.current) picker.current.value = '';
      onChanged();
    });
  }

  return (
    <li className="rounded-sf border border-sf-border p-4 text-sm">
      <p className="flex flex-wrap items-center justify-between gap-2">
        <span className="font-medium">{t('Return')} {record.return_number}</span>
        <span>{t(RETURN_STATUS_LABELS[record.status] ?? record.status)}</span>
      </p>
      <p className="mt-1 text-sf-muted">
        {(record.items ?? []).map((item) => `${item.quantity} × ${item.name}`).join(', ')} · {t(RESOLUTION_LABELS[record.resolution] ?? record.resolution).split(' (')[0]} · {t('asked {date}', { date: formatDate(record.created_at) })}
      </p>
      {record.decision_note && <p className="mt-2">{t('From the store:')} {record.decision_note}</p>}
      {record.refunded_at && record.refunded_minor > 0 && <p className="mt-2 font-medium">{t('Refunded: {amount}', { amount: formatMoney(record.refunded_minor, record.currency) })}</p>}
      {record.replacement_order && <p className="mt-2">{t('Your new order:')} {record.replacement_order.order_number}</p>}
      {photos.length > 0 && (
        <ul className="mt-3 flex flex-wrap gap-2" aria-label={t('Photos of this return')}>
          {photos.map((photo) => <li key={photo.id}><Photo shell={shell} api={api} returnId={record.id} photo={photo} /></li>)}
        </ul>
      )}
      {error && <p className="mt-2 text-sf-error" role="alert">{error}</p>}

      {record.can.add_photos && photos.length < maxPhotos && (
        <label className="mt-3 block">
          <span className="mb-1 block text-sf-muted">{busy === 'photos' ? t('Adding…') : t('Add photos (up to {count} more)', { count: maxPhotos - photos.length })}</span>
          <input ref={picker} type="file" accept={PHOTO_TYPES} multiple disabled={busy !== null} onChange={(e) => addPhotos(Array.from(e.target.files ?? []).slice(0, maxPhotos - photos.length))} className="block w-full text-sm" />
        </label>
      )}
      {record.status === 'approved' && (
        <div className="mt-3 space-y-2 border-t border-sf-border pt-3">
          <p>{t('Sent the items back? Tell the store, with the courier and tracking number if you have them.')}</p>
          <div className="grid gap-2 sm:grid-cols-2">
            <input aria-label={t('Courier')} placeholder={t('Courier (optional)')} value={carrier} onChange={(e) => setCarrier(e.target.value)} maxLength={64} className="rounded-sf border border-sf-border px-3 py-2" />
            <input aria-label={t('Tracking number')} placeholder={t('Tracking number (optional)')} value={tracking} onChange={(e) => setTracking(e.target.value)} maxLength={128} className="rounded-sf border border-sf-border px-3 py-2" />
          </div>
          <button type="button" disabled={busy !== null} onClick={() => act('shipped', { carrier: carrier || null, tracking_number: tracking || null })} className="sf-btn rounded-sf bg-sf-primary px-4 py-2 font-semibold text-white disabled:opacity-60">
            {busy === 'shipped' ? t('Saving…') : t('I have sent the items')}
          </button>
        </div>
      )}
      {record.can.cancel && (
        <button type="button" disabled={busy !== null} onClick={() => window.confirm(t('Withdraw this return request?')) && act('cancel')} className="mt-3 text-sf-accent underline disabled:opacity-60">
          {busy === 'cancel' ? t('Withdrawing…') : t('Withdraw this request')}
        </button>
      )}
    </li>
  );
}

export default function OrderReturns({ shell, api, onInvalid }: { shell: Shell; api: ReturnsApi; onInvalid?: (message: string) => void }) {
  const [info, setInfo] = useState<Returnable | null>(null);
  const [sent, setSent] = useState(false);
  const [photoProblem, setPhotoProblem] = useState<string | null>(null);
  const t = useT();
  const { returnable: path, headers } = api;
  const token = headers?.['X-Return-Token'];

  const load = useCallback(() => {
    storefrontFetch<{ data: Returnable }>(shell, path, { headers: token ? { 'X-Return-Token': token } : undefined })
      .then((res) => setInfo(res.data))
      .catch((e) => {
        setInfo(null);
        // On the order page the page itself reports a load failure; a guest link reports here.
        onInvalid?.(errorMessage(e));
      });
    // `onInvalid` is the caller's handler of the moment; it is not a reason to load again.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [shell, path, token]);
  useEffect(load, [load]);

  if (info === null) return null;
  const returns = info.returns ?? [];
  const delivered = info.lines.some((line) => line.delivered > 0);
  const returnable = info.lines.some((line) => line.returnable > 0);
  if (returns.length === 0 && !delivered) {
    return onInvalid ? <p className="text-sm text-sf-muted">{t('Nothing of this order has been delivered yet, so there is nothing to return.')}</p> : null;
  }

  return (
    <div>
      <h2 className="mb-2 font-semibold">{t('Returns')}</h2>
      {sent && <p className="mb-3 rounded-sf bg-sf-surface p-3 text-sm" role="status">{t('Your request was sent. The store will answer by email.')}</p>}
      {photoProblem && <p className="mb-3 rounded-sf bg-red-50 p-3 text-sm text-sf-error" role="alert">{photoProblem} {t('You can add it to the request below.')}</p>}
      {returns.length > 0 && (
        <ul className="mb-4 space-y-3">
          {returns.map((record) => <ReturnCard key={record.id} shell={shell} api={api} record={record} maxPhotos={info.max_photos ?? 0} onChanged={load} />)}
        </ul>
      )}
      {info.blocked === null && info.enabled && returnable && (
        <RequestForm
          // A new form (and a new idempotency key) after each request.
          key={returns.length}
          shell={shell}
          api={api}
          info={info}
          onDone={(problem) => {
            setSent(true);
            setPhotoProblem(problem);
            load();
          }}
        />
      )}
      {info.blocked === null && delivered && !(info.enabled && returnable) && returns.every((record) => ['rejected', 'cancelled', 'completed'].includes(record.status)) && (
        <p className="text-sm text-sf-muted">
          {info.enabled ? t('Items can be returned within {days} days of delivery.', { days: info.window_days }) : t('To return an item,')}{' '}
          <Link href={api.contactHref} className="text-sf-accent">
            {info.enabled ? t('Contact the store about a later return') : t('contact the store')}
          </Link>
          .
        </p>
      )}
    </div>
  );
}
