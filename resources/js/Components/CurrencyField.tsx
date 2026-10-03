import { SelectField } from '@/Components/ui/Form';
import { useAccess } from '@/lib/access';
import { currencyLabel } from '@/lib/money';

/**
 * Picks one of the platform's currencies (owner decision 2026-10-03:
 * PKR first, then USD, EUR, GBP, AED, SAR; platform staff keep the list
 * in `platform.supported_currencies`). The server accepts only these. A
 * value saved earlier that is no longer offered is still shown, so a
 * record is never silently moved to another currency.
 */
export default function CurrencyField({ value, onChange, error, label = 'Currency', hint, disabled }: { value: string; onChange: (code: string) => void; error?: string | null; label?: string; hint?: string; disabled?: boolean }) {
  const { currencies } = useAccess();
  const codes = value !== '' && !currencies.includes(value) ? [value, ...currencies] : currencies;

  return (
    <SelectField
      label={label}
      value={value}
      onChange={onChange}
      error={error}
      hint={hint}
      disabled={disabled}
      options={codes.map((code) => ({ value: code, label: currencyLabel(code), disabled: !currencies.includes(code) }))}
    />
  );
}
