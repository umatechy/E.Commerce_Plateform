import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog from '@/Components/ui/Dialog';
import { CheckboxField, FormError, SelectField, TextField } from '@/Components/ui/Form';
import Badge, { humanize } from '@/Components/ui/Badge';
import { Card } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { fromMinor, money, toMinor } from '@/lib/money';
import { options } from '@/lib/labels';

/**
 * Module 13 §5/§22 shipping configuration: zones (where you deliver),
 * methods (how), and the rate of a method in a zone
 * (/api/v1/shipping/zones, /methods, /rates).
 *
 * The API adds zones and methods and sets rates; it has no edit or
 * delete for zones and methods, so this page offers none. Setting a rate
 * for a zone and method that already have one replaces it (the server
 * does that).
 */
type Zone = { id: number; name: string; country: string | null; province: string | null; city: string | null; postal_code: string | null; is_default: boolean; is_active: boolean };
type Method = { id: number; name: string; type: string; is_active: boolean; free_shipping_threshold_minor: number | null };
type Rate = { id: number; shipping_zone_id: number; shipping_method_id: number; currency: string; base_cost_minor: number; per_unit_cost_minor: number | null; unit_threshold: string | number | null };

const METHOD_TYPES = ['flat_rate', 'free', 'weight_based', 'price_based', 'store_pickup', 'local_delivery'] as const;

function ZoneDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const form = useForm({ name: '', country: '', province: '', city: '', postal_code: '', is_active: true });

  async function save(event: FormEvent) {
    event.preventDefault();
    const v = form.values;
    const body = { name: v.name, country: v.country === '' ? null : v.country.toUpperCase(), province: v.province || null, city: v.city || null, postal_code: v.postal_code || null, is_active: v.is_active };
    if ((await form.submit(() => adminFetch('/shipping/zones', { method: 'POST', body }), 'Zone added.')) !== undefined) onDone();
  }

  return (
    <Dialog open title="Add shipping zone" description="A zone is an area you deliver to. Leave a field empty to match any value." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextField label="Name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} required maxLength={255} data-autofocus />
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label="Country" optional value={form.values.country} onChange={(v) => form.set('country', v)} error={form.errors.country} maxLength={2} hint="Two-letter code, e.g. PK." />
          <TextField label="Province" optional value={form.values.province} onChange={(v) => form.set('province', v)} error={form.errors.province} />
          <TextField label="City" optional value={form.values.city} onChange={(v) => form.set('city', v)} error={form.errors.city} />
          <TextField label="Postal code" optional value={form.values.postal_code} onChange={(v) => form.set('postal_code', v)} error={form.errors.postal_code} maxLength={32} />
        </div>
        <CheckboxField label="Active" checked={form.values.is_active} onChange={(v) => form.set('is_active', v)} />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Add zone</Button>
        </div>
      </form>
    </Dialog>
  );
}

function MethodDialog({ currency, onClose, onDone }: { currency: string; onClose: () => void; onDone: () => void }) {
  const form = useForm({ name: '', type: 'flat_rate', is_active: true, threshold: '' });
  const [local, setLocal] = useState<string | null>(null);

  async function save(event: FormEvent) {
    event.preventDefault();
    const v = form.values;
    const threshold = v.threshold.trim() === '' ? null : toMinor(v.threshold, currency);
    if (v.threshold.trim() !== '' && threshold === null) {
      setLocal(`Enter an amount such as 50.00 (${currency}).`);

      return;
    }
    setLocal(null);
    const body = { name: v.name, type: v.type, is_active: v.is_active, free_shipping_threshold_minor: threshold };
    if ((await form.submit(() => adminFetch('/shipping/methods', { method: 'POST', body }), 'Method added.')) !== undefined) onDone();
  }

  return (
    <Dialog open title="Add shipping method" onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextField label="Name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} required maxLength={255} hint="Customers see this at checkout." data-autofocus />
        <SelectField label="Type" value={form.values.type} onChange={(v) => form.set('type', v)} error={form.errors.type} options={options(METHOD_TYPES)} />
        <TextField label={`Free shipping from (${currency})`} optional inputMode="decimal" value={form.values.threshold} onChange={(v) => form.set('threshold', v)} error={local ?? form.errors.free_shipping_threshold_minor} hint="Orders of at least this amount ship free with this method." />
        <CheckboxField label="Active" checked={form.values.is_active} onChange={(v) => form.set('is_active', v)} />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Add method</Button>
        </div>
      </form>
    </Dialog>
  );
}

