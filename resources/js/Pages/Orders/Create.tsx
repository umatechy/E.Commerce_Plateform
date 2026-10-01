import { FormEvent, useState } from 'react';
import { router } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Button, { ButtonLink } from '@/Components/ui/Button';
import { FormError, TextAreaField, TextField } from '@/Components/ui/Form';
import { Card, PackageNotice } from '@/Components/ui/Page';
import ProductPicker, { type PickedProduct } from '@/Components/ProductPicker';
import { useAccess } from '@/lib/access';
import { useForm, useUnsavedWarning } from '@/lib/useForm';
import { adminFetch, AdminApiError, idempotencyKey } from '@/lib/adminApi';
import { money } from '@/lib/money';
import { variantLabel } from '@/lib/catalog';
import type { Order } from '@/lib/orders';

/**
 * An order taken by staff, e.g. by phone or at the counter
 * (POST /api/v1/orders, source "admin"). The page sends which products
 * and how many; the server prices every line from the catalog, reserves
 * the stock, applies the package's monthly order limit and returns the
 * order. The prices shown here are the catalog's current prices, for
 * orientation only.
 */
type Line = PickedProduct & { quantity: string };

function lineKey(line: PickedProduct): string {
  return `${line.product.id}:${line.variant?.id ?? ''}`;
}

export default function Create() {
  const access = useAccess();
  const form = useForm({ guest_name: '', guest_email: '', guest_phone: '', notes: '' });
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
    setLimitReached(false);
    const v = form.values;
    const body = { items, guest_name: v.guest_name, guest_email: v.guest_email, guest_phone: v.guest_phone === '' ? null : v.guest_phone, notes: v.notes === '' ? null : v.notes, source: 'admin', idempotency_key: key };
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
        <p role="note" className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          <strong>Know before you create it: </strong>
          an order made here reserves the stock, but it has no payment attached. The admin cannot yet take or record a payment for it, and the server does not let an
          unpaid order be shipped. Until that exists, such an order can only be cancelled (which releases the stock). Orders placed on your storefront are not
          affected.
        </p>

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
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label="Name" value={form.values.guest_name} onChange={(v) => form.set('guest_name', v)} error={form.errors.guest_name} required maxLength={255} />
            <TextField label="Email" type="email" value={form.values.guest_email} onChange={(v) => form.set('guest_email', v)} error={form.errors.guest_email} required maxLength={255} />
            <TextField label="Phone" optional value={form.values.guest_phone} onChange={(v) => form.set('guest_phone', v)} error={form.errors.guest_phone} maxLength={32} />
            <div className="sm:col-span-2">
              <TextAreaField label="Note" optional rows={3} value={form.values.notes} onChange={(v) => form.set('notes', v)} error={form.errors.notes} maxLength={1000} />
            </div>
          </div>
        </Card>

        <div className="flex flex-wrap gap-3">
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Creating…">Create order</Button>
          <ButtonLink href="/orders">Back to orders</ButtonLink>
        </div>
      </form>
    </AdminPage>
  );
}
