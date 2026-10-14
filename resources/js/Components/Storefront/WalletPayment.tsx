import { FormEvent, useCallback, useEffect, useState } from 'react';
import { formatMoney } from '@/lib/money';
import { isInFlight, normalizeMobile, usePoll, validCnicLast6, type Attempt } from '@/lib/payments';
import { errorMessage, storefrontFetch } from '@/Storefront/api';
import { useT, type Translate } from '@/Storefront/i18n';
import type { Shell } from '@/Storefront/types';
import { AlertIcon, CheckIcon, ClockIcon, CrossIcon, PhoneIcon } from '@/Components/Payments/icons';
import { walletName, type Payer } from './PaymentChoice';

/**
 * Phase B48 (Module 12 §25, §41–43, §61–62): the shopper's side of a wallet
 * payment, after the order is placed.
 *
 * It follows the payment (GET /checkout/payments/{id} every 3 seconds while
 * an answer is awaited; the server asks the provider) and says what to do:
 * approve on the phone, wait without paying again, or try again — with the
 * same or the other wallet — while the order is held. Paid is the server's
 * word only.
 *
 * Who may follow it: the guest with the token from checkout, or the signed-in
 * customer whose order it is (no token needed).
 */
type WalletOption = { method: string; provider: string; label: string; payer_fields: { key: 'mobile' | 'cnic_last6'; label: string; hint: string }[] };
export type PaymentView = {
  id: string;
  order_number: string | null;
  method: string;
  status: string;
  amount_minor: number;
  currency: string;
  pay_by: string | null;
  can_retry: boolean;
  options: WalletOption[];
  attempt: (Attempt & { reason?: string | null }) | null;
};

/** The shopper's words for why a payment did not go through. */
function reasonText(reason: string | null | undefined, t: Translate): string {
  switch (reason) {
    case 'payment_declined':
      return t('The payment was not approved. Check your balance and try again, or use another wallet.');
    case 'payment_expired':
      return t('The request ran out of time before it was approved. You can send it again.');
    case 'provider_unavailable':
      return t('The payment service is not answering right now. Please try again in a few minutes.');
    case 'invalid_request':
      return t('The payment could not be started. Check the number and try again.');
    default:
      return t('The payment could not be completed. Please try again or use another wallet.');
  }
}

function time(iso: string | null): string {
  return iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
}

type Props = {
  storefront: Shell;
  paymentId: string;
  token: string | null;
  /** What checkout already knows, so the page shows it at once. */
  initial?: { attempt: PaymentView['attempt']; error: { code: string; message: string } | null } | null;
  defaultPayer?: Payer;
  onPaid?: () => void;
};

