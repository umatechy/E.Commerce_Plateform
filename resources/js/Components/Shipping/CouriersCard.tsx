import { FormEvent, useEffect, useState } from 'react';
import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, TextField } from '@/Components/ui/Form';
import { Card, QueryState } from '@/Components/ui/Page';
import AccountDialog, { type AccountInfo } from '@/Components/Payments/AccountDialog';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { formatDateTime } from '@/lib/datetime';
import type { CredentialFieldDef } from '@/lib/payments';

/**
 * Phase B49 (Module 13 §42–46, §57, §74): the store's couriers on Shipping
 * setup.
 *
 * - Connected couriers book parcels, print their labels and read tracking by
 *   themselves. Only the Test courier exists yet: owner decision 2 waits for
 *   a real courier to be checked in its sandbox before it is offered.
 * - "Another courier" is any courier booked in its own portal (Leopards,
 *   TCS, M&P …): the store types the tracking number, prints the platform's
 *   label, and may give customers a tracking link.
 */
type CourierInfo = AccountInfo & {
  capabilities: { booking: boolean; cancel: boolean; tracking_poll: boolean; cod: boolean; return_pickup: boolean; verified: boolean; note: string | null };
  credential_fields: CredentialFieldDef[];
  account: (NonNullable<AccountInfo['account']> & { id: string; is_enabled: boolean; last_success_at: string | null; last_failure_at: string | null; last_failure_message: string | null }) | null;
};
type Settings = { can_configure: boolean; default_weight_grams: number; tracking_url_template: string | null; couriers: CourierInfo[]; real_couriers: string[] };

export default function CouriersCard() {
  const state = useApi<{ data: Settings }>('/courier-settings');
  const [editing, setEditing] = useState<CourierInfo | null>(null);
  const [removing, setRemoving] = useState<CourierInfo | null>(null);
  const { busy, run } = useAction();

  return (
    <QueryState state={state} lines={4}>
      {({ data }) => (
        <div className="space-y-4">
          <Card title="Couriers" description="Who carries your parcels. A connected courier books the parcel, prints its label and tells you where it is by itself; with another courier you book in its own portal and type the tracking number here.">
            <ul className="grid gap-4 lg:grid-cols-2">
              {data.couriers.map((courier) => {
                const account = courier.account;

                return (
                  <li key={courier.provider} className="flex flex-col rounded-lg border border-slate-200 p-4">
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <h3 className="font-semibold text-slate-900">{courier.label}</h3>
                        {courier.capabilities.note && <p className="mt-0.5 text-xs text-slate-500">{courier.capabilities.note}</p>}
                      </div>
                      {account === null ? <Badge>Not connected</Badge> : account.is_enabled ? <Badge tone="blue">On (test)</Badge> : <Badge>Connected, off</Badge>}
                    </div>
                    <ul aria-label={`${courier.label} can`} className="mt-3 flex flex-wrap gap-1.5 text-xs">
                      {[['booking', 'Books parcels'], ['cancel', 'Cancels'], ['tracking_poll', 'Tracking'], ['cod', 'Cash on delivery'], ['return_pickup', 'Return pickups']].map(([key, label]) => (
                        courier.capabilities[key as keyof CourierInfo['capabilities']] ? <li key={key} className="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-800">{label}</li> : null
                      ))}
                    </ul>
                    {account && (
                      <p className="mt-3 text-xs text-slate-600">
                        {account.last_success_at ? `Last answer ${formatDateTime(account.last_success_at)}` : 'Not used yet'}
                        {account.last_failure_at && <span className="block text-amber-800">Last problem {formatDateTime(account.last_failure_at)}{account.last_failure_message ? `: ${account.last_failure_message}` : ''}</span>}
                      </p>
                    )}
                    {data.can_configure && (
                      <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                        <Button size="sm" variant={account ? 'secondary' : 'primary'} onClick={() => setEditing(courier)}>{account ? 'Edit details' : `Connect ${courier.label}`}</Button>
                        {account && (
                          <Button size="sm" busy={busy === courier.provider} busyLabel="Saving…" onClick={() => run(courier.provider, () => adminFetch(`/courier-settings/couriers/${courier.provider}/enabled`, { method: 'PUT', body: { enabled: !account.is_enabled } }), { success: 'Saved.' }).then((done) => done !== undefined && state.reload())}>
                            {account.is_enabled ? 'Turn off' : 'Turn on'}
                          </Button>
                        )}
                        {account && <Button size="sm" variant="ghost" onClick={() => setRemoving(courier)}>Disconnect</Button>}
                      </div>
                    )}
                  </li>
                );
              })}
              <li className="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-700">
                <h3 className="font-semibold text-slate-900">Leopards, TCS, PostEx and others</h3>
                <p className="mt-1">A courier is connected here once Umar Techy has checked its booking and tracking with your courier account in its sandbox. Until then, book in your courier’s portal and choose <strong>Another courier</strong> when you create the shipment — you still get the label, the tracking number on the order and cash-on-delivery tracking.</p>
              </li>
            </ul>
          </Card>
          <ManualCourierCard settings={data} onSaved={state.reload} />
          {editing && <AccountDialog info={editing} kind="courier" endpoint={`/courier-settings/couriers/${editing.provider}`} currency={null} owner="store" onClose={() => setEditing(null)} onSaved={() => { setEditing(null); state.reload(); }} />}
          <ConfirmDialog
            open={removing !== null}
            title={`Disconnect ${removing?.label ?? ''}?`}
            confirmLabel="Disconnect"
            busy={busy === 'remove'}
            onClose={() => setRemoving(null)}
            onConfirm={() => removing && run('remove', () => adminFetch(`/courier-settings/couriers/${removing.provider}`, { method: 'DELETE' }), { success: 'Disconnected.' }).then((done) => {
              if (done !== undefined) {
                setRemoving(null);
                state.reload();
              }
            })}
          >
            <p>The saved credentials are deleted. Shipments already booked keep their tracking number.</p>
          </ConfirmDialog>
        </div>
      )}
    </QueryState>
  );
}

