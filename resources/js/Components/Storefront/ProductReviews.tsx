import { Link } from '@inertiajs/react';
import { FormEvent, useCallback, useEffect, useState } from 'react';
import Stars from './Stars';
import { errorMessage, storefrontFetch } from '@/Storefront/api';
import { loginHref } from '@/Storefront/account';
import type { Shell } from '@/Storefront/types';
import { useT } from '@/Storefront/i18n';

/**
 * Owner decision 15 (Module 05 §27): a product's reviews — the summary, the
 * approved reviews of verified buyers with the store's replies, and for a
 * customer who bought the product, their own review (new or changed; the
 * store approves it before others see it).
 */
export type ReviewSummary = { average: number; count: number; distribution: Record<string, number> };
type Review = { id: number; rating: number; title: string | null; body: string; author: string; verified_purchase: boolean; created_at: string; reply: string | null; status?: string };
type ReviewsPage = { data: Review[]; meta: { current_page: number; last_page: number; total: number }; summary: ReviewSummary | null; can_review: boolean; reason: string | null; own: Review | null };

export default function ProductReviews({ shell, slug, summary }: { shell: Shell; slug: string; summary: ReviewSummary | null }) {
  const t = useT();
  const [state, setState] = useState<ReviewsPage | null>(null);
  const [reviews, setReviews] = useState<Review[]>([]);
  const [writing, setWriting] = useState(false);
  const shown = state?.summary ?? summary;

  const load = useCallback(
    (page: number) =>
      storefrontFetch<ReviewsPage>(shell, `/storefront/products/${slug}/reviews`, { query: { page: String(page) } })
        .then((res) => {
          setState(res);
          setReviews((current) => (page === 1 ? res.data : [...current, ...res.data]));
        })
        .catch(() => setState(null)),
    [shell, slug],
  );

  useEffect(() => {
    void load(1);
  }, [load]);

  return (
    <section className="mt-12" aria-labelledby="reviews-heading">
      <h2 id="reviews-heading" className="mb-4 text-2xl font-semibold">{t('Customer reviews')}</h2>
      {shown ? (
        <div className="mb-6 flex flex-wrap items-center gap-6">
          <div>
            <p className="text-4xl font-bold">{shown.average.toFixed(1)}</p>
            <Stars average={shown.average} size="md" showCount={false} />
            <p className="text-sm text-sf-muted">{shown.count === 1 ? t('1 review') : t('{count} reviews', { count: String(shown.count) })}</p>
          </div>
          <ul className="min-w-[12rem] flex-1 space-y-1 text-sm" aria-label={t('Ratings by stars')}>
            {[5, 4, 3, 2, 1].map((stars) => {
              const n = shown.distribution[String(stars)] ?? 0;

              return (
                <li key={stars} className="flex items-center gap-2">
                  <span className="w-12">{stars === 1 ? t('1 star') : t('{stars} stars', { stars: String(stars) })}</span>
                  <span className="h-2 flex-1 overflow-hidden rounded-full bg-sf-surface" aria-hidden="true">
                    <span className="block h-full bg-sf-warning" style={{ width: `${shown.count ? (n / shown.count) * 100 : 0}%` }} />
                  </span>
                  <span className="w-8 text-end text-sf-muted">{n}</span>
                </li>
              );
            })}
          </ul>
        </div>
      ) : (
        <p className="mb-6 text-sf-muted">{t('No reviews yet.')}</p>
      )}

      {state?.own && !writing && (
        <p role="status" className="mb-4 rounded-sf bg-sf-surface p-3 text-sm">
          {state.own.status === 'approved' ? t('Thank you — your review is published.') : state.own.status === 'rejected' ? t('Your review was not published.') : t('Thank you — your review will appear once the store has checked it.')}{' '}
          {state.can_review && <button type="button" onClick={() => setWriting(true)} className="text-sf-accent underline">{t('Change your review')}</button>}
        </p>
      )}
      {state?.can_review && !state.own && !writing && (
        <button type="button" onClick={() => setWriting(true)} className="sf-btn mb-6 rounded-sf bg-sf-primary px-4 py-2 font-medium text-white">{t('Write a review')}</button>
      )}
      {state?.reason === 'sign_in' && (
        <p className="mb-6 text-sm text-sf-muted">
          <Link href={loginHref(shell)} className="text-sf-accent underline">{t('Sign in')}</Link> {t('to review a product you bought.')}
        </p>
      )}
      {writing && (
        <ReviewForm
          shell={shell}
          slug={slug}
          own={state?.own ?? null}
          onDone={() => {
            setWriting(false);
            void load(1);
          }}
          onCancel={() => setWriting(false)}
        />
      )}

      <ul className="space-y-6">
        {reviews.map((review) => (
          <li key={review.id} className="border-b border-sf-border pb-6">
            <div className="flex flex-wrap items-center gap-2">
              <Stars average={review.rating} showCount={false} />
              {review.title && <span className="font-semibold">{review.title}</span>}
            </div>
            <p className="mt-1 text-sm text-sf-muted">
              {review.author}
              {review.verified_purchase && <span className="ms-2 text-sf-success">✓ {t('Verified purchase')}</span>}
              <span className="ms-2">{new Date(review.created_at).toLocaleDateString()}</span>
            </p>
            <p className="mt-2 whitespace-pre-line">{review.body}</p>
            {review.reply && (
              <div className="mt-3 rounded-sf bg-sf-surface p-3 text-sm">
                <p className="font-semibold">{t('Reply from the store')}</p>
                <p className="whitespace-pre-line">{review.reply}</p>
              </div>
            )}
          </li>
        ))}
      </ul>
      {state && state.meta.current_page < state.meta.last_page && (
        <button type="button" onClick={() => void load(state.meta.current_page + 1)} className="mt-4 rounded-sf border border-sf-border px-4 py-2">{t('Show more reviews')}</button>
      )}
    </section>
  );
}

