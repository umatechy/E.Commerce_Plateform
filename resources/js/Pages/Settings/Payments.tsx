import { FormEvent, useEffect, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import Badge, { type Tone } from '@/Components/ui/Badge';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, SwitchField, TextAreaField, TextField } from '@/Components/ui/Form';
import { Card, QueryState } from '@/Components/ui/Page';
import AccountDialog from '@/Components/Payments/AccountDialog';
import AttemptStatus from '@/Components/Payments/AttemptStatus';
import PayerFields, { type PayerValues } from '@/Components/Payments/PayerFields';
import { BankIcon, CardIcon, CashIcon, WalletIcon } from '@/Components/Payments/icons';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminErrorMessage, adminFetch, fieldErrors, wasCancelled } from '@/lib/adminApi';
import { fromMinor, money, toMinor } from '@/lib/money';
import { formatDateTime } from '@/lib/datetime';
import { PAYMENT_METHOD_LABELS } from '@/lib/orders';
import { isInFlight, normalizeMobile, usePoll, validCnicLast6, type PayerFieldDef, type ProviderInfo, type StaffAttempt } from '@/lib/payments';

/**
 * Phase B48 (gap G9; Module 12 §16, §18–19, §31–37): how the store's
 * customers pay — cash on delivery, bank transfer, and the store's own
 * JazzCash and Easypaisa merchant accounts.
 *
 * The server decides everything shown here (what checkout offers, whether
 * an account may take real money); bank details and accounts ask for the
 * password again before they change. Credentials are never shown back:
 * only a masked hint, and "saved" for secrets.
 */
type Settings = {
  can_configure: boolean;
  online_included: boolean;
  currency: string;
  cod: { enabled: boolean; min_order_minor: number; max_order_minor: number };
  bank_transfer: { enabled: boolean; account_title: string | null; bank_name: string | null; account_number: string | null; instructions: string | null; complete: boolean };
  providers: (ProviderInfo & { payer_fields?: PayerFieldDef[] })[];
  card: { available: boolean };
  checkout_preview: string[];
};

const PAYER_FIELDS: Record<string, PayerFieldDef[]> = {
  jazzcash: [
    { key: 'mobile', label: 'JazzCash mobile number', hint: '03XX XXXXXXX' },
    { key: 'cnic_last6', label: 'Last 6 digits of CNIC', hint: 'Of the CNIC the wallet is registered with' },
  ],
  easypaisa: [{ key: 'mobile', label: 'Easypaisa mobile number', hint: '03XX XXXXXXX' }],
  simulator: [{ key: 'mobile', label: 'Test wallet number', hint: 'Ends in 0002: declined · 0004: no answer · anything else: paid' }],
};

export default function Payments() {
  const state = useApi<{ data: Settings }>('/payment-settings');

  return (
    <AdminPage title="Payments setup" description="Choose how your customers pay. Changes apply at checkout straight away; orders already placed keep their payment method.">
      <QueryState state={state} lines={8}>
        {({ data }) => (
          <div className="space-y-6">
            {!data.can_configure && (
              <p role="note" className="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                You can see these settings. Only the store owner (or someone they give “payment setup” to) can change where the store’s money goes.
              </p>
            )}
            <CheckoutPreview methods={data.checkout_preview} />
            <CodCard settings={data} onSaved={state.reload} />
            <WalletsSection settings={data} onChanged={state.reload} />
            <BankCard settings={data} onSaved={state.reload} />
            <Card>
              <div className="flex items-start gap-3 text-slate-600">
                <CardIcon className="mt-0.5 h-6 w-6 shrink-0 text-slate-400" />
                <div>
                  <h2 className="text-base font-semibold text-slate-900">Card payments <Badge>Coming later</Badge></h2>
                  <p className="mt-1 text-sm">Visa and Mastercard payments come through a card payment provider. Umar Techy is choosing one; it will appear here once it is ready. Card numbers will never be stored by your store.</p>
                </div>
              </div>
            </Card>
          </div>
        )}
      </QueryState>
    </AdminPage>
  );
}

