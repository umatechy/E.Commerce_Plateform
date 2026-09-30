import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AccountLayout from '@/Components/Storefront/AccountLayout';
import RequesterTicket from '@/Components/Support/RequesterTicket';
import { errorMessage, storefrontFetch } from '@/Storefront/api';
import { useCustomer } from '@/Storefront/account';
import type { TicketDetail } from '@/lib/support';
import type { StorefrontPageProps } from '@/Storefront/types';

/** Phase B26 — one of the shopper's requests: the conversation, reply, resolve and rate. */
export default function SupportTicket({ storefront, seo, ticket_id }: StorefrontPageProps & { ticket_id: string }) {
  const { customer } = useCustomer(storefront, { required: true });
  const [ticket, setTicket] = useState<TicketDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const base = storefront.base_path;
  const path = `/customer/support/tickets/${encodeURIComponent(ticket_id)}`;

  useEffect(() => {
    if (!customer) return;
    storefrontFetch<{ data: TicketDetail }>(storefront, path)
      .then((res) => setTicket(res.data))
      .catch((e) => setError(errorMessage(e)));
  }, [customer, path, storefront]);

  const call = (suffix: string, body: Record<string, unknown>) =>
    storefrontFetch<{ data: TicketDetail }>(storefront, `${path}${suffix}`, { method: 'POST', body }).then((res) => res.data);

  return (
    <AccountLayout shell={storefront} seo={seo} customer={customer} active="/account/support" title="Support request">
      <Link href={`${base}/account/support`} className="text-sm text-sf-accent">
        ← All requests
      </Link>
      {error && <p className="mt-4 text-sf-error">{error}</p>}
      {ticket && (
        <div className="mt-6">
          <RequesterTicket
            ticket={ticket}
            tone="storefront"
            onChange={setTicket}
            reply={(body) => call('/messages', { body })}
            resolve={() => call('/resolve', {})}
            rate={(rating, comment) => call('/rating', { rating, comment: comment || null })}
            describeError={errorMessage}
            newRequestHref={`${base}/account/support/new`}
          />
        </div>
      )}
    </AccountLayout>
  );
}
