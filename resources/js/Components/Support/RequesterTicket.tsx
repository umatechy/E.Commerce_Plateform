import { formatDateTime } from '@/lib/datetime';
import { useState, type FormEvent } from 'react';
import MessageThread from './MessageThread';
import { categoryLabel, statusLabel, type TicketDetail } from '@/lib/support';
import { useT } from '@/Storefront/i18n';

type Tone = 'storefront' | 'admin';

const STYLES: Record<Tone, { input: string; primary: string; secondary: string; muted: string; error: string; card: string; badge: string }> = {
  storefront: {
    input: 'w-full rounded-sf border border-sf-border bg-sf-bg px-3 py-2',
    primary: 'rounded-sf bg-sf-primary px-4 py-2 font-medium text-white disabled:opacity-50',
    secondary: 'rounded-sf border border-sf-border px-4 py-2 text-sm font-medium disabled:opacity-50',
    muted: 'text-sf-muted',
    error: 'text-sf-error',
    card: 'rounded-sf border border-sf-border p-4',
    badge: 'rounded-sf bg-sf-surface px-2 py-1 text-xs font-medium',
  },
  admin: {
    input: 'w-full rounded border border-gray-300 bg-white px-3 py-2',
    primary: 'rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50',
    secondary: 'rounded border border-gray-300 bg-white px-4 py-2 text-sm font-medium disabled:opacity-50',
    muted: 'text-gray-500',
    error: 'text-red-700',
    card: 'rounded border border-gray-200 bg-white p-4',
    badge: 'rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700',
  },
};

/**
 * The requester's side of one ticket: the conversation, a reply box,
 * "mark as resolved" and, once resolved, a one-time rating. Used by the
 * shopper's account, a guest's private link and a merchant's requests
 * to the platform; the page supplies the API calls. What is allowed
 * (can_reply / can_resolve / can_rate) comes from the server.
 */
export default function RequesterTicket({
  ticket,
  tone,
  onChange,
  reply,
  resolve,
  rate,
  describeError,
  newRequestHref,
}: {
  ticket: TicketDetail;
  tone: Tone;
  onChange: (ticket: TicketDetail) => void;
  reply: (body: string) => Promise<TicketDetail>;
  resolve: () => Promise<TicketDetail>;
  rate: (rating: number, comment: string) => Promise<TicketDetail>;
  describeError: (error: unknown) => string;
  newRequestHref?: string;
}) {
  const s = STYLES[tone];
  const t = useT(); // Phase B38: the shopper's language on storefront pages, English in the admin
  const [body, setBody] = useState('');
  const [rating, setRating] = useState(0);
  const [comment, setComment] = useState('');
  const [busy, setBusy] = useState<'reply' | 'resolve' | 'rate' | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function run(kind: 'reply' | 'resolve' | 'rate', action: () => Promise<TicketDetail>, after?: () => void) {
    setBusy(kind);
    setError(null);
    try {
      onChange(await action());
      after?.();
    } catch (e) {
      setError(describeError(e));
    } finally {
      setBusy(null);
    }
  }

  function submitReply(event: FormEvent) {
    event.preventDefault();
    if (body.trim() === '') return;
    run('reply', () => reply(body), () => setBody(''));
  }

  function submitRating(event: FormEvent) {
    event.preventDefault();
    if (rating < 1) return;
    run('rate', () => rate(rating, comment));
  }

  return (
    <div className="space-y-6">
      <div>
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <span className={`font-mono ${s.muted}`}>{ticket.number}</span>
          <span className={s.badge} data-testid="ticket-status">
            {t(statusLabel(ticket.status))}
          </span>
          <span className={s.muted}>{t(categoryLabel(ticket.category))}</span>
          {ticket.order && <span className={s.muted}>· {t('Order')} {ticket.order.number}</span>}
        </div>
        <h2 className="mt-2 text-2xl font-bold">{ticket.subject}</h2>
        <p className={`mt-1 text-sm ${s.muted}`}>{t('Opened {date}', { date: formatDateTime(ticket.created_at) })}</p>
      </div>

      <MessageThread messages={ticket.messages} perspective="requester" tone={tone} />

      {error && (
        <p role="alert" className={`text-sm ${s.error}`}>
          {error}
        </p>
      )}

      {ticket.can_reply ? (
        <form onSubmit={submitReply} className="space-y-3">
          <label className="block text-sm">
            <span className={`mb-1 block ${s.muted}`}>{ticket.status === 'resolved' ? t('Reply (this reopens the request)') : t('Your reply')}</span>
            <textarea required rows={4} maxLength={10000} value={body} onChange={(e) => setBody(e.target.value)} className={s.input} name="reply" />
          </label>
          <div className="flex flex-wrap gap-3">
            <button type="submit" disabled={busy !== null || body.trim() === ''} className={s.primary}>
              {busy === 'reply' ? t('Sending…') : t('Send reply')}
            </button>
            {ticket.can_resolve && (
              <button type="button" disabled={busy !== null} onClick={() => run('resolve', resolve)} className={s.secondary}>
                {busy === 'resolve' ? t('Saving…') : t('My issue is solved')}
              </button>
            )}
          </div>
        </form>
      ) : (
        <div className={`${s.card} text-sm`}>
          {t('This request is closed.')}{' '}
          {newRequestHref && (
            <a href={newRequestHref} className="font-medium underline">
              {t('Open a new request')}
            </a>
          )}
        </div>
      )}

      {ticket.can_rate && (
        <form onSubmit={submitRating} className={`${s.card} space-y-3`} aria-label={t('Rate our support')}>
          <p className="font-semibold">{t('How did we do?')}</p>
          <div role="radiogroup" aria-label={t('Rating')} className="flex gap-1">
            {[1, 2, 3, 4, 5].map((value) => (
              <button
                key={value}
                type="button"
                role="radio"
                aria-checked={rating === value}
                aria-label={t('{value} out of 5', { value })}
                onClick={() => setRating(value)}
                className={`text-2xl leading-none ${value <= rating ? 'text-amber-500' : 'text-gray-300'}`}
              >
                ★
              </button>
            ))}
          </div>
          <textarea rows={2} maxLength={1000} placeholder={t('Anything we could do better? (optional)')} value={comment} onChange={(e) => setComment(e.target.value)} className={s.input} />
          <button type="submit" disabled={busy !== null || rating < 1} className={s.primary}>
            {busy === 'rate' ? t('Sending…') : t('Send rating')}
          </button>
        </form>
      )}

      {ticket.satisfaction && (
        <p className={`text-sm ${s.muted}`}>
          {t('You rated this request {rating} out of 5. Thank you!', { rating: ticket.satisfaction.rating })}
          {ticket.satisfaction.comment ? ` “${ticket.satisfaction.comment}”` : ''}
        </p>
      )}
    </div>
  );
}