function ReviewForm({ shell, slug, own, onDone, onCancel }: { shell: Shell; slug: string; own: Review | null; onDone: () => void; onCancel: () => void }) {
  const t = useT();
  const [rating, setRating] = useState(own?.rating ?? 0);
  const [title, setTitle] = useState(own?.title ?? '');
  const [body, setBody] = useState(own?.body ?? '');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const input = 'w-full rounded-sf border border-sf-border px-3 py-2';

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (rating < 1) {
      setError(t('Choose from 1 to 5 stars.'));
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await storefrontFetch(shell, `/storefront/products/${slug}/reviews`, { method: 'POST', body: { rating, title: title || null, body } });
      onDone();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} className="mb-8 space-y-3 rounded-sf border border-sf-border p-4">
      {error && <p role="alert" className="text-sm text-sf-error">{error}</p>}
      <fieldset>
        <legend className="mb-1 font-medium">{t('Your rating')}</legend>
        <div className="flex gap-1">
          {[1, 2, 3, 4, 5].map((n) => (
            <label key={n} className="cursor-pointer text-2xl leading-none text-sf-warning">
              <input type="radio" name="rating" value={n} checked={rating === n} onChange={() => setRating(n)} className="sr-only" />
              <span aria-hidden="true">{n <= rating ? '★' : '☆'}</span>
              <span className="sr-only">{n === 1 ? t('1 star') : t('{stars} stars', { stars: String(n) })}</span>
            </label>
          ))}
        </div>
      </fieldset>
      <input value={title} onChange={(e) => setTitle(e.target.value)} maxLength={120} placeholder={t('Title (optional)')} aria-label={t('Title')} className={input} />
      <textarea value={body} onChange={(e) => setBody(e.target.value)} required minLength={10} maxLength={2000} rows={4} placeholder={t('What did you think of it?')} aria-label={t('Your review')} className={input} />
      <div className="flex gap-2">
        <button type="submit" disabled={busy} className="sf-btn rounded-sf bg-sf-primary px-4 py-2 font-medium text-white disabled:opacity-50">{busy ? t('Sending…') : t('Send review')}</button>
        <button type="button" onClick={onCancel} className="rounded-sf border border-sf-border px-4 py-2">{t('Cancel')}</button>
      </div>
    </form>
  );
}
