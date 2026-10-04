import { useEffect, useState } from 'react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import { useT } from '@/Storefront/i18n';
import LoadingState from '@/Components/LoadingState';
import RequesterTicket from '@/Components/Support/RequesterTicket';
import { errorMessage, StorefrontApiError, storefrontFetch, storeHref } from '@/Storefront/api';
import type { TicketDetail } from '@/lib/support';
import type { StorefrontPageProps } from '@/Storefront/types';

/**
 * Phase B26 — a guest's request, opened from the private link in their
 * email. The access token is in the link's #fragment, which the browser
 * never sends to a server; the page reads it and sends it to the API in
 * the X-Support-Token header, so it stays out of URLs and logs.
 */
export default function SupportTicket({ storefront, seo, ticket_id }: StorefrontPageProps & { ticket_id: string }) {
  const [token, setToken] = useState<string | null>(null);
  const [ticket, setTicket] = useState<TicketDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const path = `/storefront/support/tickets/${encodeURIComponent(ticket_id)}`;

  // Opening another link to this page may change only the #fragment,
  // which does not reload the page; follow it.
  const t = useT();

  useEffect(() => {
    const read = () => setToken(new URLSearchParams(window.location.hash.slice(1)).get('token') ?? '');
    read();
    window.addEventListener('hashchange', read);

    return () => window.removeEventListener('hashchange', read);
  }, []);

  useEffect(() => {
    if (token === null) return;
    setTicket(null);
    setError(null);
    storefrontFetch<{ data: TicketDetail }>(storefront, path, { headers: { 'X-Support-Token': token } })
      .then((res) => setTicket(res.data))
      .catch((e) =>
        setError(e instanceof StorefrontApiError && e.status === 404 ? t('This link is not valid. Please use the latest link from our email.') : errorMessage(e)),
      );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [path, storefront, token]);

  const call = (suffix: string, body: Record<string, unknown>) =>
    storefrontFetch<{ data: TicketDetail }>(storefront, `${path}${suffix}`, { method: 'POST', body, headers: { 'X-Support-Token': token ?? '' } }).then((res) => res.data);

  return (
    <StoreLayout shell={storefront} seo={seo}>
      <div className="mx-auto max-w-3xl">
        {error && (
          <div className="rounded-sf border border-sf-border p-6">
            <p className="text-sf-error">{error}</p>
            <a href={storeHref(storefront, '/contact')} className="mt-3 inline-block text-sm text-sf-accent">
              {t('Contact us again')}
            </a>
          </div>
        )}
        {!error && !ticket && <LoadingState />}
        {ticket && (
          <RequesterTicket
            ticket={ticket}
            tone="storefront"
            onChange={setTicket}
            reply={(body) => call('/messages', { body })}
            resolve={() => call('/resolve', {})}
            rate={(rating, comment) => call('/rating', { rating, comment: comment || null })}
            describeError={errorMessage}
            newRequestHref={storeHref(storefront, '/contact')}
          />
        )}
      </div>
    </StoreLayout>
  );
}