export default function WalletPayment({ storefront, paymentId, token, initial, defaultPayer, onPaid }: Props) {
  const t = useT();
  const headers = token ? { 'X-Payment-Token': token } : undefined;
  const [view, setView] = useState<PaymentView | null>(null);
  const [attempt, setAttempt] = useState<PaymentView['attempt']>(initial?.attempt ?? null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [payer, setPayer] = useState<Payer>(defaultPayer ?? { mobile: '', cnic_last6: '' });
  const [method, setMethod] = useState<string | null>(null);
  const [errors, setErrors] = useState<Partial<Record<keyof Payer, string>>>({});
  const [retryError, setRetryError] = useState<string | null>(initial?.error?.message ?? null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const res = await storefrontFetch<{ data: PaymentView }>(storefront, `/checkout/payments/${paymentId}`, { headers });
    setView(res.data);
    setAttempt(res.data.attempt);
    setLoadError(null);
  }, [storefront, paymentId, token]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    load().catch((e) => setLoadError(errorMessage(e)));
  }, [load]);

  const state = attempt?.state ?? null;
  usePoll(load, state === null ? view === null : isInFlight(state));

  const paid = view?.status === 'paid' || state === 'paid';
  useEffect(() => {
    if (paid) onPaid?.();
  }, [paid]); // eslint-disable-line react-hooks/exhaustive-deps

  const options = view?.options ?? [];
  const chosen = options.find((o) => o.method === (method ?? view?.method)) ?? options[0];
  const amount = formatMoney(view?.amount_minor ?? attempt?.amount_minor ?? 0, view?.currency ?? attempt?.currency ?? storefront.store.currency);
  const provider = chosen ? walletName(chosen, t) : (attempt?.provider ?? '');

  async function retry(event: FormEvent) {
    event.preventDefault();
    if (!chosen) return;
    const next: typeof errors = {};
    if (normalizeMobile(payer.mobile) === null) next.mobile = t('Enter the mobile number of the wallet, such as 03001234567.');
    if (chosen.payer_fields.some((f) => f.key === 'cnic_last6') && !validCnicLast6(payer.cnic_last6)) next.cnic_last6 = t('Enter the last 6 digits of your CNIC.');
    setErrors(next);
    if (Object.keys(next).length > 0) return;
    setBusy(true);
    setRetryError(null);
    try {
      const body = { method: chosen.method, payer: { mobile: payer.mobile, ...(chosen.payer_fields.some((f) => f.key === 'cnic_last6') ? { cnic_last6: payer.cnic_last6 } : {}) } };
      const res = await storefrontFetch<{ data: PaymentView }>(storefront, `/checkout/payments/${paymentId}/attempts`, { method: 'POST', body, headers });
      setView(res.data);
      setAttempt(res.data.attempt);
    } catch (e) {
      setRetryError(errorMessage(e));
      load().catch(() => undefined);
    } finally {
      setBusy(false);
    }
  }

  if (loadError && !view && !attempt) {
    return <p role="alert" className="rounded-sf bg-red-50 p-3 text-sm text-sf-error">{loadError}</p>;
  }

  const input = 'w-full rounded-sf border border-sf-border px-3 py-2';
  const windowOver = view !== null && !view.can_retry && !isInFlight(state) && !paid;
  const showRetry = !paid && (state === 'failed' || state === 'expired' || state === null) && (view?.can_retry ?? true) && options.length > 0;

  return (
    <section aria-labelledby="wallet-payment-title" className="rounded-sf border border-sf-border p-5">
      <h2 id="wallet-payment-title" className="sr-only">{t('Payment')}</h2>
      <div role="status" aria-live="polite">
        {paid && (
          <Panel icon={<CheckIcon className="h-8 w-8" />} tone="text-emerald-700" title={t('Payment received')}>
            <p>{t('{amount} was paid. Thank you!', { amount })}</p>
          </Panel>
        )}
        {!paid && state === 'waiting' && (
          <Panel icon={<PhoneIcon className="h-8 w-8" />} tone="text-sf-primary" pulse title={t('Check your phone')}>
            <p>{t('We sent a payment request for {amount} to {number}.', { amount, number: attempt?.payer_hint ?? '' })}</p>
            <ol className="mt-3 list-decimal space-y-1 ps-5 text-start">
              <li>{t('Open the notification, or the {provider} app.', { provider })}</li>
              <li>{t('Enter your PIN to approve the payment.')}</li>
              <li>{t('Keep this page open — it updates by itself.')}</li>
            </ol>
          </Panel>
        )}
        {!paid && state === 'confirming' && (
          <Panel icon={<ClockIcon className="h-8 w-8" />} tone="text-amber-700" title={t('Confirming your payment')}>
            <p>{t('This can take a minute. Please do not pay again — this page updates by itself.')}</p>
          </Panel>
        )}
        {!paid && state === 'review' && (
          <Panel icon={<AlertIcon className="h-8 w-8" />} tone="text-amber-700" title={t('We are checking this payment')}>
            <p>{t('The store will contact you about it. Please do not pay again.')}</p>
          </Panel>
        )}
        {!paid && (state === 'failed' || state === 'expired') && (
          <Panel icon={<CrossIcon className="h-8 w-8" />} tone="text-sf-error" title={t('Payment not completed')}>
            <p>{reasonText(attempt?.reason ?? (state === 'expired' ? 'payment_expired' : null), t)}</p>
          </Panel>
        )}
        {windowOver && state !== 'review' && (
          <p className="mt-3 text-center text-sm text-sf-error">{t('The time to pay has run out, so the order was cancelled. You can order again.')}</p>
        )}
      </div>

      {!paid && view?.pay_by && !windowOver && (
        <p className="mt-3 text-center text-xs text-sf-muted">{t('Your order is held until {time}.', { time: time(view.pay_by) })}</p>
      )}

      {showRetry && chosen && (
        <form onSubmit={retry} className="mt-5 space-y-3 border-t border-sf-border pt-4" noValidate>
          <p className="font-medium">{state === null ? t('Pay for your order') : t('Try again')}</p>
          {options.length > 1 && (
            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={t('Wallet')}>
              {options.map((option) => (
                <button
                  key={option.method}
                  type="button"
                  role="radio"
                  aria-checked={chosen.method === option.method}
                  onClick={() => setMethod(option.method)}
                  className={`rounded-sf border px-3 py-1.5 text-sm ${chosen.method === option.method ? 'border-sf-primary bg-sf-primary text-white' : 'border-sf-border'}`}
                >
                  {walletName(option, t)}
                </button>
              ))}
            </div>
          )}
          <div className="grid gap-3 sm:grid-cols-2">
            {chosen.payer_fields.map((field) => (
              <div key={field.key}>
                <label htmlFor={`retry-${field.key}`} className="mb-1 block text-sm font-medium">
                  {field.key === 'mobile' ? t('{provider} mobile number', { provider }) : t('Last 6 digits of your CNIC')}
                </label>
                <input
                  id={`retry-${field.key}`}
                  inputMode="numeric"
                  autoComplete={field.key === 'mobile' ? 'tel' : 'off'}
                  maxLength={field.key === 'mobile' ? 16 : 6}
                  placeholder={field.key === 'mobile' ? '03XX XXXXXXX' : '123456'}
                  value={payer[field.key]}
                  onChange={(e) => setPayer({ ...payer, [field.key]: e.target.value })}
                  aria-invalid={errors[field.key] ? true : undefined}
                  aria-describedby={errors[field.key] ? `retry-${field.key}-error` : undefined}
                  className={input}
                />
                {errors[field.key] && <p id={`retry-${field.key}-error`} role="alert" className="mt-1 text-xs text-sf-error">{errors[field.key]}</p>}
              </div>
            ))}
          </div>
          {retryError && <p role="alert" className="rounded-sf bg-red-50 p-2 text-sm text-sf-error">{retryError}</p>}
          <button type="submit" disabled={busy} className="sf-btn w-full rounded-sf bg-sf-primary px-4 py-3 font-semibold text-white disabled:opacity-50">
            {busy ? t('Sending…') : t('Send payment request for {amount}', { amount })}
          </button>
        </form>
      )}
    </section>
  );
}

function Panel({ icon, tone, title, pulse = false, children }: { icon: React.ReactNode; tone: string; title: string; pulse?: boolean; children: React.ReactNode }) {
  return (
    <div className="flex flex-col items-center text-center">
      <span className={`relative flex h-16 w-16 items-center justify-center rounded-full bg-black/5 ${tone}`}>
        {pulse && <span aria-hidden="true" className="absolute inset-0 rounded-full bg-current opacity-20 motion-safe:animate-ping" />}
        {icon}
      </span>
      <p className="mt-3 text-xl font-semibold">{title}</p>
      <div className="mt-1 max-w-md text-sf-muted">{children}</div>
    </div>
  );
}
