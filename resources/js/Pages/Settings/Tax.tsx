import { FormEvent, useEffect, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { CheckboxField, FormError, SelectField, TextField } from '@/Components/ui/Form';
import { Card, EmptyPanel, QueryState } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { money, toMinor } from '@/lib/money';
import { ratePercent } from '@/lib/orders';

/**
 * Phase B46 (gap G3, owner decision 1; Module 33 §29): the store's tax.
 * Nothing comes pre-filled — the store enters the classes and rates that
 * apply to its business, then turns tax on. Orders keep the tax they were
 * charged; changes here apply to new orders only.
 */
export type TaxSettings = { enabled: boolean; prices_include_tax: boolean; based_on: string; shipping_taxable: boolean; discount_basis: string; rounding: string; label: string };
export type TaxClassRow = { id: number; name: string; description: string | null; is_default: boolean; rates_count: number; products_count: number };
export type TaxRateRow = { id: number; tax_class_id: number; name: string; country: string | null; region: string | null; rate_bps: number; is_active: boolean; starts_on: string | null; ends_on: string | null };
type TaxData = { settings: TaxSettings; classes: TaxClassRow[]; rates: TaxRateRow[]; store_country: string | null };

/** "16.5" → 1650; null when it is not a percentage from 0 to 100 with at most two decimals. */
export function percentToBps(text: string): number | null {
  const t = text.trim().replace(',', '.').replace(/%$/, '').trim();
  if (!/^\d{1,3}(\.\d{1,2})?$/.test(t)) return null;
  const [whole, fraction = ''] = t.split('.');
  const bps = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));

  return bps <= 10000 ? bps : null;
}

const BASED_ON = [
  { value: 'shipping', label: 'The delivery address' },
  { value: 'billing', label: 'The billing address' },
  { value: 'store', label: 'My store’s country (everyone pays the same)' },
];