function CheckoutPreview({ methods }: { methods: string[] }) {
  return (
    <section aria-labelledby="checkout-preview" className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
      <h2 id="checkout-preview" className="text-sm font-semibold text-slate-900">Your customers can pay with</h2>
      {methods.length === 0 ? (
        <p className="mt-2 text-sm font-medium text-red-700">Nothing yet — customers cannot place orders until you turn on at least one way to pay.</p>
      ) : (
        <ul className="mt-2 flex flex-wrap gap-2">
          {methods.map((method) => (
            <li key={method} className="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-sm font-medium text-emerald-900">
              {PAYMENT_METHOD_LABELS[method] ?? method}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function SectionTitle({ icon, title, badge }: { icon: React.ReactNode; title: string; badge?: React.ReactNode }) {
  return (
    <span className="flex flex-wrap items-center gap-2">
      <span className="text-slate-500">{icon}</span>
      <span>{title}</span>
      {badge}
    </span>
  );
}

function CodCard({ settings, onSaved }: { settings: Settings; onSaved: () => void }) {
  const { currency, cod } = settings;
  const initial = { enabled: cod.enabled, min: cod.min_order_minor > 0 ? fromMinor(cod.min_order_minor, currency) : '', max: cod.max_order_minor > 0 ? fromMinor(cod.max_order_minor, currency) : '' };
  const form = useForm(initial);
  const savedKey = JSON.stringify(cod);
  useEffect(() => form.reset(initial), [savedKey]); // eslint-disable-line react-hooks/exhaustive-deps
  const [amountErrors, setAmountErrors] = useState<{ min?: string; max?: string }>({});

  async function save(event: FormEvent) {
    event.preventDefault();
    const min = form.values.min.trim() === '' ? 0 : toMinor(form.values.min, currency);
    const max = form.values.max.trim() === '' ? 0 : toMinor(form.values.max, currency);
    const errors = { min: min === null ? 'Enter an amount, or leave it empty.' : undefined, max: max === null ? 'Enter an amount, or leave it empty.' : undefined };
    setAmountErrors(errors);
    if (min === null || max === null) return;
    if ((await form.submit(() => adminFetch('/payment-settings/cod', { method: 'PUT', body: { enabled: form.values.enabled, min_order_minor: min, max_order_minor: max } }), 'Cash on delivery saved.')) !== undefined) onSaved();
  }

  return (
    <Card title={<SectionTitle icon={<CashIcon />} title="Cash on delivery" badge={cod.enabled ? <Badge tone="green">On</Badge> : <Badge>Off</Badge>} />} description="Customers pay the courier in cash when the order arrives. You record the cash when it reaches you.">
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <SwitchField label="Offer cash on delivery" checked={form.values.enabled} disabled={!settings.can_configure} onChange={(x) => form.set('enabled', x)} />
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label={`Smallest order (${currency})`} optional inputMode="decimal" value={form.values.min} disabled={!settings.can_configure} onChange={(x) => form.set('min', x)} error={amountErrors.min ?? form.errors.min_order_minor} hint="Empty: any amount." />
          <TextField label={`Largest order (${currency})`} optional inputMode="decimal" value={form.values.max} disabled={!settings.can_configure} onChange={(x) => form.set('max', x)} error={amountErrors.max ?? form.errors.max_order_minor} hint="Many stores limit cash orders to reduce returns. Empty: no limit." />
        </div>
        {settings.can_configure && (
          <div className="flex justify-end">
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={!form.dirty}>Save cash on delivery</Button>
          </div>
        )}
      </form>
    </Card>
  );
}

function BankCard({ settings, onSaved }: { settings: Settings; onSaved: () => void }) {
  const bank = settings.bank_transfer;
  const initial = { enabled: bank.enabled && bank.complete, account_title: bank.account_title ?? '', bank_name: bank.bank_name ?? '', account_number: bank.account_number ?? '', instructions: bank.instructions ?? '' };
  const form = useForm(initial);
  const savedKey = JSON.stringify(bank);
  useEffect(() => form.reset(initial), [savedKey]); // eslint-disable-line react-hooks/exhaustive-deps
  const v = form.values;
  const filled = v.account_title.trim() !== '' && v.bank_name.trim() !== '' && v.account_number.trim() !== '';

  async function save(event: FormEvent) {
    event.preventDefault();
    if ((await form.submit(() => adminFetch('/payment-settings/bank-transfer', { method: 'PUT', body: v }), 'Bank transfer saved.')) !== undefined) onSaved();
  }

  const status = bank.enabled && bank.complete ? <Badge tone="green">On</Badge> : bank.complete ? <Badge>Off</Badge> : <Badge tone="amber">Account needed</Badge>;

  return (
    <Card title={<SectionTitle icon={<BankIcon />} title="Bank transfer" badge={status} />} description="Customers transfer the total to your bank account using the order number as the reference. You confirm the order once the money arrives.">
      <form onSubmit={save} className="grid gap-6 lg:grid-cols-[1fr_280px]" noValidate>
        <div className="space-y-4">
          <FormError message={form.formError} />
          <SwitchField label="Offer bank transfer" checked={v.enabled} disabled={!settings.can_configure || (!filled && !v.enabled)} onChange={(x) => form.set('enabled', x)} hint={!filled ? 'Fill in the account first.' : undefined} />
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label="Account title" value={v.account_title} maxLength={120} disabled={!settings.can_configure} onChange={(x) => form.set('account_title', x)} error={form.errors.account_title} hint="The name on the account, as the bank shows it." />
            <TextField label="Bank" value={v.bank_name} maxLength={120} disabled={!settings.can_configure} onChange={(x) => form.set('bank_name', x)} error={form.errors.bank_name} hint="For example Meezan Bank." />
          </div>
          <TextField label="Account number or IBAN" value={v.account_number} maxLength={40} disabled={!settings.can_configure} onChange={(x) => form.set('account_number', x)} error={form.errors.account_number} hint="An IBAN (PK36 …) works from every Pakistani bank and wallet." />
          <TextAreaField label="Extra instructions" optional rows={2} value={v.instructions} maxLength={500} disabled={!settings.can_configure} onChange={(x) => form.set('instructions', x)} error={form.errors.instructions} hint="For example: send the receipt on WhatsApp to 0300 1234567." />
          {settings.can_configure && (
            <div className="flex flex-wrap items-center justify-end gap-3">
              <p className="text-xs text-slate-500">You will be asked for your password: this decides where customers send money.</p>
              <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={!form.dirty}>Save bank transfer</Button>
            </div>
          )}
        </div>
        <aside aria-label="What customers see" className="h-fit rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm">
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Customers see</p>
          {filled ? (
            <dl className="mt-2 space-y-1">
              <div><dt className="inline text-slate-500">Account title: </dt><dd className="inline font-medium">{v.account_title}</dd></div>
              <div><dt className="inline text-slate-500">Bank: </dt><dd className="inline font-medium">{v.bank_name}</dd></div>
              <div><dt className="inline text-slate-500">Account: </dt><dd className="inline break-all font-mono">{v.account_number}</dd></div>
              <div><dt className="inline text-slate-500">Reference: </dt><dd className="inline font-medium">their order number</dd></div>
              {v.instructions.trim() !== '' && <p className="pt-1 text-slate-700">{v.instructions}</p>}
            </dl>
          ) : (
            <p className="mt-2 text-slate-500">The account details appear here as you type.</p>
          )}
        </aside>
      </form>
    </Card>
  );
}

function providerStatus(info: ProviderInfo): { label: string; tone: Tone } {
  const account = info.account;
  if (account === null) return { label: 'Not connected', tone: 'neutral' };
  if (info.provider === 'simulator') return account.is_enabled ? { label: 'On at checkout (test)', tone: 'blue' } : { label: 'Connected, off', tone: 'neutral' };
  if (account.environment === 'sandbox') return { label: 'Sandbox — testing only', tone: 'amber' };
  if (!info.live_allowed) return { label: 'Paused by Umar Techy', tone: 'red' };

  return account.is_enabled ? { label: 'Live — taking payments', tone: 'green' } : { label: 'Live, turned off', tone: 'neutral' };
}

function WalletsSection({ settings, onChanged }: { settings: Settings; onChanged: () => void }) {
  const [editing, setEditing] = useState<ProviderInfo | null>(null);
  const [testing, setTesting] = useState<ProviderInfo | null>(null);
  const [removing, setRemoving] = useState<ProviderInfo | null>(null);
  const { busy, run } = useAction();

  return (
    <Card title={<SectionTitle icon={<WalletIcon />} title="Mobile wallets" />} description="Customers pay from their JazzCash or Easypaisa account: they type the wallet number at checkout and approve on their phone. The money goes to your own merchant account.">
      {!settings.online_included ? (
        <p className="text-sm text-slate-700">Online payments are not part of your package. See Billing to change your plan.</p>
      ) : (
        <ul className="grid gap-4 lg:grid-cols-2">
          {settings.providers.map((info) => (
            <ProviderCard
              key={info.provider}
              info={info}
              currency={settings.currency}
              canConfigure={settings.can_configure}
              busy={busy === info.provider}
              onEdit={() => setEditing(info)}
              onTest={() => setTesting(info)}
              onRemove={() => setRemoving(info)}
              onToggle={(enabled) =>
                run(info.provider, () => adminFetch(`/payment-settings/providers/${info.provider}/enabled`, { method: 'PUT', body: { enabled } }), { success: enabled ? `${info.label} is now offered at checkout.` : `${info.label} is no longer offered.` }).then((done) => done !== undefined && onChanged())
              }
            />
          ))}
        </ul>
      )}
      {editing && <AccountDialog info={editing} endpoint={`/payment-settings/providers/${editing.provider}`} currency={settings.currency} owner="store" onClose={() => setEditing(null)} onSaved={() => { setEditing(null); onChanged(); }} />}
      {testing && <TestPaymentDialog info={testing} onClose={() => { setTesting(null); onChanged(); }} />}
      <ConfirmDialog
        open={removing !== null}
        title={`Disconnect ${removing?.label ?? ''}?`}
        confirmLabel="Disconnect"
        busy={busy === 'remove'}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing && run('remove', () => adminFetch(`/payment-settings/providers/${removing.provider}`, { method: 'DELETE' }), { success: `${removing.label} disconnected.` }).then((done) => {
            if (done !== undefined) {
              setRemoving(null);
              onChanged();
            }
          })
        }
      >
        <p>The saved credentials are deleted and customers can no longer pay with {removing?.label}. Past payments stay in your records.</p>
      </ConfirmDialog>
    </Card>
  );
}

function ProviderCard({ info, currency, canConfigure, busy, onEdit, onTest, onRemove, onToggle }: {
  info: ProviderInfo; currency: string; canConfigure: boolean; busy: boolean;
  onEdit: () => void; onTest: () => void; onRemove: () => void; onToggle: (enabled: boolean) => void;
}) {
  const account = info.account;
  const status = providerStatus(info);
  const canTurnOn = account !== null && (info.provider === 'simulator' || (account.environment === 'production' && info.live_allowed));
  const hints = account ? info.credential_fields.filter((f) => !f.secret).map((f) => `${f.label}: ${account.credential_hints[f.key] ?? '—'}`) : [];

  return (
    <li className="flex flex-col rounded-lg border border-slate-200 p-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h3 className="text-base font-semibold text-slate-900">{info.label}</h3>
          <p className="mt-0.5 text-xs text-slate-500">{info.provider === 'simulator' ? 'Moves no money. Only on this test system.' : 'Mobile account payments, approved on the phone.'}</p>
        </div>
        <Badge tone={status.tone}>{status.label}</Badge>
      </div>

      {!info.capabilities.verified && (
        <p className="mt-3 rounded-md bg-amber-50 p-2 text-xs text-amber-900">
          Umar Techy has not yet checked {info.label} in its sandbox. You can connect and test a sandbox account now; real payments open once the check is done.
        </p>
      )}
      {account && account.environment === 'sandbox' && info.provider !== 'simulator' && (
        <p className="mt-3 rounded-md bg-slate-50 p-2 text-xs text-slate-700">Customers never see a sandbox account. Send a test payment to check it, then connect your production account to take real payments.</p>
      )}

      {account && (
        <dl className="mt-3 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
          <div><dt className="text-xs text-slate-500">Environment</dt><dd>{account.environment === 'production' ? 'Production (real money)' : 'Sandbox (testing)'}</dd></div>
          {hints.map((hint) => {
            const [label, value] = hint.split(': ');

            return <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="font-mono">{value}</dd></div>;
          })}
          <div><dt className="text-xs text-slate-500">Last payment</dt><dd>{account.last_success_at ? formatDateTime(account.last_success_at) : 'None yet'}</dd></div>
          {account.last_failure_at && <div><dt className="text-xs text-slate-500">Last problem</dt><dd>{formatDateTime(account.last_failure_at)}</dd></div>}
          {(account.min_amount_minor !== null || account.max_amount_minor !== null) && (
            <div className="sm:col-span-2"><dt className="text-xs text-slate-500">Order amounts</dt><dd>{account.min_amount_minor !== null ? `from ${money(account.min_amount_minor, currency)} ` : ''}{account.max_amount_minor !== null ? `up to ${money(account.max_amount_minor, currency)}` : ''}</dd></div>
          )}
        </dl>
      )}

      {canConfigure && (
        <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
          <Button size="sm" variant={account ? 'secondary' : 'primary'} onClick={onEdit}>{account ? 'Edit details' : `Connect ${info.label}`}</Button>
          {account && account.environment === 'sandbox' && <Button size="sm" onClick={onTest}>Send a test payment</Button>}
          {account && canTurnOn && (
            <Button size="sm" variant={account.is_enabled ? 'secondary' : 'primary'} busy={busy} busyLabel="Saving…" onClick={() => onToggle(!account.is_enabled)}>
              {account.is_enabled ? 'Turn off at checkout' : 'Offer at checkout'}
            </Button>
          )}
          {account && <Button size="sm" variant="ghost" onClick={onRemove}>Disconnect</Button>}
        </div>
      )}
    </li>
  );
}

function TestPaymentDialog({ info, onClose }: { info: ProviderInfo; onClose: () => void }) {
  const fields = PAYER_FIELDS[info.provider] ?? PAYER_FIELDS.easypaisa;
  const [payer, setPayer] = useState<PayerValues>({ mobile: '', cnic_last6: '' });
  const [errors, setErrors] = useState<Partial<Record<keyof PayerValues, string>>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState<StaffAttempt | null>(null);
  const [busy, setBusy] = useState(false);

  usePoll(async () => {
    if (!attempt) return;
    const res = await adminFetch<{ data: StaffAttempt }>(`/payment-attempts/${attempt.id}`);
    setAttempt(res.data);
  }, attempt !== null && isInFlight(attempt.state));

  async function start(event: FormEvent) {
    event.preventDefault();
    const next: typeof errors = {};
    if (normalizeMobile(payer.mobile) === null) next.mobile = 'Enter a mobile number such as 03001234567.';
    if (fields.some((f) => f.key === 'cnic_last6') && !validCnicLast6(payer.cnic_last6)) next.cnic_last6 = 'Enter the last 6 digits.';
    setErrors(next);
    if (Object.keys(next).length > 0) return;
    setBusy(true);
    setFormError(null);
    try {
      const res = await adminFetch<{ data: StaffAttempt }>(`/payment-settings/providers/${info.provider}/test`, { method: 'POST', body: { payer } });
      setAttempt(res.data);
    } catch (e) {
      if (!wasCancelled(e)) {
        const fe = fieldErrors(e);
        setErrors({ mobile: fe['payer.mobile'], cnic_last6: fe['payer.cnic_last6'] });
        setFormError(adminErrorMessage(e));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog open title={`Test payment with ${info.label}`} description="A payment of Rs 1.00 against your sandbox account, to see that the details work." onClose={onClose} busy={busy}>
      {attempt ? (
        <div className="space-y-4">
          <AttemptStatus attempt={attempt} providerLabel={info.label} />
          {attempt.failure_label && <p className="text-sm text-slate-600">Provider said: {attempt.failure_label}{attempt.provider_code ? ` (code ${attempt.provider_code})` : ''}{attempt.provider_message ? ` — ${attempt.provider_message}` : ''}</p>}
          <div className="flex justify-end gap-2">
            {!isInFlight(attempt.state) && attempt.state !== 'paid' && <Button onClick={() => setAttempt(null)}>Try again</Button>}
            <Button variant="primary" onClick={onClose}>{attempt.state === 'paid' ? 'Done' : 'Close'}</Button>
          </div>
        </div>
      ) : (
        <form onSubmit={start} className="space-y-4" noValidate>
          <FormError message={formError} />
          <p className="text-sm text-slate-700">Use a sandbox wallet from {info.label}. You will be asked to approve on that phone.</p>
          <PayerFields fields={fields} values={payer} onChange={setPayer} errors={errors} />
          <div className="flex justify-end gap-2">
            <Button onClick={onClose} disabled={busy}>Cancel</Button>
            <Button type="submit" variant="primary" busy={busy} busyLabel="Sending…">Send test payment</Button>
          </div>
        </form>
      )}
    </Dialog>
  );
}
