import { formatMoney } from '@/lib/money';
import { useT } from '@/Storefront/i18n';
import { BankIcon, CashIcon, WalletIcon } from '@/Components/Payments/icons';
import BankDetails, { type BankAccount } from './BankDetails';

/**
 * Phase B48 (Module 12 §36, Module 11): the ways to pay at checkout, as
 * cards a shopper can pick at a glance. The store's settings and its
 * package decide which exist (the server sends them); a method whose limit
 * this cart is outside is shown, disabled, with the reason. The server
 * checks all of it again when the order is placed.
 */
export type PaymentOption = {
  method: string;
  kind: 'cod' | 'wallet' | 'bank_transfer';
  label?: string;
  payer_fields?: { key: 'mobile' | 'cnic_last6'; label: string; hint: string }[];
  min_minor?: number | null;
  max_minor?: number | null;
  bank?: BankAccount;
};
export type Payer = { mobile: string; cnic_last6: string };

/** Why this cart cannot use the option, or null. */
export function outOfRange(option: PaymentOption, totalMinor: number | null): 'below' | 'above' | null {
  if (totalMinor === null) return null;
  if (option.min_minor != null && totalMinor < option.min_minor) return 'below';
  if (option.max_minor != null && totalMinor > option.max_minor) return 'above';

  return null;
}

export function walletName(option: Pick<PaymentOption, 'method' | 'label'>, t: (text: string) => string): string {
  return option.method === 'test_wallet' ? t('Test wallet (no real money)') : (option.label ?? option.method);
}

type Props = {
  options: PaymentOption[];
  value: string;
  onChange: (method: string) => void;
  payer: Payer;
  onPayer: (payer: Payer) => void;
  payerErrors: Partial<Record<keyof Payer, string>>;
  totalMinor: number | null;
  currency: string;
};

export default function PaymentChoice({ options, value, onChange, payer, onPayer, payerErrors, totalMinor, currency }: Props) {
  const t = useT();
  const input = 'w-full rounded-sf border border-sf-border px-3 py-2';

  return (
    <div className="space-y-2" role="radiogroup" aria-label={t('Payment')}>
      {options.map((option) => {
        const range = outOfRange(option, totalMinor);
        const selected = value === option.method && range === null;
        const id = `pay-${option.method}`;
        const title = option.kind === 'cod' ? t('Cash on delivery') : option.kind === 'bank_transfer' ? t('Bank transfer') : walletName(option, t);
        const Icon = option.kind === 'cod' ? CashIcon : option.kind === 'bank_transfer' ? BankIcon : WalletIcon;

        return (
          <div key={option.method} className={`rounded-sf border transition-colors ${selected ? 'border-sf-primary ring-1 ring-sf-primary' : 'border-sf-border'} ${range ? 'opacity-60' : ''}`}>
            <label htmlFor={id} className={`flex items-start gap-3 p-3 ${range ? 'cursor-not-allowed' : 'cursor-pointer'}`}>
              {/* Named by its title only; the explanation is its description (a short name for screen readers). */}
              <input id={id} type="radio" name="payment" className="mt-1" checked={selected} disabled={range !== null} onChange={() => onChange(option.method)} aria-labelledby={`${id}-title`} aria-describedby={`${id}-text`} />
              <Icon className="mt-0.5 h-5 w-5 shrink-0 text-sf-muted" />
              <span className="min-w-0">
                <span id={`${id}-title`} className="block font-medium">{title}</span>
                <span id={`${id}-text`} className="block text-sm text-sf-muted">
                  {range === 'below' && t('Available for orders from {amount}.', { amount: formatMoney(option.min_minor ?? 0, currency) })}
                  {range === 'above' && t('Available for orders up to {amount}.', { amount: formatMoney(option.max_minor ?? 0, currency) })}
                  {range === null && option.kind === 'cod' && t('Pay in cash when your order arrives.')}
                  {range === null && option.kind === 'wallet' && t('Approve the payment on your phone — no card needed.')}
                  {range === null && option.kind === 'bank_transfer' && t('Transfer the total to the store’s account. Your order is sent once the money arrives.')}
                </span>
              </span>
            </label>

            {selected && option.kind === 'wallet' && (
              <div className="grid gap-3 border-t border-sf-border p-3 sm:grid-cols-2">
                {(option.payer_fields ?? []).map((field) => (
                  <div key={field.key}>
                    <label htmlFor={`${id}-${field.key}`} className="mb-1 block text-sm font-medium">
                      {field.key === 'mobile' ? t('{provider} mobile number', { provider: walletName(option, t) }) : t('Last 6 digits of your CNIC')}
                    </label>
                    <input
                      id={`${id}-${field.key}`}
                      inputMode="numeric"
                      autoComplete={field.key === 'mobile' ? 'tel' : 'off'}
                      maxLength={field.key === 'mobile' ? 16 : 6}
                      placeholder={field.key === 'mobile' ? '03XX XXXXXXX' : '123456'}
                      value={payer[field.key]}
                      onChange={(e) => onPayer({ ...payer, [field.key]: e.target.value })}
                      aria-invalid={payerErrors[field.key] ? true : undefined}
                      aria-describedby={`${id}-${field.key}-help`}
                      className={input}
                      required
                    />
                    <p id={`${id}-${field.key}-help`} className={`mt-1 text-xs ${payerErrors[field.key] ? 'text-sf-error' : 'text-sf-muted'}`} role={payerErrors[field.key] ? 'alert' : undefined}>
                      {payerErrors[field.key] ?? (field.key === 'mobile' ? t('You will get a request on this phone to approve.') : t('Of the CNIC the account is registered with.'))}
                    </p>
                  </div>
                ))}
              </div>
            )}
            {selected && option.kind === 'bank_transfer' && option.bank && (
              <div className="border-t border-sf-border p-3">
                <BankDetails bank={option.bank} />
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}