export default function Tax() {
  const access = useAccess();
  const canManage = access.can('tax.manage');
  const state = useApi<{ data: TaxData }>('/tax');
  const [classDialog, setClassDialog] = useState<TaxClassRow | 'new' | null>(null);
  const [rateDialog, setRateDialog] = useState<TaxRateRow | 'new' | null>(null);
  const [removing, setRemoving] = useState<{ kind: 'class' | 'rate'; id: number; name: string } | null>(null);
  const { busy, run } = useAction();
  const data = state.data?.data;
  const className = (id: number) => data?.classes.find((c) => c.id === id)?.name ?? '—';

  const classColumns: Column<TaxClassRow>[] = [
    { key: 'name', header: 'Class', priority: true, render: (c) => <span className="font-medium">{c.name} {c.is_default && <Badge tone="blue">Default</Badge>}</span> },
    { key: 'rates', header: 'Rates', align: 'right', render: (c) => c.rates_count },
    { key: 'products', header: 'Products', align: 'right', render: (c) => (c.is_default ? `${c.products_count} + all without a class` : c.products_count) },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (c) => canManage && (
        <span className="flex justify-end gap-1">
          <Button size="sm" variant="ghost" onClick={() => setClassDialog(c)}>Edit<span className="sr-only"> {c.name}</span></Button>
          <Button size="sm" variant="ghost" onClick={() => setRemoving({ kind: 'class', id: c.id, name: c.name })}>Delete<span className="sr-only"> {c.name}</span></Button>
        </span>
      ),
    },
  ];
  const rateColumns: Column<TaxRateRow>[] = [
    { key: 'name', header: 'Rate', priority: true, render: (r) => <span className="font-medium">{r.name}</span> },
    { key: 'rate', header: '%', align: 'right', priority: true, render: (r) => `${ratePercent(r.rate_bps)} %` },
    { key: 'class', header: 'Class', render: (r) => className(r.tax_class_id) },
    { key: 'where', header: 'Where', render: (r) => (r.country === null ? 'Any country' : `${r.country}${r.region ? ` — ${r.region}` : ' (whole country)'}`) },
    { key: 'dates', header: 'Valid', render: (r) => (r.starts_on || r.ends_on ? `${r.starts_on ?? '…'} to ${r.ends_on ?? '…'}` : 'Always') },
    { key: 'active', header: 'Status', render: (r) => (r.is_active ? <Badge tone="green">On</Badge> : <Badge>Off</Badge>) },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (r) => canManage && (
        <span className="flex justify-end gap-1">
          <Button size="sm" variant="ghost" onClick={() => setRateDialog(r)}>Edit<span className="sr-only"> {r.name}</span></Button>
          <Button size="sm" variant="ghost" onClick={() => setRemoving({ kind: 'rate', id: r.id, name: r.name })}>Delete<span className="sr-only"> {r.name}</span></Button>
        </span>
      ),
    },
  ];

  return (
    <AdminPage
      title="Tax"
      description="How your store charges tax: your tax classes, your rates by country and region, and whether prices include tax. Changes apply to new orders; placed orders keep their tax."
    >
      <QueryState state={state} lines={6}>
        {({ data: d }) => (
          <div className="space-y-6">
            <p role="note" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
              No tax rates come with the platform. Enter the rates that apply to your business, as confirmed by your tax adviser, then turn tax on.
            </p>
            <SettingsCard settings={d.settings} hasRates={d.rates.some((r) => r.is_active)} canManage={canManage} onSaved={state.reload} />
            <Card title="Tax classes" description="Groups of products taxed alike, for example Standard, Reduced or Zero rated. Products without a class use the default class." actions={canManage && <Button size="sm" onClick={() => setClassDialog('new')}>Add class</Button>}>
              <DataTable caption="Tax classes" columns={classColumns} rows={d.classes} rowKey={(c) => c.id} empty={<EmptyPanel title="No tax classes yet" description="Add a class (for example “Standard”), then its rates." />} />
            </Card>
            <Card title="Tax rates" description="Every rate that matches the address applies: a country rate and a region rate add up." actions={canManage && d.classes.length > 0 && <Button size="sm" onClick={() => setRateDialog('new')}>Add rate</Button>}>
              <DataTable caption="Tax rates" columns={rateColumns} rows={d.rates} rowKey={(r) => r.id} empty={<EmptyPanel title="No tax rates yet" description={d.classes.length === 0 ? 'Add a tax class first.' : 'Add the rates your business charges.'} />} />
            </Card>
            <PreviewCard classes={d.classes} storeCountry={d.store_country} />
          </div>
        )}
      </QueryState>

      {classDialog && data && <ClassDialog target={classDialog} onClose={() => setClassDialog(null)} onDone={() => { setClassDialog(null); state.reload(); }} />}
      {rateDialog && data && <RateDialog target={rateDialog} classes={data.classes} storeCountry={data.store_country} onClose={() => setRateDialog(null)} onDone={() => { setRateDialog(null); state.reload(); }} />}
      <ConfirmDialog
        open={removing !== null}
        title={removing?.kind === 'class' ? 'Delete this tax class?' : 'Delete this tax rate?'}
        confirmLabel="Delete"
        busy={busy === 'delete'}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing &&
          run('delete', () => adminFetch(`/tax/${removing.kind === 'class' ? 'classes' : 'rates'}/${removing.id}`, { method: 'DELETE' }), { success: 'Deleted.' }).then((done) => {
            if (done !== undefined) {
              setRemoving(null);
              state.reload();
            }
          })
        }
      >
        <p>
          <strong>{removing?.name}</strong> will be removed. Orders already placed keep the tax they were charged.
          {removing?.kind === 'class' && ' Its products move to the default class.'}
        </p>
      </ConfirmDialog>
    </AdminPage>
  );
}

function SettingsCard({ settings, hasRates, canManage, onSaved }: { settings: TaxSettings; hasRates: boolean; canManage: boolean; onSaved: () => void }) {
  const form = useForm<TaxSettings>(settings);
  const v = form.values;
  // Reset only when the saved values change: reloading the page after a rate is added must not undo what is being typed.
  const savedKey = JSON.stringify(settings);
  useEffect(() => form.reset(settings), [savedKey]); // eslint-disable-line react-hooks/exhaustive-deps

  async function save(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch('/tax/settings', { method: 'PUT', body: v }), 'Tax settings saved.')) !== undefined) onSaved();
  }

  return (
    <Card title="How tax is charged" description={settings.enabled ? 'Tax is on: new orders are charged by these rules.' : 'Tax is off: orders are charged no tax.'}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <CheckboxField
          label="Charge tax"
          checked={v.enabled}
          disabled={!canManage || (!v.enabled && !hasRates)}
          onChange={(x) => form.set('enabled', x)}
          error={form.errors.enabled}
          hint={!hasRates && !v.enabled ? 'Add at least one active rate first.' : 'Applies to orders placed from now on.'}
        />
        <CheckboxField label="My prices include tax" checked={v.prices_include_tax} disabled={!canManage} onChange={(x) => form.set('prices_include_tax', x)} hint="On: the price shoppers see already holds the tax, which the order shows separately. Off: tax is added at checkout." />
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField label="Tax is worked out from" value={v.based_on} disabled={!canManage} onChange={(x) => form.set('based_on', x)} options={BASED_ON} error={form.errors.based_on} />
          <TextField label="Name shown to shoppers" value={v.label} disabled={!canManage} onChange={(x) => form.set('label', x)} maxLength={40} error={form.errors.label} hint="For example Tax, GST or Sales tax." />
          <SelectField label="Discounts" value={v.discount_basis} disabled={!canManage} onChange={(x) => form.set('discount_basis', x)} options={[{ value: 'before_tax', label: 'Lower the amount tax is charged on' }, { value: 'after_tax', label: 'Are taken off after tax' }]} error={form.errors.discount_basis} />
          <SelectField label="Rounding" value={v.rounding} disabled={!canManage} onChange={(x) => form.set('rounding', x)} options={[{ value: 'line', label: 'Each product line' }, { value: 'order', label: 'Once per rate on the order' }]} error={form.errors.rounding} />
        </div>
        <CheckboxField label="Charge tax on delivery" checked={v.shipping_taxable} disabled={!canManage} onChange={(x) => form.set('shipping_taxable', x)} hint="Delivery is taxed with the default class’s rates." />
        {canManage && (
          <div className="flex justify-end">
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save tax settings</Button>
          </div>
        )}
      </form>
    </Card>
  );
}

