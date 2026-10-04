import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { storefrontFetch } from '@/Storefront/api';
import { useT } from '@/Storefront/i18n';
import type { Shell } from '@/Storefront/types';

type Suggestion = { type: 'product' | 'category'; slug: string; name: string };

/** Search field with debounced suggestions (products and categories). */
export default function SearchBox({ shell, initial = '' }: { shell: Shell; initial?: string }) {
  const t = useT();
  const [q, setQ] = useState(initial);
  const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
  const timer = useRef<number | undefined>(undefined);

  useEffect(() => {
    window.clearTimeout(timer.current);
    if (q.trim().length < 2) {
      setSuggestions([]);
      return;
    }
    timer.current = window.setTimeout(() => {
      storefrontFetch<{ data: Suggestion[] }>(shell, '/storefront/search/suggest', { query: { q: q.trim() } })
        .then((res) => setSuggestions(res.data))
        .catch(() => setSuggestions([]));
    }, 250);

    return () => window.clearTimeout(timer.current);
  }, [q, shell]);

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (q.trim() !== '') router.get(`${shell.base_path}/search`, { q: q.trim() });
  }

  return (
    <form onSubmit={submit} className="relative w-full max-w-sm" role="search">
      <input
        type="search"
        value={q}
        onChange={(e) => setQ(e.target.value)}
        placeholder={t('Search products')}
        aria-label={t('Search products')}
        className="w-full rounded-sf border border-sf-border bg-sf-bg px-3 py-2 text-sm"
      />
      {suggestions.length > 0 && (
        <ul className="absolute z-20 mt-1 w-full overflow-hidden rounded-sf border border-sf-border bg-sf-bg shadow-lg">
          {suggestions.map((s) => (
            <li key={`${s.type}:${s.slug}`}>
              <a
                href={`${shell.base_path}/${s.type === 'product' ? 'products' : 'categories'}/${s.slug}`}
                className="flex justify-between px-3 py-2 text-sm hover:bg-sf-surface"
              >
                <span>{s.name}</span>
                <span className="text-xs text-sf-muted">{s.type === 'category' ? t('Category') : ''}</span>
              </a>
            </li>
          ))}
        </ul>
      )}
    </form>
  );
}
