import { Link } from '@inertiajs/react';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { AdminCrumbs } from '@/Components/AdminPage';
import EmptyState from '@/Components/EmptyState';
import ErrorState from '@/Components/ErrorState';
import LoadingState from '@/Components/LoadingState';
import RequesterTicket from '@/Components/Support/RequesterTicket';
import { AdminApiError, adminErrorMessage, adminFetch } from '@/lib/adminApi';
import { categoryLabel, PLATFORM_CATEGORIES, statusLabel, timeAgo, type SupportCategory, type TicketDetail, type TicketPage, type TicketSummary } from '@/lib/support';

const input = 'w-full rounded border border-gray-300 bg-white px-3 py-2 text-sm';
const API = '/platform-support/tickets';

/**
 * Module 34 (Phase B26) — a merchant's own requests to the platform's
 * support team (billing, technical problems, their account). The store
 * owner can use it; other staff need the support.platform permission.
 */
export default function Platform() {
  const [list, setList] = useState<TicketPage<TicketSummary> | null>(null);
  const [selected, setSelected] = useState<string | null>(() => {
    const id = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('ticket');

    return id && /^[0-9A-Za-z]{26}$/.test(id) ? id : null;
  });
  const [ticket, setTicket] = useState<TicketDetail | null>(null);
  const [composing, setComposing] = useState(false);
  const [form, setForm] = useState({ subject: '', category: 'technical' as SupportCategory, message: '' });
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    adminFetch<{ data: TicketPage<TicketSummary> }>(API, { query: { status: 'all' } })
      .then((res) => setList(res.data))
      .catch((e) => setError(e instanceof AdminApiError && e.status === 403 ? 'Only the store owner, or staff allowed to contact the platform, can see these requests.' : adminErrorMessage(e)));
  }, []);

  useEffect(load, [load]);

  useEffect(() => {
    if (!selected) return;
    adminFetch<{ data: TicketDetail }>(`${API}/${encodeURIComponent(selected)}`)
      .then((res) => setTicket(res.data))
      .catch((e) => setError(adminErrorMessage(e)));
  }, [selected]);

  function open(id: string) {
    setComposing(false);
    setSelected(id);
    window.history.replaceState(window.history.state, '', `${window.location.pathname}?ticket=${id}`);
  }

  function changed(next: TicketDetail) {
    setTicket(next);
    load();
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setFormError(null);
    try {
      const res = await adminFetch<{ data: TicketDetail }>(API, { method: 'POST', body: form });
      setForm({ subject: '', category: 'technical', message: '' });
      setTicket(res.data);
      open(res.data.id);
      load();
    } catch (e) {
      setFormError(adminErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  const call = (suffix: string, body: Record<string, unknown>) =>
    adminFetch<{ data: TicketDetail }>(`${API}/${encodeURIComponent(ticket?.id ?? '')}${suffix}`, { method: 'POST', body }).then((res) => res.data);

  return (
    <AuthenticatedLayout>
      <AdminCrumbs trail={[{ label: 'Help from the platform' }]} />
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Help from the platform</h1>
          <p className="text-sm text-gray-500">Questions about your plan, billing or a technical problem go to our team here.</p>
        </div>
        <div className="flex items-center gap-4">
          <Link href="/support" className="text-sm text-blue-700 hover:underline">
            ← Customer support inbox
          </Link>
          <button type="button" onClick={() => setComposing(true)} className="rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white">
            New request
          </button>
        </div>
      </div>

      {error && <ErrorState message={error} />}
      {!error && (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
          <section aria-label="Your requests">
            {!list && <LoadingState />}
            {list?.tickets.length === 0 && <EmptyState title="No requests yet" description="When you ask our team for help, the conversation appears here." />}
            {list && list.tickets.length > 0 && (
              <ul className="divide-y rounded border border-gray-200 bg-white">
                {list.tickets.map((t) => (
                  <li key={t.id}>
                    <button
                      type="button"
                      onClick={() => open(t.id)}
                      aria-current={selected === t.id}
                      className={`block w-full px-3 py-3 text-left hover:bg-gray-50 ${selected === t.id && !composing ? 'bg-blue-50' : ''}`}
                    >
                      <div className="flex items-center gap-2 text-xs text-gray-500">
                        <span className="font-mono">{t.number}</span>
                        <span>{statusLabel(t.status)}</span>
                        <span className="ml-auto">{timeAgo(t.updated_at)}</span>
                      </div>
                      <p className="mt-1 truncate font-medium text-gray-900">{t.subject}</p>
                      <p className="text-xs text-gray-500">{categoryLabel(t.category)}</p>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className="rounded border border-gray-200 bg-white p-4">
            {composing ? (
              <form onSubmit={submit} className="space-y-4" aria-label="New request">
                <h2 className="font-semibold">New request to the platform</h2>
                <label className="block text-sm">
                  <span className="mb-1 block text-gray-600">Topic</span>
                  <select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value as SupportCategory })} className={input} name="category">
                    {PLATFORM_CATEGORIES.map((c) => (
                      <option key={c} value={c}>
                        {categoryLabel(c)}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="block text-sm">
                  <span className="mb-1 block text-gray-600">Subject</span>
                  <input required maxLength={200} value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} className={input} name="subject" />
                </label>
                <label className="block text-sm">
                  <span className="mb-1 block text-gray-600">Describe the problem</span>
                  <textarea required rows={6} maxLength={10000} value={form.message} onChange={(e) => setForm({ ...form, message: e.target.value })} className={input} name="message" />
                </label>
                {formError && (
                  <p role="alert" className="text-sm text-red-700">
                    {formError}
                  </p>
                )}
                <div className="flex gap-3">
                  <button type="submit" disabled={busy} className="rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                    {busy ? 'Sending…' : 'Send request'}
                  </button>
                  <button type="button" onClick={() => setComposing(false)} className="rounded border border-gray-300 px-4 py-2 text-sm">
                    Cancel
                  </button>
                </div>
              </form>
            ) : ticket && ticket.id === selected ? (
              <RequesterTicket
                ticket={ticket}
                tone="admin"
                onChange={changed}
                reply={(body) => call('/messages', { body })}
                resolve={() => call('/resolve', {})}
                rate={(rating, comment) => call('/rating', { rating, comment: comment || null })}
                describeError={(e) => adminErrorMessage(e)}
              />
            ) : selected ? (
              <LoadingState />
            ) : (
              <EmptyState title="Select a request" description="Or start a new one." />
            )}
          </section>
        </div>
      )}
    </AuthenticatedLayout>
  );
}
