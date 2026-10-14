import { FormEvent, useState } from 'react';
import Button from '@/Components/ui/Button';
import { StatusBadge } from '@/Components/ui/Badge';
import { FormError, TextField } from '@/Components/ui/Form';
import { Card } from '@/Components/ui/Page';
import { ShipmentDrawer } from '@/Components/Orders/ShipmentDialogs';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import type { Shipment } from '@/lib/orders';

/**
 * Phase B49 (Module 13 §70): the parcel a customer sends back. Arranged once
 * the return is approved — a pickup booked with a connected courier, or
 * another courier with a printed return label. When the courier collects
 * it, the return moves to "on its way back" by itself.
 */
type CourierSettings = { couriers: { provider: string; label: string; account: { is_enabled: boolean } | null; capabilities: { return_pickup: boolean } }[] };

export default function ReturnParcelCard({ returnId, status, onChanged }: { returnId: string; status: string; onChanged: () => void }) {
  const access = useAccess();
  const parcels = useApi<{ data: Shipment[] }>(`/returns/${returnId}/shipments`);
  const couriers = useApi<{ data: CourierSettings }>('/courier-settings');
  const [open, setOpen] = useState<string | null>(null);
  const pickups = (couriers.data?.data.couriers ?? []).filter((c) => c.account?.is_enabled && c.capabilities.return_pickup);
  const form = useForm({ carrier: '', carrier_name: '', tracking_number: '' });
  const active = (parcels.data?.data ?? []).filter((p) => p.status !== 'cancelled');
  const carrier = form.values.carrier || pickups[0]?.provider || 'manual_courier';
  const canArrange = access.can('returns.manage') && status === 'approved' && active.length === 0;

  async function arrange(event: FormEvent) {
    event.preventDefault();
    const body = { carrier, ...(carrier === 'manual_courier' ? { carrier_name: form.values.carrier_name, tracking_number: form.values.tracking_number.trim() || null } : {}) };
    if ((await form.submit(() => adminFetch(`/returns/${returnId}/shipments`, { method: 'POST', body }), carrier === 'manual_courier' ? 'Return parcel arranged.' : 'Pickup booked with the courier.')) !== undefined) {
      parcels.reload();
      onChanged();
    }
  }

  if (!canArrange && (parcels.data?.data ?? []).length === 0) return null;

  return (
    <Card title="Return parcel" description="How the goods come back to you.">
      {(parcels.data?.data ?? []).map((parcel) => (
        <div key={parcel.id} className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-200 p-3 text-sm">
          <div>
            <p className="font-medium">{parcel.carrier_label} {parcel.tracking_number && <span className="font-mono text-slate-600">· {parcel.tracking_number}</span>}</p>
            {parcel.booking_error && <p className="text-xs text-red-700">{parcel.booking_error}</p>}
          </div>
          <div className="flex items-center gap-2">
            <StatusBadge status={parcel.status} />
            {parcel.status !== 'cancelled' && <a href={`/api/v1/shipments/${parcel.id}/label`} target="_blank" rel="noreferrer" className="rounded-md border border-slate-300 px-2 py-1 text-xs font-medium hover:bg-slate-50">Return label</a>}
            <Button size="sm" variant="ghost" onClick={() => setOpen(parcel.id)}>Open</Button>
          </div>
        </div>
      ))}
      {canArrange && (
        <form onSubmit={arrange} className="space-y-3" noValidate>
          <FormError message={form.formError} />
          <fieldset>
            <legend className="text-sm font-medium text-slate-700">Arrange the parcel</legend>
            <div className="mt-2 grid gap-2 sm:grid-cols-2">
              {[...pickups.map((c) => ({ value: c.provider, title: `${c.label} pickup`, text: 'The courier collects it from the customer.' })), { value: 'manual_courier', title: 'Another courier or drop-off', text: 'Print the return label; add the tracking number if there is one.' }].map((choice) => (
                <label key={choice.value} className={`flex cursor-pointer gap-2 rounded-md border p-3 text-sm ${carrier === choice.value ? 'border-indigo-500 ring-1 ring-indigo-500' : 'border-slate-300'}`}>
                  <input type="radio" name="return-carrier" checked={carrier === choice.value} onChange={() => form.set('carrier', choice.value)} className="mt-0.5" />
                  <span><span className="block font-medium">{choice.title}</span><span className="block text-xs text-slate-500">{choice.text}</span></span>
                </label>
              ))}
            </div>
          </fieldset>
          {carrier === 'manual_courier' && (
            <div className="grid gap-3 sm:grid-cols-2">
              <TextField label="Courier" value={form.values.carrier_name} onChange={(v) => form.set('carrier_name', v)} error={form.errors.carrier_name} maxLength={64} placeholder="e.g. TCS, or Drop-off" required />
              <TextField label="Tracking number" optional value={form.values.tracking_number} onChange={(v) => form.set('tracking_number', v)} error={form.errors.tracking_number} maxLength={64} />
            </div>
          )}
          <div className="flex justify-end">
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Arranging…">{carrier === 'manual_courier' ? 'Arrange return parcel' : 'Book the pickup'}</Button>
          </div>
        </form>
      )}
      {open && <ShipmentDrawer shipmentId={open} onClose={() => setOpen(null)} onChanged={() => { parcels.reload(); onChanged(); }} />}
    </Card>
  );
}