function ManualCourierCard({ settings, onSaved }: { settings: Settings; onSaved: () => void }) {
  const initial = { weight: String(settings.default_weight_grams / 1000), template: settings.tracking_url_template ?? '' };
  const form = useForm(initial);
  const savedKey = `${settings.default_weight_grams}|${settings.tracking_url_template}`;
  useEffect(() => form.reset(initial), [savedKey]); // eslint-disable-line react-hooks/exhaustive-deps
  const [weightError, setWeightError] = useState<string | null>(null);
  const example = form.values.template.includes('{tracking}') ? form.values.template.replace('{tracking}', 'LE1234567890') : null;

  async function save(event: FormEvent) {
    event.preventDefault();
    const kg = Number(form.values.weight.replace(',', '.'));
    if (!Number.isFinite(kg) || kg <= 0 || kg > 100) {
      setWeightError('A weight from 0.001 to 100 kg.');

      return;
    }
    setWeightError(null);
    if ((await form.submit(() => adminFetch('/courier-settings', { method: 'PUT', body: { default_weight_grams: Math.round(kg * 1000), tracking_url_template: form.values.template.trim() || null } }), 'Shipping defaults saved.')) !== undefined) onSaved();
  }

  return (
    <Card title="Parcels and tracking links" description="Used for every courier, and for the link customers follow with another courier.">
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <div className="grid gap-4 sm:grid-cols-[12rem_1fr]">
          <TextField label="Usual parcel weight (kg)" inputMode="decimal" value={form.values.weight} disabled={!settings.can_configure} onChange={(x) => form.set('weight', x)} error={weightError ?? form.errors.default_weight_grams} hint="When a shipment has no weight of its own." />
          <TextField
            label="Tracking link of your courier"
            optional
            value={form.values.template}
            disabled={!settings.can_configure}
            onChange={(x) => form.set('template', x)}
            error={form.errors.tracking_url_template}
            placeholder="https://…/{tracking}"
            hint={example ? `Customers will open: ${example}` : 'Copy your courier’s tracking page address and put {tracking} where the number goes.'}
          />
        </div>
        {settings.can_configure && (
          <div className="flex justify-end">
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={!form.dirty}>Save</Button>
          </div>
        )}
      </form>
    </Card>
  );
}
