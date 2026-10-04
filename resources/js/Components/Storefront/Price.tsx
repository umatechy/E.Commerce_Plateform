import { formatMoney } from '@/lib/money';
import { useT } from '@/Storefront/i18n';
import type { Money } from '@/Storefront/types';

/** "From $19.00" for a price range, a struck-through "was" price when on sale. */
export default function Price({ price, className = '' }: { price: Money; className?: string }) {
  const t = useT();
  if (price.amount_minor === null) {
    return <span className={`text-sf-muted ${className}`}>{t('Price on request')}</span>;
  }

  return (
    <span className={`inline-flex items-baseline gap-2 ${className}`}>
      <span className={price.on_sale ? 'font-semibold text-sf-error' : 'font-semibold'}>
        {price.max_amount_minor !== null && `${t('From')} `}
        {formatMoney(price.amount_minor, price.currency)}
      </span>
      {price.compare_at_minor !== null && (
        <s className="text-sm text-sf-muted" aria-label={t('Regular price')}>
          {formatMoney(price.compare_at_minor, price.currency)}
        </s>
      )}
    </span>
  );
}
