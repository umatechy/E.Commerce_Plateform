import { FormEvent } from 'react';
import Button from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { FormError, TextField } from '@/Components/ui/Form';
import { useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { fromMinor, toMinor } from '@/lib/money';
import type { ProviderInfo } from '@/lib/payments';

/**
 * Phase B48 (Module 12 §31–33; Module 30 §27): connect or change a merchant
 * account — a store's own (with its payment limits) or Umar Techy's (for
 * invoices, no limits). Secrets are write-only: an empty field keeps the
 * saved value; nothing saved is ever shown in full. Sandbox and production
 * credentials are kept apart: switching environment asks for every value.
 */
export default function AccountDialog({ info, endpoint, currency, owner, onClose, onSaved }: {
  info: ProviderInfo;
  endpoint: string;
  /** Store accounts: the store currency, for the minimum and maximum. Null: no limits (Umar Techy's own account). */
  currency: string | null;
  owner: 'store' | 'platform';
  onClose: () => void;
  onSaved: () => void;
}) {
  const account = info.account;
  const form = useForm({
    environment: (account?.environment ?? 'sandbox') as 'sandbox' | 'production',
    credentials: Object.fromEntries(info.credential_fields.map((f) => [f.key, ''])) as Record<string, string>,
    min: currency && account?.min_amount_minor != null ? fromMinor(account.min_amount_minor, currency) : '',
    max: currency && account?.max_amount_minor != null ? fromMinor(account.max_amount_minor, currency) : '',
  });
  const v = form.values;
  // Saved values only count in the environment they were saved for.
  const keeps = (key: string) => account !== null && account.environment === v.environment && account.credential_hints[key] !== undefined;
  const productionBlocked = info.provider === 'simulator' || !info.live_allowed;
  const whose = owner === 'store' ? 'your' : 'Umar Techy’s';

  async function save(event: FormEvent) {
    event.preventDefault();
    const limits = currency ? { min_amount_minor: v.min.trim() === '' ? null : toMinor(v.min, currency), max_amount_minor: v.max.trim() === '' ? null : toMinor(v.max, currency) } : {};
    const body = { environment: v.environment, credentials: v.credentials, ...limits };
    if ((await form.submit(() => adminFetch(endpoint, { method: 'PUT', body }), `${info.label} details saved.`)) !== undefined) onSaved();
  }

  return (
    <Dialog open title={account ? `${info.label} details` : `Connect ${info.label}`} description={`From ${whose} merchant account with ${info.label}. Saved encrypted; never shown again in full.`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <fieldset>
          <legend className="text-sm font-medium text-slate-700">Environment</legend>
          <div className="mt-2 grid gap-2 sm:grid-cols-2">
            {(['sandbox', 'production'] as const).map((env) => {
              const disabled = env === 'production' && productionBlocked;

              return (
                <label key={env} className={`flex cursor-pointer gap-2 rounded-md border p-3 text-sm ${v.environment === env ? 'border-indigo-500 ring-1 ring-indigo-500' : 'border-slate-300'} ${disabled ? 'cursor-not-allowed opacity-60' : ''}`}>
                  <input type="radio" name="environment" value={env} checked={v.environment === env} disabled={disabled} onChange={() => form.set('environment', env)} className="mt-0.5" />
                  <span>
                    <span className="block font-medium">{env === 'sandbox' ? 'Sandbox' : 'Production'}</span>
                    <span className="block text-xs text-slate-500">
                      {env === 'sandbox'
                        ? 'Test credentials. No real money.'
                        : disabled
                          ? (info.provider === 'simulator' ? 'The test wallet has no production.' : `Opens once ${info.label} is open for real payments (Payment providers).`)
                          : `Real payments into ${whose} account.`}
                    </span>
                  </span>
                </label>
              );
            })}
          </div>
          {form.errors.environment && <p role="alert" className="mt-1 text-xs font-medium text-red-700">{form.errors.environment}</p>}
          {account && account.environment !== v.environment && <p className="mt-2 text-xs text-amber-800">Switching environment needs every value again, and the account starts switched off.</p>}
        </fieldset>
        {info.credential_fields.map((field) => (
          <TextField
            key={field.key}
            label={field.label}
            type={field.secret ? 'password' : 'text'}
            autoComplete="off"
            maxLength={field.max_length}
            value={v.credentials[field.key] ?? ''}
            onChange={(x) => form.set('credentials', { ...v.credentials, [field.key]: x })}
            placeholder={keeps(field.key) ? (field.secret ? 'Saved — leave empty to keep' : `Saved: ${account?.credential_hints[field.key]} — leave empty to keep`) : undefined}
            error={form.errors[`credentials.${field.key}`]}
            hint={field.help ?? undefined}
          />
        ))}
        {currency && (
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label={`Smallest payment (${currency})`} optional inputMode="decimal" value={v.min} onChange={(x) => form.set('min', x)} error={form.errors.min_amount_minor} />
            <TextField label={`Largest payment (${currency})`} optional inputMode="decimal" value={v.max} onChange={(x) => form.set('max', x)} error={form.errors.max_amount_minor} hint="Your wallet account may have its own limit." />
          </div>
        )}
        <p className="text-xs text-slate-500">You will be asked for your password before this is saved.</p>
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save details</Button>
        </div>
      </form>
    </Dialog>
  );
}