function RateDialog({ zones, methods, currency, onClose, onDone }: { zones: Zone[]; methods: Method[]; currency: string; onClose: () => void; onDone: () => void }) {
  const form = useForm({ shipping_zone_id: '', shipping_method_id: '', currency, base: '', per_unit: '', unit_threshold: '' });
  const [local, setLocal] = useState<Record<string, string>>({});

  async function save(event: FormEvent) {
    event.preventDefault();
    const v = form.values;
    const code = v.currency.toUpperCase();
    const base = toMinor(v.base, code);
    const perUnit = v.per_unit.trim() === '' ? null : toMinor(v.per_unit, code);
    const problems: Record<string, string> = {};
    if (base === null) problems.base_cost_minor = `Enter an amount such as 5.00 (${code}).`;
    if (v.per_unit.trim() !== '' && perUnit === null) problems.per_unit_cost_minor = `Enter an amount such as 1.00 (${code}).`;
    setLocal(problems);
    if (Object.keys(problems).length > 0) return;
    const body = {
      shipping_zone_id: v.shipping_zone_id === '' ? null : Number(v.shipping_zone_id),
      shipping_method_id: v.shipping_method_id === '' ? null : Number(v.shipping_method_id),
      currency: code,
      base_cost_minor: base,
      per_unit_cost_minor: perUnit,
      unit_threshold: v.unit_threshold === '' ? null : Number(v.unit_threshold),
    };
    if ((await form.submit(() => adminFetch('/shipping/rates', { method: 'POST', body }), 'Rate saved.')) !== undefined) onDone();
  }

  const errors = { ...form.errors, ...local };

  return (
    <Dialog open title="Set a rate" description="The cost of one method in one zone. An existing rate for the same zone and method is replaced." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField label="Zone" value={form.values.shipping_zone_id} onChange={(v) => form.set('shipping_zone_id', v)} error={errors.shipping_zone_id} placeholder="Choose a zone" options={zones.map((zone) => ({ value: String(zone.id), label: zone.name }))} required />
          <SelectField label="Method" value={form.values.shipping_method_id} onChange={(v) => form.set('shipping_method_id', v)} error={errors.shipping_method_id} placeholder="Choose a method" options={methods.map((method) => ({ value: String(method.id), label: method.name }))} required />
          <TextField label="Currency" value={form.values.currency} onChange={(v) => form.set('currency', v.toUpperCase())} error={errors.currency} maxLength={3} required />
          <TextField label="Base cost" inputMode="decimal" value={form.values.base} onChange={(v) => form.set('base', v)} error={errors.base_cost_minor} required />
          <TextField label="Cost per extra unit" optional inputMode="decimal" value={form.values.per_unit} onChange={(v) => form.set('per_unit', v)} error={errors.per_unit_cost_minor} />
          <TextField label="Units included in the base cost" optional type="number" min={0} value={form.values.unit_threshold} onChange={(v) => form.set('unit_threshold', v)} error={errors.unit_threshold} />
        </div>
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save rate</Button>
        </div>
      </form>
    </Dialog>
  );
}

