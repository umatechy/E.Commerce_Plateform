import { FormEvent, useRef, useState } from 'react';
import Button from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { FormError, TextField } from '@/Components/ui/Form';
import Icon, { type IconName } from '@/Components/ui/Icon';
import { useAccess } from '@/lib/access';
import { adminFetch, idempotencyKey } from '@/lib/adminApi';
import { toMinor } from '@/lib/money';
import { useForm } from '@/lib/useForm';
import { readPage } from '@/lib/useApi';
import { toast } from '@/Components/ui/toast';

/**
 * UI-16 — Quick start: the steps a new store needs before it can open,
 * done right here instead of on four pages:
 *
 * 1. how customers reach the store (contact email, phone) — store settings;
 * 2. the first product (name, price, stock) — the product and inventory APIs;
 * 3. open the store — the launch API, or what is still missing.
 *
 * Only the steps still open are shown; each can be skipped. Every save goes
 * through the same APIs and permissions as the full pages.
 */
export type QuickCheck = { key: string; done: boolean; required: boolean; message: string };
type StepId = 'contact' | 'product' | 'launch';

const STEP_META: Record<StepId, { title: string; icon: IconName }> = {
  contact: { title: 'How customers reach you', icon: 'mail' },
  product: { title: 'Your first product', icon: 'box' },
  launch: { title: 'Open your store', icon: 'store' },
};

