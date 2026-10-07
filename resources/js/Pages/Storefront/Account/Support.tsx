import { Link } from '@inertiajs/react';
import { useT } from '@/Storefront/i18n';
import { useEffect, useState } from 'react';
import AccountLayout from '@/Components/Storefront/AccountLayout';
import EmptyState from '@/Components/EmptyState';
import { storefrontFetch } from '@/Storefront/api';
import { useCustomer } from '@/Storefront/account';
import { categoryLabel, statusLabel, type TicketPage, type TicketSummary } from '@/lib/support';
import { formatDate } from '@/lib/datetime';
import type { StorefrontPageProps } from '@/Storefront/types';

/** Phase B26 — the shopper's support requests to this store. */
export default function Support({ storefront, seo }: StorefrontPageProps) {
  const t = useT();
  const { customer } = useCustomer(storefront, { required: true });
  const [status, setStatus] = useState<'all' | 'active'>('all');
  const [page, setPage] = useState(1);
  const [data, setData] = useState<TicketPage<TicketSummary> | null>(null);
  const base = storefront.base_path;

  useEffect(() => {
    if (!customer) return;
    storefrontFetch<{ data: TicketPage<TicketSummary> }>(storefront, '/customer/support/tickets', { query: { page: String(page), status } }).then((res) =>
      setData(res.data),
    );
  }, [customer, page, status, storefront]);

  return (
    <AccountLayout shell={storefront} seo={seo} customer={customer} active="/account/support" title={t('Support')}>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div className="flex gap-2 text-sm" role="group" aria-label={t('Show')}>
          {(['all', 'active'] as const).map((value) => (
            <button
              key={value}
              type="button"
              aria-pressed={status === value}
              onClick={() => {
                setStatus(value);
                setPage(1);
              }}
              className={`rounded-sf border border-sf-border px-3 py-1 ${status === value ? 'bg-sf-surface font-semibold' : ''}`}
            >
              {value === 'all' ? t('All requests') : t('Open requests')}
            </button>
          ))}
        </div>
        <Link href={`${base}/account/support/new`} className="sf-btn rounded-sf bg-sf-primary px-4 py-2 text-sm font-medium text-white">
          {t('New request')}
        </Link>
      </div>

      {data?.tickets.length === 0 && <EmptyState title={t('No requests yet')} description={t('Questions you send us appear here with our replies.')} />}
      {data && data.tickets.length > 0 && (
        <ul className="divide-y divide-sf-border rounded-sf border border-sf-border">
          {data.tickets.map((ticket) => (
            <li key={ticket.id}>
              <Link href={`${base}/account/support/${ticket.id}`} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-sf-surface">
                <span className="font-mono text-xs text-sf-muted">{ticket.number}</span>
                <span className="min-w-0 flex-1 truncate font-medium">{ticket.subject}</span>
                <span className="text-xs text-sf-muted">{t(categoryLabel(ticket.category))}</span>
                <span className={`rounded-sf px-2 py-1 text-xs font-medium ${ticket.status === 'awaiting_customer' ? 'bg-amber-100 text-amber-900' : 'bg-sf-surface'}`}>
                  {t(statusLabel(ticket.status))}
                </span>
                <span className="w-24 text-end text-xs text-sf-muted">{formatDate(ticket.updated_at)}</span>
              </Link>
            </li>
          ))}
        </ul>
      )}
      {data && data.pagination.last_page > 1 && (
        <div className="mt-6 flex items-center justify-center gap-4 text-sm">
          <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded-sf border border-sf-border px-3 py-1 disabled:opacity-40">
            {t('Previous')}
          </button>
          <span>{t('Page {page} of {last}', { page: data.pagination.page, last: data.pagination.last_page })}</span>
          <button type="button" disabled={page >= data.pagination.last_page} onClick={() => setPage(page + 1)} className="rounded-sf border border-sf-border px-3 py-1 disabled:opacity-40">
            {t('Next')}
          </button>
        </div>
      )}
    </AccountLayout>
  );
}
