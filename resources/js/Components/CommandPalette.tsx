import { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import Icon, { type IconName } from '@/Components/ui/Icon';
import { Spinner } from '@/Components/ui/Button';
import type { ResolvedGroup } from '@/lib/adminNav';
import { adminFetch } from '@/lib/adminApi';
import { readPage } from '@/lib/useApi';
import { money } from '@/lib/money';
import { humanize } from '@/Components/ui/Badge';
import { orderCustomer, type Order } from '@/lib/orders';
import type { Product } from '@/lib/catalog';
import type { CustomerRow } from '@/lib/customers';

/**
 * UI-2 — "Go to…" search over the admin (Ctrl+K or ⌘K): every page the
 * menu offers this user, plus a few quick actions.
 *
 * UI-14: it also finds records. From two typed characters it asks the
 * store's own lists (orders, products, customers — only those the user may
 * open) for the first matches, so "ORD-26", "shawl" or a phone number opens
 * the record directly. Each list's API still decides what may be seen.
 */
export type PaletteEntry = { href: string; label: string; hint: string; icon: IconName; group: string };
export type PaletteSearch = { orders: boolean; products: boolean; customers: boolean };

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

const RECORDS_PER_LIST = 5;
const GROUP_ORDER = ['Orders', 'Products', 'Customers'];

/**
 * The first matching records of each list the user may open, each list shown
 * as soon as it answers (`onList`). A list that fails is left out quietly.
 */
async function findRecords(query: string, search: PaletteSearch, signal: AbortSignal, onList: (group: string, found: PaletteEntry[]) => void): Promise<void> {
  const ask = async <T,>(path: string, toEntry: (row: T) => PaletteEntry): Promise<PaletteEntry[]> => {
    try {
      const body = await adminFetch(path, { query: { search: query, page: 1 } });
      const found = readPage<T>(body).rows.slice(0, RECORDS_PER_LIST).map(toEntry);
      if (!signal.aborted) onList(found[0]?.group ?? path, found);

      return found;
    } catch {
      return [];
    }
  };
  await Promise.all([
    search.orders
      ? ask<Order>('/orders', (o) => ({ href: `/orders/${o.id}`, label: o.order_number, hint: `${orderCustomer(o)} · ${humanize(o.status)} · ${money(o.grand_total_minor, o.currency)}`, icon: 'cart', group: 'Orders' }))
      : [],
    search.products
      ? ask<Product>('/products', (p) => ({
          href: `/products/${p.id}`,
          label: p.name,
          hint: [p.sku, p.effective_price_minor !== null && p.currency ? money(p.effective_price_minor, p.currency) : null, humanize(p.status)].filter(Boolean).join(' · '),
          icon: 'box',
          group: 'Products',
        }))
      : [],
    search.customers
      ? ask<CustomerRow>('/customers', (c) => ({ href: `/customers/${c.id}`, label: c.name, hint: [c.email, c.phone].filter(Boolean).join(' · '), icon: 'user', group: 'Customers' }))
      : [],
  ]);
}

export default function CommandPalette({ entries, open, onClose, search = { orders: false, products: false, customers: false } }: { entries: PaletteEntry[]; open: boolean; onClose: () => void; search?: PaletteSearch }) {
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);
  const [records, setRecords] = useState<PaletteEntry[]>([]);
  const [searching, setSearching] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  const canSearch = search.orders || search.products || search.customers;
  const term = query.trim();

  useEffect(() => {
    if (open) {
      setQuery('');
      setActive(0);
      setRecords([]);
      window.setTimeout(() => input.current?.focus(), 0);
    }
  }, [open]);

  // Records: after a short pause in typing, and only the answer to the latest text.
  useEffect(() => {
    if (!open || !canSearch || term.length < 2) {
      setRecords([]);
      setSearching(false);

      return;
    }
    const controller = new AbortController();
    setSearching(true);
    const timer = window.setTimeout(() => {
      setRecords([]);
      findRecords(term, search, controller.signal, (group, found) => {
        // Kept in a fixed order (orders, products, customers) whatever answers first.
        setRecords((current) => [...current.filter((entry) => entry.group !== group), ...found].sort((a, b) => GROUP_ORDER.indexOf(a.group) - GROUP_ORDER.indexOf(b.group)));
      }).then(() => {
        if (!controller.signal.aborted) setSearching(false);
      });
    }, 250);

    return () => {
      controller.abort();
      window.clearTimeout(timer);
    };
    // `search` is rebuilt by the layout on each render; its three flags are what matter.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, term, canSearch, search.orders, search.products, search.customers]);

  const pages = useMemo(() => entries.filter((e) => matches(e, query)).slice(0, records.length > 0 ? 6 : 12), [entries, query, records.length]);
  const list = useMemo(() => [...pages, ...records], [pages, records]);

  if (!open) return null;

  function go(entry: PaletteEntry | undefined) {
    if (!entry) return;
    onClose();
    router.visit(entry.href);
  }

  // Group headings in the order the entries come.
  let lastGroup = '';

  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center bg-slate-950/40 px-4 pt-[12vh] backdrop-blur-[2px] motion-safe:animate-fade-in" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div role="dialog" aria-modal="true" aria-label="Go to a page" className="w-full max-w-xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl motion-safe:animate-scale-in">
        <div className="flex items-center gap-3 border-b border-slate-200 px-4">
          {searching ? <Spinner className="text-brand-600" /> : <Icon name="search" size={18} className="text-slate-400" />}
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
            placeholder={canSearch ? 'Search pages, orders, products, customers…' : 'Go to… (orders, add product, theme)'}
            aria-label={canSearch ? 'Search pages and records' : 'Search pages'}
            aria-controls="palette-results"
            aria-activedescendant={list[active] ? `palette-${active}` : undefined}
            className="h-14 flex-1 bg-transparent text-base text-slate-900 placeholder:text-slate-400 focus:outline-none"
          />
          <kbd className="hidden rounded-md border border-slate-200 px-1.5 py-0.5 text-[11px] text-slate-500 sm:inline">Esc</kbd>
        </div>
        <ul id="palette-results" role="listbox" aria-label="Results" className="max-h-[55vh] overflow-y-auto p-2">
          {list.length === 0 && !searching && <li className="px-3 py-6 text-center text-sm text-slate-500">Nothing matches “{query}”.</li>}
          {list.length === 0 && searching && <li className="px-3 py-6 text-center text-sm text-slate-500">Searching your store…</li>}
          {list.map((entry, i) => {
            const heading = entry.group !== lastGroup && ['Orders', 'Products', 'Customers'].includes(entry.group);
            lastGroup = entry.group;

            return (
              <li key={`${entry.group}-${entry.href}`} role="presentation">
                {heading && <p className="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{entry.group}</p>}
                <div
                  id={`palette-${i}`}
                  role="option"
                  aria-selected={i === active}
                  onMouseEnter={() => setActive(i)}
                  onClick={() => go(entry)}
                  className={`flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 transition-colors motion-reduce:transition-none ${i === active ? 'bg-brand-50' : ''}`}
                >
                  <span className={`flex h-9 w-9 flex-none items-center justify-center rounded-lg ${i === active ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'}`}>
                    <Icon name={entry.icon} size={18} />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className={`block truncate text-sm font-medium text-slate-900 ${entry.group === 'Orders' ? 'font-mono' : ''}`}>{entry.label}</span>
                    <span className="block truncate text-xs text-slate-500">{entry.hint}</span>
                  </span>
                  {!heading && !['Orders', 'Products', 'Customers'].includes(entry.group) && <span className="hidden text-[11px] uppercase tracking-wide text-slate-400 sm:inline">{entry.group}</span>}
                  {i === active && <Icon name="arrowRight" size={16} className="text-brand-600" />}
                </div>
              </li>
            );
          })}
        </ul>
        <p className="border-t border-slate-100 px-4 py-2 text-[11px] text-slate-500">↑ ↓ to move · Enter to open · Esc to close{canSearch ? ' · Type 2+ letters to find orders, products and customers' : ''}</p>
      </div>
    </div>
  );
}