function ClassDialog({ target, onClose, onDone }: { target: TaxClassRow | 'new'; onClose: () => void; onDone: () => void }) {
  const existing = target === 'new' ? null : target;
  const form = useForm({ name: existing?.name ?? '', description: existing?.description ?? '', is_default: existing?.is_default ?? false });

  async function save(event: FormEvent) {
    event.preventDefault();
    const body = { name: form.values.name, description: form.values.description === '' ? null : form.values.description, ...(existing?.is_default ? {} : { is_default: form.values.is_default }) };
    if ((await form.submit(() => adminFetch(existing ? `/tax/classes/${existing.id}` : '/tax/classes', { method: existing ? 'PUT' : 'POST', body }), 'Tax class saved.')) !== undefined) onDone();
  }

  return (
    <Dialog open title={existing ? `Edit ${existing.name}` : 'Add a tax class'} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextField label="Name" value={form.values.name} onChange={(x) => form.set('name', x)} error={form.errors.name} required maxLength={80} data-autofocus />
        <TextField label="Description" optional value={form.values.description} onChange={(x) => form.set('description', x)} error={form.errors.description} maxLength={255} />
        {!existing?.is_default && <CheckboxField label="Default class" checked={form.values.is_default} onChange={(x) => form.set('is_default', x)} hint="Products without a class, and delivery, use the default class." />}
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save class</Button>
        </div>
      </form>
    </Dialog>
  );
}

function RateDialog({ target, classes, storeCountry, onClose, onDone }: { target: TaxRateRow | 'new'; classes: TaxClassRow[]; storeCountry: string | null; onClose: () => void; onDone: () => void }) {
  const r = target === 'new' ? null : target;
  const form = useForm({
    name: r?.name ?? '', tax_class_id: String(r?.tax_class_id ?? classes.find((c) => c.is_default)?.id ?? ''), percent: r ? ratePercent(r.rate_bps) : '',
    country: r ? r.country ?? '' : storeCountry ?? '', region: r?.region ?? '', is_active: r?.is_active ?? true, starts_on: r?.starts_on ?? '', ends_on: r?.ends_on ?? '',
  });
  const v = form.values;
  const [percentError, setPercentError] = useState<string | null>(null);

  async function save(event: FormEvent) {
    event.preventDefault();
    const bps = percentToBps(v.percent);
    setPercentError(bps === null ? 'A percentage from 0 to 100, with at most two decimals (e.g. 16.5).' : null);
    if (bps === null) return;
    const body = {
      name: v.name, tax_class_id: Number(v.tax_class_id), rate_bps: bps, is_active: v.is_active,
      country: v.country.trim() === '' ? null : v.country.trim().toUpperCase(), region: v.region.trim() === '' ? null : v.region.trim(),
      starts_on: v.starts_on === '' ? null : v.starts_on, ends_on: v.ends_on === '' ? null : v.ends_on,
    };
    if ((await form.submit(() => adminFetch(r ? `/tax/rates/${r.id}` : '/tax/rates', { method: r ? 'PUT' : 'POST', body }), 'Tax rate saved.')) !== undefined) onDone();
  }

  return (
    <Dialog open title={r ? `Edit ${r.name}` : 'Add a tax rate'} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label="Name" value={v.name} onChange={(x) => form.set('name', x)} error={form.errors.name} required maxLength={80} hint="Shown on orders, e.g. Sales tax." data-autofocus />
          <TextField label="Rate (%)" inputMode="decimal" value={v.percent} onChange={(x) => form.set('percent', x)} error={percentError ?? form.errors.rate_bps} required />
          <SelectField label="Tax class" value={v.tax_class_id} onChange={(x) => form.set('tax_class_id', x)} options={classes.map((c) => ({ value: String(c.id), label: c.name }))} error={form.errors.tax_class_id} />
          <TextField label="Country" optional value={v.country} onChange={(x) => form.set('country', x)} maxLength={2} error={form.errors.country} hint="Two letters, e.g. PK. Empty: any country." />
          <TextField label="Region" optional value={v.region} onChange={(x) => form.set('region', x)} maxLength={80} error={form.errors.region} hint="A province or state as customers write it, e.g. Punjab. Empty: the whole country." />
          <span />
          <TextField label="Valid from" optional type="date" value={v.starts_on} onChange={(x) => form.set('starts_on', x)} error={form.errors.starts_on} />
          <TextField label="Valid until" optional type="date" value={v.ends_on} onChange={(x) => form.set('ends_on', x)} error={form.errors.ends_on} />
        </div>
        <CheckboxField label="On" checked={v.is_active} onChange={(x) => form.set('is_active', x)} />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save rate</Button>
        </div>
      </form>
    </Dialog>
  );
}

