import { useT } from '@/Storefront/i18n';

/**
 * Owner decision 15 (Module 05 §14, §27): a rating as five stars — filled to
 * the average (e.g. 4.5 = four and a half) — with the number for readers.
 * The stars are decoration; the label says it in words.
 */
export default function Stars({ average, count, size = 'sm', showCount = true }: { average: number; count?: number; size?: 'sm' | 'md'; showCount?: boolean }) {
  const t = useT();
  const px = size === 'md' ? 18 : 14;
  const label = t('Rated {rating} out of 5', { rating: average.toFixed(1) });

  return (
    <span className="inline-flex items-center gap-1 text-sm text-sf-muted">
      <span role="img" aria-label={label} className="inline-flex">
        {[1, 2, 3, 4, 5].map((n) => {
          const fill = Math.max(0, Math.min(1, average - (n - 1)));

          return (
            <svg key={n} width={px} height={px} viewBox="0 0 20 20" aria-hidden="true">
              <defs>
                <linearGradient id={`sf-star-${n}-${Math.round(fill * 100)}`}>
                  <stop offset={`${fill * 100}%`} stopColor="currentColor" />
                  <stop offset={`${fill * 100}%`} stopColor="transparent" />
                </linearGradient>
              </defs>
              <path
                d="M10 1.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L10 14.9l-5.2 2.7 1-5.8L1.5 7.7l5.9-.9z"
                fill={`url(#sf-star-${n}-${Math.round(fill * 100)})`}
                stroke="currentColor"
                strokeWidth="1"
                className="text-sf-warning"
              />
            </svg>
          );
        })}
      </span>
      {showCount && count !== undefined && <span>({count})</span>}
    </span>
  );
}