export default function Settings() {
  const access = useAccess();
  const zones = useApi<{ data: Zone[] }>('/shipping/zones');
  const methods = useApi<{ data: Method[] }>('/shipping/methods');
  const rates = useApi<{ data: Rate[] }>('/shipping/rates');
  const [dialog, setDialog] = useState<'zone' | 'method' | 'rate' | null>(null);

  const zoneList = zones.data?.data ?? null;
  const methodList = methods.data?.data ?? null;
  const zoneName = (id: number) => zoneList?.find((zone) => zone.id === id)?.name ?? `Zone ${id}`;
  const methodName = (id: number) => methodList?.find((method) => method.id === id)?.name ?? `Method ${id}`;

  const zoneColumns: Column<Zone>[] = [
    { key: 'name', header: 'Zone', render: (zone) => <span className="font-medium">{zone.name} {zone.is_default && <Badge tone="blue">Default</Badge>}</span> },
    { key: 'area', header: 'Area', render: (zone) => [zone.country, zone.province, zone.city, zone.postal_code].filter(Boolean).join(', ') || 'Anywhere' },
    { key: 'active', header: 'Status', priority: true, render: (zone) => <Badge tone={zone.is_active ? 'green' : 'neutral'}>{zone.is_active ? 'Active' : 'Inactive'}</Badge> },
  ];
  const methodColumns: Column<Method>[] = [
    { key: 'name', header: 'Method', render: (method) => <span className="font-medium">{method.name}</span> },
    { key: 'type', header: 'Type', render: (method) => humanize(method.type) },
    { key: 'free', header: 'Free from', align: 'right', render: (method) => (method.free_shipping_threshold_minor === null ? '—' : money(method.free_shipping_threshold_minor, access.currency)) },
    { key: 'active', header: 'Status', priority: true, render: (method) => <Badge tone={method.is_active ? 'green' : 'neutral'}>{method.is_active ? 'Active' : 'Inactive'}</Badge> },
  ];
  const rateColumns: Column<Rate>[] = [
    { key: 'zone', header: 'Zone', render: (rate) => zoneName(rate.shipping_zone_id) },
    { key: 'method', header: 'Method', priority: true, render: (rate) => methodName(rate.shipping_method_id) },
    { key: 'base', header: 'Base cost', align: 'right', priority: true, render: (rate) => money(rate.base_cost_minor, rate.currency) },
    { key: 'unit', header: 'Per extra unit', align: 'right', render: (rate) => (rate.per_unit_cost_minor === null ? '—' : `${fromMinor(rate.per_unit_cost_minor, rate.currency)} ${rate.currency}`) },
  ];

  return (
    <AdminPage title="Shipping setup" description="Where you deliver, how, and what it costs. Customers choose from these at checkout.">
      <div className="space-y-4">
        <Card title="Zones" actions={<Button size="sm" onClick={() => setDialog('zone')}>Add zone</Button>}>
          <DataTable caption="Shipping zones" columns={zoneColumns} rows={zoneList} rowKey={(zone) => zone.id} loading={zones.loading} error={zones.error} onRetry={zones.reload} empty={<p className="text-sm text-slate-600">No zones yet.</p>} />
        </Card>
        <Card title="Methods" actions={<Button size="sm" onClick={() => setDialog('method')}>Add method</Button>}>
          <DataTable caption="Shipping methods" columns={methodColumns} rows={methodList} rowKey={(method) => method.id} loading={methods.loading} error={methods.error} onRetry={methods.reload} empty={<p className="text-sm text-slate-600">No methods yet.</p>} />
        </Card>
        <Card
          title="Rates"
          actions={
            <Button size="sm" onClick={() => setDialog('rate')} disabled={!zoneList?.length || !methodList?.length} title={!zoneList?.length || !methodList?.length ? 'Add a zone and a method first' : undefined}>
              Set a rate
            </Button>
          }
        >
          <DataTable caption="Shipping rates" columns={rateColumns} rows={rates.data?.data ?? null} rowKey={(rate) => rate.id} loading={rates.loading} error={rates.error} onRetry={rates.reload} empty={<p className="text-sm text-slate-600">No rates yet. Without a rate a method cannot be offered in a zone.</p>} />
        </Card>
      </div>

      {dialog === 'zone' && <ZoneDialog onClose={() => setDialog(null)} onDone={() => { setDialog(null); zones.reload(); }} />}
      {dialog === 'method' && <MethodDialog currency={access.currency} onClose={() => setDialog(null)} onDone={() => { setDialog(null); methods.reload(); }} />}
      {dialog === 'rate' && <RateDialog zones={zoneList ?? []} methods={methodList ?? []} currency={access.currency} onClose={() => setDialog(null)} onDone={() => { setDialog(null); rates.reload(); }} />}
    </AdminPage>
  );
}