type PreviewResult = { tax_minor: number; shipping_tax_minor: number; total_minor: number; prices_include_tax: boolean; enabled: boolean; breakdown: { name: string; rate_bps: number; tax_minor: number }[] };

function PreviewCard({ classes, storeCountry }: { classes: TaxClassRow[]; storeCountry: string | null }) {
  const access = useAccess();
  const [values, setValues] = useState({ amount: '1000', class: '', country: storeCountry ?? 'PK', region: '' });
  const [result, setResult] = useState<PreviewResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const { busy, run } = useAction();

  async function check(event: FormEvent) {
    event.preventDefault();
    setError(null);
    const amount = toMinor(values.amount, access.currency);
    if (amount === null) {
      setError('Enter an amount.');
      return;
    }
    const body = { amount_minor: amount, tax_class_id: values.class === '' ? null : Number(values.class), country: values.country.trim().toUpperCase(), region: values.region.trim() === '' ? null : values.region.trim() };
    const done = await run('preview', () => adminFetch<{ data: PreviewResult }>('/tax/preview', { method: 'POST', body }), { onError: setError });
    if (done) setResult(done.data);
  }

  return (
    <Card title="Try it" description="The tax on an amount with your rates and settings as they are now — also before tax is turned on.">
      <form onSubmit={check} className="grid gap-4 sm:grid-cols-5 sm:items-end" noValidate>
        <TextField label="Price" inputMode="decimal" value={values.amount} onChange={(x) => setValues({ ...values, amount: x })} />
        <SelectField label="Tax class" optional value={values.class} onChange={(x) => setValues({ ...values, class: x })} placeholder="Default class" options={classes.map((c) => ({ value: String(c.id), label: c.name }))} />
        <TextField label="Country" value={values.country} maxLength={2} onChange={(x) => setValues({ ...values, country: x })} />
        <TextField label="Region" optional value={values.region} onChange={(x) => setValues({ ...values, region: x })} />
        <Button type="submit" busy={busy === 'preview'} busyLabel="Working out…">Work out tax</Button>
      </form>
      <FormError message={error} />
      {result && (
        <div role="status" className="mt-4 rounded-md bg-slate-50 p-3 text-sm">
          <p>
            Tax: <strong>{money(result.tax_minor, access.currency)}</strong> — {result.prices_include_tax ? 'already inside the price' : 'added to the price'}; the customer pays <strong>{money(result.total_minor, access.currency)}</strong>.
          </p>
          {result.breakdown.length === 0 ? <p className="text-slate-600">No rate applies to this class and address.</p> : (
            <ul className="mt-1 list-disc ps-5 text-slate-700">
              {result.breakdown.map((b) => <li key={b.name}>{b.name} ({ratePercent(b.rate_bps)} %): {money(b.tax_minor, access.currency)}</li>)}
            </ul>
          )}
          {!result.enabled && <p className="mt-1 text-amber-800">Tax is off: orders are not charged this yet.</p>}
        </div>
      )}
    </Card>
  );
}
