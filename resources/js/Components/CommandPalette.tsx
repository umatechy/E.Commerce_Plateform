import { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import Icon, { type IconName } from '@/Components/ui/Icon';
import type { ResolvedGroup } from '@/lib/adminNav';

/**
 * UI-2 — "Go to…" search over the admin (Ctrl+K or ⌘K): every page the
 * menu offers this user, plus a few quick actions. It only moves between
 * pages the menu already shows; each page's API still decides.
 */
export type PaletteEntry = { href: string; label: string; hint: string; icon: IconName; group: string };

export function paletteEntries(groups: ResolvedGroup[], icons: Record<string, IconName>, extra: PaletteEntry[]): PaletteEntry[] {
  const pages = groups.flatMap((g) =>
    g.items.filter((i) => i.state === 'open').map((i) => ({ href: i.href, label: i.label, hint: i.description, icon: icons[i.href] ?? 'arrowRight', group: g.label })),
  );

  return [...extra, ...pages];
}

function matches(entry: PaletteEntry, query: string): boolean {
  const q = query.trim().toLowerCase();
  if (q === '') return true;
  const hay = `${entry.label} ${entry.hint} ${entry.group}`.toLowerCase();

  return q.split(/\s+/).every((word) => hay.includes(word));
}

export default function CommandPalette({ entries, open, onClose }: { entries: PaletteEntry[]; open: boolean; onClose: () => void }) {
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);
  const input = useRef<HTMLInputElement>(null);
  const list = useMemo(() => entries.filter((e) => matches(e, query)).slice(0, 12), [entries, query]);

  useEffect(() => {
    if (open) {
      setQuery('');
      setActive(0);
      window.setTimeout(() => input.current?.focus(), 0);
    }
  }, [open]);

  if (!open) return null;

  function go(entry: PaletteEntry | undefined) {
    if (!entry) return;
    onClose();
    router.visit(entry.href);
  }

  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center bg-slate-900/40 px-4 pt-[12vh] backdrop-blur-[2px]" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div role="dialog" aria-modal="true" aria-label="Go to a page" className="w-full max-w-xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
        <div className="flex items-center gap-3 border-b border-slate-200 px-4">
          <Icon name="arrowRight" size={18} className="text-slate-400" />
          <input
            ref={input}
            value={query}
            onChange={(e) => { setQuery(e.target.value); setActive(0); }}
            onKeyDown={(e) => {
              if (e.key === 'Escape') onClose();
              if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(a + 1, list.length - 1)); }
              if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)); }
              if (e.key === 'Enter') { e.preventDefault(); go(list[active]); }
            }}
            placeholder="Go to… (orders, add product, theme)"
            aria-label="Search pages"
            aria-controls="palette-results"
            aria-activedescendant={list[active] ? `palette-${active}` : undefined}
            className="h-14 flex-1 bg-transparent text-base text-slate-900 placeholder:text-slate-400 focus:outline-none"
          />
          <kbd className="hidden rounded-md border border-slate-200 px-1.5 py-0.5 text-[11px] text-slate-500 sm:inline">Esc</kbd>
        </div>
        <ul id="palette-results" role="listbox" aria-label="Pages" className="max-h-[50vh] overflow-y-auto p-2">
          {list.length === 0 && <li className="px-3 py-6 text-center text-sm text-slate-500">Nothing matches “{query}”.</li>}
          {list.map((entry, i) => (
            <li
              key={`${entry.group}-${entry.href}`}
              id={`palette-${i}`}
              role="option"
              aria-selected={i === active}
              onMouseEnter={() => setActive(i)}
              onClick={() => go(entry)}
              className={`flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 ${i === active ? 'bg-brand-50' : ''}`}
            >
              <span className={`flex h-9 w-9 flex-none items-center justify-center rounded-lg ${i === active ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'}`}>
                <Icon name={entry.icon} size={18} />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block text-sm font-medium text-slate-900">{entry.label}</span>
                <span className="block truncate text-xs text-slate-500">{entry.hint}</span>
              </span>
              <span className="hidden text-[11px] uppercase tracking-wide text-slate-400 sm:inline">{entry.group}</span>
            </li>
          ))}
        </ul>
        <p className="border-t border-slate-100 px-4 py-2 text-[11px] text-slate-500">↑ ↓ to move · Enter to open · Esc to close</p>
      </div>
    </div>
  );
}
