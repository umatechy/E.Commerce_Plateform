import { Link } from '@inertiajs/react';
import { useT } from '@/Storefront/i18n';
import { useCallback, useEffect, useState } from 'react';
import AccountLayout from '@/Components/Storefront/AccountLayout';
import EmptyState from '@/Components/EmptyState';
import { formatMoney } from '@/lib/money';
import { errorMessage, storefrontFetch } from '@/Storefront/api';
import { useCustomer } from '@/Storefront/account';
import type { StorefrontPageProps } from '@/Storefront/types';

type Item = {
  id: number;
  product_name: string | null;
  product_slug: string | null;
  variant_options: Record<string, string> | null;
  image_url: string | null;
  current_price_minor: number | null;
  availability: 'available' | 'unavailable';
};

export default function Wishlist({ storefront, seo }: StorefrontPageProps) {
  const t = useT();
  const { customer } = useCustomer(storefront, { required: true });
  const [items, setItems] = useState<Item[] | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const base = storefront.base_path;

  const load = useCallback(() => {
    storefrontFetch<{ data: Item[] }>(storefront, '/wishlist').then((res) => setItems(res.data));
  }, [storefront]);

  useEffect(() => {
    if (customer) load();
  }, [customer, load]);

  async function act(path: string, method: string, success: string) {
    setNotice(null);
    try {
      await storefrontFetch(storefront, path, { method });
      setNotice(success);
      load();
    } catch (e) {
      setNotice(errorMessage(e));
    }
  }

  return (
    <AccountLayout shell={storefront} seo={seo} customer={customer} active="/account/wishlist" title={t('Wishlist')}>
      {notice && (
        <p className="mb-4 text-sm" role="status">
          {notice}
        </p>
      )}
      {items?.length === 0 && <EmptyState title={t('Your wishlist is empty')} description={t('Save products you like from their product page.')} />}
      <ul className="divide-y divide-sf-border">
        {items?.map((item) => (
          <li key={item.id} className="flex items-center gap-4 py-4">
            <div className="h-16 w-16 flex-none overflow-hidden rounded-sf bg-sf-surface">{item.image_url && <img src={item.image_url} alt="" className="h-full w-full object-cover" />}</div>
            <div className="flex-1">
              {item.product_slug ? (
                <Link href={`${base}/products/${item.product_slug}`} className="font-medium">
                  {item.product_name}
                </Link>
              ) : (
                <span className="font-medium">{item.product_name ?? t('No longer available')}</span>
              )}
              {item.variant_options && (
                <span className="block text-sm text-sf-muted">
                  {Object.entries(item.variant_options)
                    .map(([k, v]) => `${k}: ${v}`)
                    .join(' · ')}
                </span>
              )}
              <span className="block text-sm">
                {item.availability === 'available' && item.current_price_minor !== null ? formatMoney(item.current_price_minor, storefront.store.currency) : t('Unavailable')}
              </span>
            </div>
            <div className="flex flex-col items-end gap-2 text-sm">
              {item.availability === 'available' && (
                <button type="button" onClick={() => act(`/wishlist/${item.id}/move-to-cart`, 'POST', t('Moved to your cart.'))} className="sf-btn rounded-sf bg-sf-primary px-3 py-1 text-white">
                  {t('Move to cart')}
                </button>
              )}
              <button type="button" onClick={() => act(`/wishlist/${item.id}`, 'DELETE', t('Removed.'))} className="text-sf-muted">
                {t('Remove')}
              </button>
            </div>
          </li>
        ))}
      </ul>
    </AccountLayout>
  );
}
