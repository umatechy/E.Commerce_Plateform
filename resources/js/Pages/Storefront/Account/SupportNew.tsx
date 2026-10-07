import { formatDate } from '@/lib/datetime';
import { useT } from '@/Storefront/i18n';
import { Link, router } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import AccountLayout from '@/Components/Storefront/AccountLayout';
import { errorMessage, storefrontFetch, validationErrors } from '@/Storefront/api';
import { useCustomer } from '@/Storefront/account';
import type { OrderSummary } from '@/Storefront/orders';
import { categoryLabel, STORE_CATEGORIES, type SupportCategory, type TicketDetail } from '@/lib/support';
import type { StorefrontPageProps } from '@/Storefront/types';

const input = 'w-full rounded-sf border border-sf-border bg-sf-bg px-3 py-2';

/** Phase B26 — a signed-in shopper opens a request, optionally about one of their orders. */
export default function SupportNew({ storefront, seo }: StorefrontPageProps) {
  const t = useT();
  const { customer } = useCustomer(storefront, { required: true });
  const [orders, setOrders] = useState<OrderSummary[]>([]);
  const [form, setForm] = useState({ subject: '', category: 'order' as SupportCategory, order: '', message: '' });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const base = storefront.base_path;

  useEffect(() => {
    if (!customer) return;
    // "Get help with this order" links here with ?order=<id>.
    const preset = new URLSearchParams(window.location.search).get('order') ?? '';
    storefrontFetch<{ data: { orders: OrderSummary[] } }>(storefront, '/customer/orders').then((res) => {
      setOrders(res.data.orders);
      if (preset && res.data.orders.some((o) => o.id === preset)) setForm((f) => ({ ...f, order: preset }));
    });
  }, [customer, storefront]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setError(null);
    try {
      const res = await storefrontFetch<{ data: TicketDetail }>(storefront, '/customer/support/tickets', {
        method: 'POST',
        body: { subject: form.subject, category: form.category, message: form.message, order: form.order || null },
      });
      router.visit(`${base}/account/support/${res.data.id}`);
    } catch (e) {
      setErrors(validationErrors(e));
      setError(errorMessage(e));
      setBusy(false);
    }
  }

  const fieldError = (field: string) => errors[field]?.[0] && <span className="mt-1 block text-sf-error">{errors[field][0]}</span>;

  return (
    <AccountLayout shell={storefront} seo={seo} customer={customer} active="/account/support" title={t('New request')}>
      <Link href={`${base}/account/support`} className="text-sm text-sf-accent">
        <span className="inline-block rtl:rotate-180" aria-hidden="true">←</span> {t('All requests')}
      </Link>
      <form onSubmit={submit} className="mt-6 max-w-xl space-y-4">
        <label className="block text-sm">
          <span className="mb-1 block text-sf-muted">{t('What is it about?')}</span>
          <select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value as SupportCategory })} className={input} name="category">
            {STORE_CATEGORIES.map((c) => (
              <option key={c} value={c}>
                {t(categoryLabel(c))}
              </option>
            ))}
          </select>
          {fieldError('category')}
        </label>
        {orders.length > 0 && (
          <label className="block text-sm">
            <span className="mb-1 block text-sf-muted">{t('Order (optional)')}</span>
            <select value={form.order} onChange={(e) => setForm({ ...form, order: e.target.value })} className={input} name="order">
              <option value="">{t('Not about a specific order')}</option>
              {orders.map((o) => (
                <option key={o.id} value={o.id}>
                  {o.number} — {formatDate(o.placed_at)}
                </option>
              ))}
            </select>
            {fieldError('order')}
          </label>
        )}
        <label className="block text-sm">
          <span className="mb-1 block text-sf-muted">{t('Subject')}</span>
          <input required maxLength={200} value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} className={input} name="subject" />
          {fieldError('subject')}
        </label>
        <label className="block text-sm">
          <span className="mb-1 block text-sf-muted">{t('How can we help?')}</span>
          <textarea required rows={6} maxLength={10000} value={form.message} onChange={(e) => setForm({ ...form, message: e.target.value })} className={input} name="message" />
          {fieldError('message')}
        </label>
        {error && Object.keys(errors).length === 0 && (
          <p role="alert" className="text-sm text-sf-error">
            {error}
          </p>
        )}
        <button type="submit" disabled={busy} className="sf-btn rounded-sf bg-sf-primary px-4 py-2 font-medium text-white disabled:opacity-50">
          {busy ? t('Sending…') : t('Send request')}
        </button>
      </form>
    </AccountLayout>
  );
}
