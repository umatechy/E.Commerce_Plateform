import { useCallback, useEffect, useState } from 'react';
import { useT } from '@/Storefront/i18n';
import AccountLayout from '@/Components/Storefront/AccountLayout';
import AddressForm, { type AddressInput } from '@/Components/Storefront/AddressForm';
import { errorMessage, storefrontFetch, validationErrors } from '@/Storefront/api';
import { formatAddress, useCustomer, type Address } from '@/Storefront/account';
import type { StorefrontPageProps } from '@/Storefront/types';

export default function Addresses({ storefront, seo }: StorefrontPageProps) {
  const t = useT();
  const { customer } = useCustomer(storefront, { required: true });
  const [addresses, setAddresses] = useState<Address[] | null>(null);
  const [editing, setEditing] = useState<Address | 'new' | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    storefrontFetch<{ data: Address[] }>(storefront, '/customer/addresses').then((res) => setAddresses(res.data));
  }, [storefront]);

  useEffect(() => {
    if (customer) load();
  }, [customer, load]);

  async function run(action: () => Promise<unknown>) {
    setError(null);
    setErrors({});
    setBusy(true);
    try {
      await action();
      setEditing(null);
      load();
    } catch (e) {
      setErrors(validationErrors(e));
      if (Object.keys(validationErrors(e)).length === 0) setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  const save = (input: AddressInput) =>
    run(() =>
      editing === 'new'
        ? storefrontFetch(storefront, '/customer/addresses', { method: 'POST', body: input })
        : storefrontFetch(storefront, `/customer/addresses/${(editing as Address).id}`, { method: 'PATCH', body: input }),
    );

  return (
    <AccountLayout shell={storefront} seo={seo} customer={customer} active="/account/addresses" title={t('Addresses')}>
      {error && (
        <p className="mb-4 rounded-sf bg-red-50 p-3 text-sm text-sf-error" role="alert">
          {error}
        </p>
      )}
      {editing !== null ? (
        <div className="rounded-sf border border-sf-border p-4">
          <h2 className="mb-4 font-semibold">{editing === 'new' ? t('New address') : t('Edit address')}</h2>
          <AddressForm
            key={editing === 'new' ? 'new' : editing.id}
            initial={editing === 'new' ? { name: customer?.name ?? '' } : editing}
            errors={errors}
            busy={busy}
            onSubmit={save}
            onCancel={() => setEditing(null)}
          />
        </div>
      ) : (
        <button type="button" onClick={() => setEditing('new')} className="sf-btn mb-6 rounded-sf bg-sf-primary px-4 py-2 font-medium text-white">
          {t('Add an address')}
        </button>
      )}

      {addresses?.length === 0 && editing === null && <p className="text-sm text-sf-muted">{t('You have no saved addresses yet.')}</p>}
      <ul className="mt-4 grid gap-4 sm:grid-cols-2">
        {addresses?.map((address) => (
          <li key={address.id} className="rounded-sf border border-sf-border p-4 text-sm">
            <p className="flex items-center justify-between font-semibold">
              {address.label || address.name}
              {address.is_default && <span className="rounded bg-sf-surface px-2 py-0.5 text-xs font-normal">{t('Default')}</span>}
            </p>
            <p className="mt-1 text-sf-muted">{address.name}</p>
            <p className="text-sf-muted">{formatAddress(address)}</p>
            {address.phone && <p className="text-sf-muted">{address.phone}</p>}
            <div className="mt-3 flex gap-3">
              <button type="button" onClick={() => setEditing(address)} className="text-sf-accent">
                {t('Edit')}
              </button>
              {!address.is_default && (
                <button type="button" onClick={() => run(() => storefrontFetch(storefront, `/customer/addresses/${address.id}/default`, { method: 'POST' }))} className="text-sf-accent">
                  {t('Make default')}
                </button>
              )}
              <button
                type="button"
                onClick={() => {
                  if (window.confirm(t('Delete this address?'))) run(() => storefrontFetch(storefront, `/customer/addresses/${address.id}`, { method: 'DELETE' }));
                }}
                className="text-sf-muted"
              >
                {t('Delete')}
              </button>
            </div>
          </li>
        ))}
      </ul>
    </AccountLayout>
  );
}
