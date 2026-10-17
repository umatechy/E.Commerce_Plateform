import { FormEvent, useState } from 'react';
import Icon from './Icon';
import { FOCUS_RING } from './Button';
import { useAuth } from '@/lib/access';
import { useAdminT } from '@/lib/adminI18n';
import { savePreference } from '@/lib/preferences';

/**
 * UI-21 — saved views: a list's filters kept under a name ("COD to confirm",
 * "Drafts") and applied with one click. Kept with the account (preferences
 * saved_views), so they are on every device; at most ten per list.
 */
export type ViewList = 'orders' | 'products' | 'customers';
type View = { name: string; query: string };

const MAX_VIEWS = 10;

/** The filters that differ from their defaults, as a query string (the page number is left out). */
export function viewQuery(filters: Record<string, string>, defaults: Record<string, string>): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (key !== 'page' && value !== '' && value !== defaults[key]) params.set(key, value);
  }
  params.sort();

  return params.toString();
}

/** A saved query back as filters over the defaults (page 1). */
export function viewFilters(query: string, defaults: Record<string, string>): Record<string, string> {
  return { ...defaults, ...Object.fromEntries(new URLSearchParams(query)), page: '1' };
}

export default function SavedViews({ list, query, onApply }: { list: ViewList; query: string; onApply: (query: string) => void }) {
  const t = useAdminT();
  const auth = useAuth();
  const [views, setViews] = useState<View[]>(() => auth.user?.preferences?.saved_views?.[list] ?? []);
  const [naming, setNaming] = useState(false);
  const [name, setName] = useState('');
  const current = views.find((view) => view.query === query);

  function store(next: View[]) {
    setViews(next);
    savePreference({ saved_views: { [list]: next } });
  }

  function save(event: FormEvent) {
    event.preventDefault();
    const trimmed = name.trim().slice(0, 40);
    if (trimmed === '') return;
    store([...views.filter((view) => view.name !== trimmed), { name: trimmed, query }].slice(-MAX_VIEWS));
    setNaming(false);
    setName('');
  }

  if (views.length === 0 && query === '') return null;

  return (
    <div className="mb-3 flex flex-wrap items-center gap-2 text-sm">
      <span className="flex items-center gap-1.5 text-xs font-medium text-slate-500"><Icon name="layers" size={14} />{t('Saved views')}</span>
      {views.map((view) => {
        const active = view.query === query;

        return (
          <span key={view.name} className={`inline-flex items-center rounded-full ring-1 ring-inset transition-colors motion-reduce:transition-none ${active ? 'bg-brand-600 text-white ring-brand-600' : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50'}`}>
            <button type="button" onClick={() => onApply(view.query)} aria-pressed={active} className={`rounded-full py-1 ps-3 pe-1.5 font-medium ${FOCUS_RING}`}>
              {view.name}
            </button>
            <button type="button" onClick={() => store(views.filter((v) => v !== view))} aria-label={t('Remove the view {name}', { name: view.name })} className={`me-1 rounded-full p-0.5 ${active ? 'text-white/70 hover:text-white' : 'text-slate-400 hover:text-slate-700'} ${FOCUS_RING}`}>
              <Icon name="x" size={13} />
            </button>
          </span>
        );
      })}
      {query !== '' && !current && !naming && views.length < MAX_VIEWS && (
        <button type="button" onClick={() => setNaming(true)} className={`inline-flex items-center gap-1 rounded-full border border-dashed border-brand-300 px-3 py-1 font-medium text-brand-700 hover:bg-brand-50 ${FOCUS_RING}`}>
          <Icon name="plus" size={14} />{t('Save this view')}
        </button>
      )}
      {naming && (
        <form onSubmit={save} className="inline-flex items-center gap-1.5">
          <input
            autoFocus
            aria-label={t('Name of the view')}
            placeholder={t('For example: COD to confirm')}
            value={name}
            maxLength={40}
            onChange={(e) => setName(e.target.value)}
            onKeyDown={(e) => e.key === 'Escape' && setNaming(false)}
            className="h-8 w-48 rounded-full border border-brand-300 bg-white px-3 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600"
          />
          <button type="submit" disabled={name.trim() === ''} className={`rounded-full bg-brand-600 px-3 py-1 font-semibold text-white disabled:opacity-50 ${FOCUS_RING}`}>{t('Save')}</button>
          <button type="button" onClick={() => setNaming(false)} className={`rounded-full px-2 py-1 text-slate-500 hover:text-slate-800 ${FOCUS_RING}`}>{t('Cancel')}</button>
        </form>
      )}
    </div>
  );
}