function ContactStep({ onDone }: { onDone: () => void }) {
  const form = useForm({ email: '', phone: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    const saved = await form.submit(async () => {
      await adminFetch('/store/settings/store.contact_email', { method: 'PUT', body: { value: form.values.email.trim(), reason: 'Quick start' } });
      if (form.values.phone.trim() !== '') await adminFetch('/store/settings/store.contact_phone', { method: 'PUT', body: { value: form.values.phone.trim(), reason: 'Quick start' } });

      return true;
    }, 'Contact details saved.');
    if (saved) onDone();
  }

  return (
    <form onSubmit={save} className="space-y-4" noValidate>
      <p className="text-sm text-slate-600">Shown on your storefront and used by Umar Techy to reach you. Needed before you open.</p>
      <FormError message={form.formError} errors={form.errors} />
      <TextField label="Contact email" type="email" autoComplete="email" value={form.values.email} onChange={(v) => form.set('email', v)} error={form.errors.value} placeholder="shop@example.com" data-autofocus required />
      <TextField label="Phone or WhatsApp" optional type="tel" autoComplete="tel" value={form.values.phone} onChange={(v) => form.set('phone', v)} placeholder="+92 300 1234567" />
      <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={form.values.email.trim() === ''}>Save and continue<Icon name="arrowRight" size={16} /></Button>
    </form>
  );
}

function ProductStep({ currency, onDone }: { currency: string; onDone: () => void }) {
  const access = useAccess();
  const form = useForm({ name: '', price: '', stock: '' });
  const [local, setLocal] = useState<string | null>(null);
  const canStock = access.can('inventory.adjust');
  const created = useRef<{ id: string; internal_id: number } | null>(null);

  async function save(event: FormEvent) {
    event.preventDefault();
    const price = toMinor(form.values.price, currency);
    const stock = form.values.stock.trim() === '' ? null : Number(form.values.stock);
    if (price === null || price <= 0) {
      setLocal(`Enter a price such as 2500 (${currency}).`);

      return;
    }
    if (stock !== null && (!Number.isInteger(stock) || stock < 0)) {
      setLocal('Enter the stock as a whole number, or leave it empty.');

      return;
    }
    setLocal(null);
    const saved = await form.submit(async () => {
      // Created once: pressing the button again after a problem does not add a second product.
      created.current ??= (await adminFetch<{ data: { id: string; internal_id: number } }>('/products', {
        method: 'POST',
        body: { type: 'simple', name: form.values.name.trim(), status: 'active', visibility: 'public', price_minor: price, currency },
      })).data;

      return created.current;
    }, 'Your first product is in your store.');
    if (!saved) return;
    if (stock !== null && canStock) {
      // The stock is a second step: if it fails, the product stays and the seller is told where to finish.
      try {
        const warehouses = readPage<{ id: string; internal_id: number; is_default: boolean }>(await adminFetch('/warehouses')).rows;
        const warehouse = warehouses.find((w) => w.is_default) ?? warehouses[0];
        if (!warehouse) throw new Error('This store has no warehouse yet.');
        const record = await adminFetch<{ data: { id: string } }>('/inventory', { method: 'POST', body: { warehouse_id: warehouse.internal_id, product_id: saved.internal_id, product_variant_id: null } });
        await adminFetch(`/inventory/${record.data.id}/opening-stock`, { method: 'POST', body: { quantity: stock, reason: 'Opening stock (quick start)', idempotency_key: idempotencyKey() } });
      } catch {
        toast.warning('The product is added, but its stock was not saved. Set it on the Inventory page.');
      }
    }
    onDone();
  }

  return (
    <form onSubmit={save} className="space-y-4" noValidate>
      <p className="text-sm text-slate-600">Just the basics — add pictures, a description and variants later on the product page.</p>
      <FormError message={form.formError} errors={form.errors} />
      <TextField label="Product name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} placeholder="For example: Lawn suit, 3 piece" data-autofocus required />
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField label={`Price (${currency})`} inputMode="decimal" value={form.values.price} onChange={(v) => form.set('price', v)} error={local ?? form.errors.price_minor} placeholder="2500" required />
        {canStock && <TextField label="How many you have" optional inputMode="numeric" value={form.values.stock} onChange={(v) => form.set('stock', v)} placeholder="10" hint="Leave empty if you do not count stock." />}
      </div>
      <Button type="submit" variant="primary" busy={form.busy} busyLabel="Adding…" disabled={form.values.name.trim() === '' || form.values.price.trim() === ''}>Add product and continue<Icon name="arrowRight" size={16} /></Button>
    </form>
  );
}

function LaunchStep({ checks, onLaunched }: { checks: QuickCheck[]; onLaunched: () => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const missing = checks.filter((c) => c.required && !c.done);

  async function launch() {
    setBusy(true);
    setError(null);
    try {
      await adminFetch('/storefront/launch', { method: 'POST' });
      onLaunched();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'The store could not be opened yet.');
    } finally {
      setBusy(false);
    }
  }

  return missing.length > 0 ? (
    <div className="space-y-3 text-sm">
      <p className="text-slate-600">Almost there. Still needed before customers can buy:</p>
      <ul className="space-y-2">
        {missing.map((c) => (
          <li key={c.key} className="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-amber-900"><Icon name="alert" size={16} className="mt-0.5 flex-none" />{c.message}</li>
        ))}
      </ul>
      <p className="text-slate-500">Each one is on the checklist on your dashboard, with a link to where it is done.</p>
    </div>
  ) : (
    <div className="space-y-4 text-center">
      <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600"><Icon name="checkCircle" size={28} /></span>
      <div>
        <p className="font-semibold text-slate-900">Everything needed is done</p>
        <p className="mt-1 text-sm text-slate-600">Open your store and share its address. You can keep improving it while it is open.</p>
      </div>
      {error && <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{error}</p>}
      <Button variant="primary" size="lg" busy={busy} busyLabel="Opening…" onClick={launch}><Icon name="store" size={18} />Open my store</Button>
    </div>
  );
}

export default function QuickStart({ checks, launched, currency, onChanged, onClose }: { checks: QuickCheck[]; launched: boolean; currency: string; onChanged: () => void; onClose: () => void }) {
  const access = useAccess();
  const done = (key: string) => checks.find((c) => c.key === key)?.done ?? true;
  // The steps still open when the guide was opened (so finishing one does not reshuffle the list).
  const [steps] = useState<StepId[]>(() => [
    ...(!done('business_info') && access.can('settings.manage') ? ['contact' as const] : []),
    ...(!done('products') && access.can('products.create') ? ['product' as const] : []),
    ...(!launched ? ['launch' as const] : []),
  ]);
  const [at, setAt] = useState(0);
  const step = steps[at];
  const next = () => {
    onChanged();
    if (at < steps.length - 1) setAt(at + 1);
    else onClose();
  };

  if (!step) return null;

  return (
    <Dialog open title="Quick start" description={`Step ${at + 1} of ${steps.length} · about two minutes`} onClose={onClose}>
      <ol aria-label="Steps" className="mb-6 flex items-center gap-2">
        {steps.map((id, i) => (
          <li key={id} className="flex flex-1 items-center gap-2" aria-current={i === at ? 'step' : undefined}>
            <span className={`flex h-8 w-8 flex-none items-center justify-center rounded-full transition-colors motion-reduce:transition-none ${i < at ? 'bg-emerald-500 text-white' : i === at ? 'bg-brand-600 text-white ring-4 ring-brand-100' : 'bg-slate-100 text-slate-400'}`}>
              {i < at ? <Icon name="check" size={14} strokeWidth={3} /> : <Icon name={STEP_META[id].icon} size={15} />}
            </span>
            {i < steps.length - 1 && <span aria-hidden="true" className={`h-0.5 flex-1 rounded-full ${i < at ? 'bg-emerald-300' : 'bg-slate-200'}`} />}
          </li>
        ))}
      </ol>
      <h3 className="mb-3 text-lg font-semibold text-slate-900">{STEP_META[step].title}</h3>
      <div key={step} className="motion-safe:animate-fade-in-up">
        {step === 'contact' && <ContactStep onDone={next} />}
        {step === 'product' && <ProductStep currency={currency} onDone={next} />}
        {step === 'launch' && (
          <LaunchStep
            checks={checks}
            onLaunched={() => {
              onChanged();
              onClose();
            }}
          />
        )}
      </div>
      {step !== 'launch' && (
        <div className="mt-5 border-t border-slate-100 pt-4 text-right">
          <button type="button" onClick={() => (at < steps.length - 1 ? setAt(at + 1) : onClose())} className="rounded text-sm font-medium text-slate-500 hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
            Skip this step
          </button>
        </div>
      )}
    </Dialog>
  );
}
