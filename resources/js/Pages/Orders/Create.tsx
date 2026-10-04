import { FormEvent, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Button, { ButtonLink } from '@/Components/ui/Button';
import { FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { Card, PackageNotice } from '@/Components/ui/Page';
import ProductPicker, { type PickedProduct } from '@/Components/ProductPicker';
import { useAccess } from '@/lib/access';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useForm, useUnsavedWarning } from '@/lib/useForm';
import { adminFetch, AdminApiError, idempotencyKey } from '@/lib/adminApi';
import { money } from '@/lib/money';
import { variantLabel } from '@/lib/catalog';
import { PAYMENT_METHOD_LABELS, type Order } from '@/lib/orders';
import type { CustomerRow } from '@/lib/customers';
import type { StoreCredit } from '@/Components/Customers/StoreCreditCard';

/**
 * An order taken by staff, e.g. by phone or at the counter
 * (POST /api/v1/orders, source "admin"). The page sends which products
 * and how many; the server prices every line from the catalog, reserves
 * the stock, applies the package's monthly order limit and returns the
 * order. The prices shown here are the catalog's current prices, for
 * orientation only.
 *
 * Phase B32 (Module 09 §70, Module 10): the order is for one of the
 * store's customers or for a guest, and staff choose how it is paid
 * (cash on delivery or bank transfer, as the package allows). The
 * payment is then recorded on the order when the money arrives
 * (Payments → Open → Record payment received). Active customers only;
 * the server checks this again.
 *
 * Phase B35 (Module 09 §52): a customer's store credit can pay for the
 * order as far as it goes, as at checkout. The server decides the amount;
 * the page shows the balance and asks how the rest is paid.
 */
const ADMIN_PAYMENT_METHODS = ['cod', 'bank_transfer'] as const;

function CustomerPicker({ chosen, onChoose }: { chosen: CustomerRow | null; onChoose: (customer: CustomerRow | null) => void }) {
  const [text, setText] = useState('');
  const [search, setSearch] = useState('');
  useEffect(() => {
    const timer = setTimeout(() => setSearch(text.trim()), 300);

    return () => clearTimeout(timer);
  }, [text]);
  const results = usePagedApi<CustomerRow>(search.length >= 2 && chosen === null ? '/customers' : null, { search, status: 'active', per_page: 8 });

  if (chosen !== null) {
    return (
      <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-200 p-3 text-sm">
        <div>
          <p className="font-medium text-slate-900">{chosen.name}</p>
          <p className="text-slate-600">{chosen.email}{chosen.phone ? ` · ${chosen.phone}` : ''}</p>
        </div>
        <Button size="sm" onClick={() => onChoose(null)}>Change customer</Button>
      </div>
    );
  }

  return (
    <div>
      <TextField label="Find a customer" type="search" value={text} onChange={setText} hint="Name, email or phone (at least 2 characters). Only active customers can order." autoComplete="off" />
      {search.length >= 2 && (
        <div className="mt-2" aria-live="polite">
          {results.error ? (
            <p className="text-sm text-red-700">{results.error}</p>
          ) : results.rows === null ? (
            <p className="text-sm text-slate-600">Searching…</p>
          ) : results.rows.length === 0 ? (
            <p className="text-sm text-slate-600">No active customer matches. Choose “Guest” to take the order without an account.</p>
          ) : (
            <ul className="divide-y divide-slate-100 rounded-md border border-slate-200">
              {results.rows.map((customer) => (
                <li key={customer.id}>
                  <button type="button" onClick={() => onChoose(customer)} className="w-full p-2 text-left text-sm hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                    <span className="font-medium text-slate-900">{customer.name}</span> <span className="text-slate-600">{customer.email}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
type Line = PickedProduct & { quantity: string };

function lineKey(line: PickedProduct): string {
  return `${line.product.id}:${line.variant?.id ?? ''}`;
}

export default function Create() {
  const access = useAccess();
  const form = useForm({ guest_name: '', guest_email: '', guest_phone: '', notes: '', payment_method: '' });
  const methods = ADMIN_PAYMENT_METHODS.filter((method) => access.feature(`payment.${method}`) === true);
  // From a customer's page: /orders/new?customer={id}.
  const [preset] = useState(() => new URLSearchParams(window.location.search).get('customer'));
  const presetCustomer = useApi<{ data: CustomerRow }>(preset && /^[0-9A-Za-z]{26}$/.test(preset) ? `/customers/${preset}` : null);
  const [mode, setMode] = useState<'customer' | 'guest'>(preset ? 'customer' : 'guest');
  const [customer, setCustomer] = useState<CustomerRow | null>(null);
  const [useCredit, setUseCredit] = useState(false);
  const credit = useApi<{ data: StoreCredit }>(mode === 'customer' && customer !== null ? `/customers/${customer.id}/store-credit` : null);
  const creditBalance = credit.data && credit.data.data.currency === access.currency ? credit.data.data.balance_minor : 0;
  const creditOffered = mode === 'customer' && customer !== null && creditBalance > 0;
  useEffect(() => setUseCredit(false), [customer, mode]);
  useEffect(() => {
    const found = presetCustomer.data?.data;
    if (found && found.status === 'active' && !found.erased) setCustomer(found);
  }, [presetCustomer.data]);
  const [lines, setLines] = useState<Line[]>([]);
  // One key for this order form: submitting twice creates one order.
  const [key] = useState(idempotencyKey);
  const [limitReached, setLimitReached] = useState(false);
  useUnsavedWarning(form.dirty || lines.length > 0);

  function add(picked: PickedProduct) {
    setLines((current) => (current.some((line) => lineKey(line) === lineKey(picked)) ? current : [...current, { ...picked, quantity: '1' }]));
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    const items = lines.map((line) => ({
      product_id: line.variant === null ? line.product.internal_id : null,
      product_variant_id: line.variant?.internal_id ?? null,
      quantity: Number(line.quantity),
    }));
    if (items.length === 0) {
      form.setFormError('Add at least one product.');

      return;
    }
    if (items.some((item) => !Number.isInteger(item.quantity) || item.quantity < 1)) {
      form.setFormError('Every quantity must be a whole number of 1 or more.');

      return;
    }
    if (mode === 'customer' && customer === null) {
      form.setFormError('Choose the customer, or take the order as a guest.');

      return;
    }
    if (creditOffered && useCredit && form.values.payment_method === '') {
      form.setFormError('Choose how the rest is paid. If the store credit covers everything, nothing is charged.');

      return;
    }
    setLimitReached(false);
    const v = form.values;
    const who = mode === 'customer' && customer !== null
      ? { customer: customer.id }
      : { guest_name: v.guest_name, guest_email: v.guest_email, guest_phone: v.guest_phone === '' ? null : v.guest_phone };
    const body = { items, ...who, payment_method: v.payment_method === '' ? null : v.payment_method, notes: v.notes === '' ? null : v.notes, source: 'admin', idempotency_key: key, ...(creditOffered && useCredit ? { use_store_credit: true } : {}) };
    const saved = await form.submit(async () => {
      try {
        return await adminFetch<{ data: Order }>('/orders', { method: 'POST', body });
      } catch (e) {
        if (e instanceof AdminApiError && e.code === 'usage_limit_exceeded') setLimitReached(true);
        throw e;
      }
    }, 'Order created.');
    if (saved) router.visit(`/orders/${saved.data.id}`);
  }

  return (
    <AdminPage title="Create order" trail={[{ label: 'New order' }]} description="For an order you take yourself. The server sets the prices and reserves the stock.">
      <form onSubmit={save} className="space-y-4" noValidate>
        {limitReached && (
          <PackageNotice title="Your package's monthly order limit is reached" packageName={access.packageName} canSeeBilling={access.can('billing.view')}>
            No more orders can be accepted this month on your package.
          </PackageNotice>
        )}
        <FormError message={form.formError} errors={form.errors} />

        <Card title="Products">
          <ProductPicker onPick={add} label="Add a product" />
          {lines.length > 0 && (
            <ul className="mt-4 divide-y divide-slate-100 rounded-md border border-slate-200">
              {lines.map((line, index) => {
                const price = line.variant?.effective_price_minor ?? line.product.effective_price_minor;

                return (
                  <li key={lineKey(line)} className="grid grid-cols-[1fr_6rem_auto] items-end gap-3 p-3">
                    <div className="text-sm">
                      <span className="font-medium">{line.product.name}</span>
                      {line.variant && <span className="text-slate-600"> — {variantLabel(line.variant)}</span>}
                      <p className="text-xs text-slate-500">{price === null ? 'No price set' : `Catalog price ${money(price, line.product.currency ?? access.currency)}`}</p>
                    </div>
                    <TextField label="Quantity" type="number" min={1} value={line.quantity} onChange={(quantity) => setLines((current) => current.map((item, i) => (i === index ? { ...item, quantity } : item)))} />
                    <Button variant="ghost" size="sm" onClick={() => setLines((current) => current.filter((_, i) => i !== index))}>
                      Remove<span className="sr-only"> {line.product.name}</span>
                    </Button>
                  </li>
                );
              })}
            </ul>
          )}
        </Card>

        <Card title="Customer">
          <fieldset className="mb-4">
            <legend className="sr-only">Who the order is for</legend>
            <div className="flex flex-wrap gap-4 text-sm">
              {(['customer', 'guest'] as const).map((value) => (
                <label key={value} className="flex items-center gap-2">
                  <input type="radio" name="order-for" value={value} checked={mode === value} onChange={() => setMode(value)} className="h-4 w-4 border-slate-400 text-indigo-600 focus:ring-indigo-500" />
                  {value === 'customer' ? 'A customer of the store' : 'Guest (no account)'}
                </label>
              ))}
            </div>
          </fieldset>
          <div className="grid gap-4 sm:grid-cols-2">
            {mode === 'customer' ? (
              <div className="sm:col-span-2">
                {form.errors.customer && <p className="mb-2 text-sm text-red-700">{form.errors.customer}</p>}
                {presetCustomer.data && presetCustomer.data.data.status !== 'active' && customer === null && (
                  <p className="mb-2 text-sm text-amber-800">{presetCustomer.data.data.name} is {presetCustomer.data.data.status} and cannot place orders.</p>
                )}
                <CustomerPicker chosen={customer} onChoose={setCustomer} />
              </div>
            ) : (
              <>
                <TextField label="Name" value={form.values.guest_name} onChange={(v) => form.set('guest_name', v)} error={form.errors.guest_name} required maxLength={255} />
                <TextField label="Email" type="email" value={form.values.guest_email} onChange={(v) => form.set('guest_email', v)} error={form.errors.guest_email} required maxLength={255} />
                <TextField label="Phone" optional value={form.values.guest_phone} onChange={(v) => form.set('guest_phone', v)} error={form.errors.guest_phone} maxLength={32} />
              </>
            )}
            <div className="sm:col-span-2">
              <TextAreaField label="Note" optional rows={3} value={form.values.notes} onChange={(v) => form.set('notes', v)} error={form.errors.notes} maxLength={1000} />
            </div>
          </div>
        </Card>

        <Card title="Payment">
          {creditOffered && (
            <label className="mb-4 flex items-start gap-2 text-sm">
              <input type="checkbox" checked={useCredit} onChange={(e) => setUseCredit(e.target.checked)} className="mt-0.5 h-4 w-4 rounded border-slate-400 text-indigo-600 focus:ring-indigo-500" />
              <span>
                Pay with {customer?.name}&rsquo;s store credit (balance {money(creditBalance, access.currency)})
                <span className="block text-slate-600">As much of the total as the balance covers. Cancelling the order gives it back.</span>
              </span>
            </label>
          )}
          {methods.length === 0 ? (
            <p className="text-sm text-amber-900">Your package includes neither cash on delivery nor bank transfer. The order is created without a payment and cannot be shipped until it has one.</p>
          ) : (
            <SelectField
              label={creditOffered && useCredit ? 'How the rest is paid' : 'How the customer pays'}
              value={form.values.payment_method}
              onChange={(v) => form.set('payment_method', v)}
              error={form.errors.payment_method}
              placeholder="Not decided yet"
              options={methods.map((method) => ({ value: method, label: PAYMENT_METHOD_LABELS[method] }))}
              hint={form.values.payment_method === ''
                ? 'Without a payment the order cannot be shipped. Choose one now, or cancel the order later if it is never paid.'
                : 'When the money arrives, record it on the order under Payments. A cash-on-delivery order can be shipped before it is paid.'}
            />
          )}
        </Card>

        <div className="flex flex-wrap gap-3">
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Creating…">Create order</Button>
          <ButtonLink href="/orders">Back to orders</ButtonLink>
        </div>
      </form>
    </AdminPage>
  );
}
