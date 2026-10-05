import type { ProductBadge } from '@/Storefront/types';
import { useT } from '@/Storefront/i18n';

/**
 * Phase B43 (Module 06 §36, Module 17 §26): a product's badges, in the order
 * the server chose (priority, cut to the store's maximum). Automatic badges
 * carry no label — the storefront words them in the visitor's language; the
 * store's own badges come with their (translated) label. Colours are the
 * theme's tokens, so every theme keeps its own look and contrast.
 */
const TONE: Record<string, string> = {
  accent: 'bg-sf-accent text-white',
  success: 'bg-sf-success text-white',
  warning: 'bg-sf-warning text-white',
  danger: 'bg-sf-error text-white',
  neutral: 'bg-gray-900/85 text-white',
};

export function useBadgeLabel() {
  const t = useT();

  return (badge: ProductBadge): string => {
    switch (badge.type) {
      case 'sale':
        return badge.percent ? t('{percent}% off', { percent: badge.percent }) : t('Sale');
      case 'out_of_stock':
        return t('Sold out');
      case 'low_stock':
        return t('Only a few left');
      case 'new':
        return t('New');
      case 'bestseller':
        return t('Bestseller');
      case 'featured':
        return t('Featured');
      default:
        return badge.label ?? '';
    }
  };
}

export default function ProductBadges({ badges, className = '' }: { badges: ProductBadge[]; className?: string }) {
  const label = useBadgeLabel();
  if (badges.length === 0) return null;

  return (
    <ul className={`flex flex-wrap gap-1 ${className}`}>
      {badges.map((badge, index) => (
        <li key={`${badge.type}-${badge.label ?? ''}-${index}`} className={`rounded-sf px-2 py-0.5 text-xs font-semibold ${TONE[badge.tone] ?? TONE.accent}`}>
          {label(badge)}
        </li>
      ))}
    </ul>
  );
}
