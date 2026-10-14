import { TextField } from '@/Components/ui/Form';
import type { PayerFieldDef } from '@/lib/payments';

/**
 * Phase B48: the wallet number (and, for JazzCash, the last six CNIC digits)
 * typed in the admin — for a test payment or to pay an invoice. Checked
 * again by the server; never stored in full.
 */
export type PayerValues = { mobile: string; cnic_last6: string };

export default function PayerFields({ fields, values, onChange, errors }: { fields: PayerFieldDef[]; values: PayerValues; onChange: (next: PayerValues) => void; errors: Partial<Record<keyof PayerValues, string>> }) {
  return (
    <div className="grid gap-4 sm:grid-cols-2">
      {fields.map((field) => (
        <TextField
          key={field.key}
          label={field.label}
          hint={field.hint}
          inputMode="numeric"
          autoComplete={field.key === 'mobile' ? 'tel' : 'off'}
          maxLength={field.key === 'mobile' ? 16 : 6}
          value={values[field.key]}
          onChange={(value) => onChange({ ...values, [field.key]: value })}
          error={errors[field.key]}
          required
        />
      ))}
    </div>
  );
}
