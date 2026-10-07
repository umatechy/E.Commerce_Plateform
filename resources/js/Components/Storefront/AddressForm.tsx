import { useState } from 'react';
import type { Address } from '@/Storefront/account';
import { useT } from '@/Storefront/i18n';

export type AddressInput = Omit<Address, 'id' | 'is_default'> & { is_default?: boolean };

const EMPTY: AddressInput = { label: '', name: '', phone: '', line1: '', line2: '', city: '', province: '', postal_code: '', country: '' };

/** Add or edit a saved address. Validation messages come from the API (422). */
export default function AddressForm({
  initial,
  errors = {},
  busy = false,
  onSubmit,
  onCancel,
}: {
  initial?: Partial<AddressInput>;
  errors?: Record<string, string[]>;
  busy?: boolean;
  onSubmit: (address: AddressInput) => void;
  onCancel?: () => void;
}) {
  const t = useT();
  const [form, setForm] = useState<AddressInput>({ ...EMPTY, ...initial });
  const input = 'w-full rounded-sf border border-sf-border px-3 py-2';
  const field = (key: keyof AddressInput, label: string, props: Record<string, unknown> = {}) => (
    <label className="block text-sm">
      <span className="mb-1 block text-sf-muted">{label}</span>
      <input
        value={String(form[key] ?? '')}
        onChange={(e) => setForm({ ...form, [key]: e.target.value })}
        className={input}
        aria-invalid={Boolean(errors[key])}
        {...props}
      />
      {errors[key] && <span className="mt-1 block text-xs text-sf-error">{errors[key][0]}</span>}
    </label>
  );

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        onSubmit({ ...form, country: form.country.trim().toUpperCase() });
      }}
      className="grid gap-3 sm:grid-cols-2"
    >
      {field('label', t('Label (e.g. Home, Office)'))}
      {field('name', t('Full name'), { required: true, autoComplete: 'name' })}
      {field('phone', t('Phone'), { autoComplete: 'tel' })}
      <div className="sm:col-span-2">{field('line1', t('Street address'), { required: true, autoComplete: 'address-line1' })}</div>
      <div className="sm:col-span-2">{field('line2', t('Apartment, suite (optional)'), { autoComplete: 'address-line2' })}</div>
      {field('city', t('City'), { required: true, autoComplete: 'address-level2' })}
      {field('province', t('Province / state'), { autoComplete: 'address-level1' })}
      {field('postal_code', t('Postal code'), { autoComplete: 'postal-code' })}
      {field('country', t('Country code (e.g. PK)'), { required: true, maxLength: 2, autoComplete: 'country' })}
      <label className="flex items-center gap-2 text-sm sm:col-span-2">
        <input type="checkbox" checked={Boolean(form.is_default)} onChange={(e) => setForm({ ...form, is_default: e.target.checked })} />
        {t('Use as my default address')}
      </label>
      <div className="flex gap-3 sm:col-span-2">
        <button type="submit" disabled={busy} className="sf-btn rounded-sf bg-sf-primary px-5 py-2 font-medium text-white disabled:opacity-50">
          {busy ? t('Saving…') : t('Save address')}
        </button>
        {onCancel && (
          <button type="button" onClick={onCancel} className="rounded-sf border border-sf-border px-5 py-2">
            {t('Cancel')}
          </button>
        )}
      </div>
    </form>
  );
}
