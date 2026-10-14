import { useState } from 'react';
import { useT } from '@/Storefront/i18n';
import { CopyIcon } from '@/Components/Payments/icons';

/**
 * Phase B48 (Module 12 §18): the store's bank account, with a copy button
 * for the numbers a shopper has to type into their banking app. The
 * reference is the order number once there is one.
 */
export type BankAccount = { account_title: string; bank_name: string; account_number: string; instructions: string | null };

function CopyButton({ value, label }: { value: string; label: string }) {
  const t = useT();
  const [copied, setCopied] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      /* clipboard refused: the number stays visible to copy by hand */
    }
  }

  return (
    <button type="button" onClick={copy} className="inline-flex shrink-0 items-center gap-1 rounded-sf border border-sf-border px-2 py-1 text-xs hover:bg-black/5" aria-label={t('Copy {what}', { what: label })}>
      <CopyIcon className="h-3.5 w-3.5" />
      <span aria-live="polite">{copied ? t('Copied') : t('Copy')}</span>
    </button>
  );
}

export default function BankDetails({ bank, reference }: { bank: BankAccount; reference?: string }) {
  const t = useT();
  const rows: { label: string; value: string; copy?: boolean; mono?: boolean }[] = [
    { label: t('Account title'), value: bank.account_title },
    { label: t('Bank'), value: bank.bank_name },
    { label: t('Account number or IBAN'), value: bank.account_number, copy: true, mono: true },
    ...(reference ? [{ label: t('Reference'), value: reference, copy: true, mono: true }] : []),
  ];

  return (
    <div className="space-y-2 text-sm">
      <dl className="divide-y divide-sf-border rounded-sf border border-sf-border">
        {rows.map((row) => (
          <div key={row.label} className="flex items-center justify-between gap-3 px-3 py-2">
            <div className="min-w-0">
              <dt className="text-xs text-sf-muted">{row.label}</dt>
              <dd className={`break-all font-medium ${row.mono ? 'font-mono' : ''}`} dir="ltr">{row.value}</dd>
            </div>
            {row.copy && <CopyButton value={row.value} label={row.label} />}
          </div>
        ))}
      </dl>
      {!reference && <p className="text-sf-muted">{t('Use your order number as the reference. You will see it after you place the order.')}</p>}
      {bank.instructions && <p className="text-sf-muted">{bank.instructions}</p>}
    </div>
  );
}
