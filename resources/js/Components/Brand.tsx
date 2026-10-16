import { useId } from 'react';
import { usePage } from '@inertiajs/react';

/**
 * UI-1 — the platform's name and logo. Both come from Super Admin →
 * Platform settings (platform.brand_name, platform.logo_url), shared with
 * every page as `brand`; without a logo address the built-in Umar Techy
 * mark below is drawn.
 */
export type Brand = { name: string; logo_url: string | null };

const FALLBACK: Brand = { name: 'Umar Techy', logo_url: null };

export function useBrand(): Brand {
  const props = usePage<{ brand?: Partial<Brand> }>().props;

  return { name: props.brand?.name || FALLBACK.name, logo_url: props.brand?.logo_url ?? null };
}

/** The built-in mark: a shop bag with a rising spark, in the platform's indigo. */
export function BrandMark({ size = 36, className = '' }: { size?: number; className?: string }) {
  // Each mark its own gradient id: a shared id breaks when the first mark is hidden (display: none).
  const gradient = `ut-mark-${useId().replace(/:/g, '')}`;

  return (
    <svg width={size} height={size} viewBox="0 0 40 40" aria-hidden="true" className={`flex-none ${className}`}>
      <defs>
        <linearGradient id={gradient} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#6366f1" />
          <stop offset="1" stopColor="#7c3aed" />
        </linearGradient>
      </defs>
      <rect width="40" height="40" rx="11" fill={`url(#${gradient})`} />
      <path d="M11 15.5h18l-1.6 13.2a2 2 0 0 1-2 1.8H14.6a2 2 0 0 1-2-1.8z" fill="#fff" />
      <path d="M15.5 15.5v-2a4.5 4.5 0 0 1 9 0v2" fill="none" stroke="#fff" strokeWidth="2.2" strokeLinecap="round" />
      <path d="M15.5 22.5c0 2.8 2 4.6 4.5 4.6s4.5-1.8 4.5-4.6" fill="none" stroke="#6366f1" strokeWidth="2.2" strokeLinecap="round" />
      <circle cx="30.5" cy="9.5" r="2.6" fill="#fde68a" />
    </svg>
  );
}

/**
 * The logo with the platform's name. `tone="light"` on dark backgrounds.
 * An uploaded logo replaces mark and name together (it usually holds both).
 */
export default function BrandLogo({ size = 36, tone = 'dark', showName = true, className = '' }: { size?: number; tone?: 'dark' | 'light'; showName?: boolean; className?: string }) {
  const brand = useBrand();

  if (brand.logo_url) {
    return <img src={brand.logo_url} alt={brand.name} style={{ height: size }} className={`w-auto ${className}`} />;
  }

  return (
    <span className={`inline-flex items-center gap-2.5 ${className}`}>
      <BrandMark size={size} />
      {showName && (
        <span className={`text-lg font-bold tracking-tight ${tone === 'light' ? 'text-white' : 'text-slate-900'}`}>{brand.name}</span>
      )}
    </span>
  );
}
