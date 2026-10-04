import { useState } from 'react';
import { useT } from '@/Storefront/i18n';

/**
 * Phase B41 (Module 07 §47–49): the filters a category exposes — choices
 * with how many products have each (counted with the other filters), colour
 * swatches, yes/no, and number ranges. The state lives in the address as
 * attr[key]=slug,slug / attr[key]=min-max / attr[key]=1; the server checks it.
 */
export type FilterFacet = {
  key: string;
  name: string;
  type: string;
  unit: string | null;
  options?: { slug: string; value: string; color_code: string | null; count: number; selected: boolean }[];
  yes?: number;
  range?: { min: number | null; max: number | null };
  selected?: boolean | { min: number | null; max: number | null } | null;
};

export default function AttributeFilters({ facets, onChange }: { facets: FilterFacet[]; onChange: (key: string, value: string | undefined) => void }) {
  const t = useT();
  if (facets.length === 0) return null;

  return (
    <div className="space-y-6">
      {facets.map((facet) => (
        <fieldset key={facet.key}>
          <legend className="mb-2 font-semibold">{facet.name}</legend>
          {facet.options && <Choices facet={facet} onChange={onChange} />}
          {facet.type === 'boolean' && (
            <label className="flex items-center gap-2">
              <input type="checkbox" checked={facet.selected === true} onChange={(e) => onChange(facet.key, e.target.checked ? '1' : undefined)} />
              {t('Yes')} <span className="text-sf-muted">({facet.yes ?? 0})</span>
            </label>
          )}
          {facet.range && <Range facet={facet} onChange={onChange} />}
        </fieldset>
      ))}
    </div>
  );
}

function Choices({ facet, onChange }: { facet: FilterFacet; onChange: (key: string, value: string | undefined) => void }) {
  const chosen = (facet.options ?? []).filter((o) => o.selected).map((o) => o.slug);
  const toggle = (slug: string, on: boolean) => {
    const next = on ? [...chosen, slug] : chosen.filter((s) => s !== slug);
    onChange(facet.key, next.length === 0 ? undefined : next.join(','));
  };

  if (facet.type === 'color') {
    return (
      <div className="flex flex-wrap gap-2">
        {(facet.options ?? []).map((o) => (
          <button
            key={o.slug}
            type="button"
            aria-pressed={o.selected}
            onClick={() => toggle(o.slug, !o.selected)}
            className={`flex items-center gap-1 rounded-sf border px-2 py-1 ${o.selected ? 'border-sf-accent ring-2 ring-sf-accent' : 'border-sf-border'}`}
          >
            <span aria-hidden className="inline-block h-4 w-4 rounded-full border border-sf-border" style={{ backgroundColor: o.color_code ?? 'transparent' }} />
            {o.value} <span className="text-sf-muted">({o.count})</span>
          </button>
        ))}
      </div>
    );
  }

  return (
    <ul className="space-y-1">
      {(facet.options ?? []).map((o) => (
        <li key={o.slug}>
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={o.selected} onChange={(e) => toggle(o.slug, e.target.checked)} />
            {o.value} <span className="text-sf-muted">({o.count})</span>
          </label>
        </li>
      ))}
    </ul>
  );
}

function Range({ facet, onChange }: { facet: FilterFacet; onChange: (key: string, value: string | undefined) => void }) {
  const t = useT();
  const selected = facet.selected && typeof facet.selected === 'object' ? facet.selected : null;
  const [min, setMin] = useState(selected?.min != null ? String(selected.min) : '');
  const [max, setMax] = useState(selected?.max != null ? String(selected.max) : '');
  const unit = facet.unit ? ` (${facet.unit})` : '';
  const clean = (v: string) => (/^\d+(\.\d+)?$/.test(v.trim()) ? v.trim() : '');

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        const lo = clean(min);
        const hi = clean(max);
        onChange(facet.key, lo === '' && hi === '' ? undefined : `${lo}-${hi}`);
      }}
    >
      <div className="flex items-center gap-2">
        <input value={min} onChange={(e) => setMin(e.target.value)} inputMode="decimal" placeholder={facet.range?.min != null ? String(facet.range.min) : t('Min')} aria-label={`${facet.name}${unit}: ${t('Min')}`} className="w-20 rounded-sf border border-sf-border px-2 py-1" />
        <span>–</span>
        <input value={max} onChange={(e) => setMax(e.target.value)} inputMode="decimal" placeholder={facet.range?.max != null ? String(facet.range.max) : t('Max')} aria-label={`${facet.name}${unit}: ${t('Max')}`} className="w-20 rounded-sf border border-sf-border px-2 py-1" />
        {facet.unit && <span className="text-sf-muted">{facet.unit}</span>}
      </div>
      <button type="submit" className="mt-2 rounded-sf border border-sf-border px-3 py-1">{t('Apply')}</button>
    </form>
  );
}
