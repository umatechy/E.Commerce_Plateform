import Button from '@/Components/ui/Button';
import { CheckboxField, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { humanize } from '@/Components/ui/Badge';
import ImageUploadField from '@/Components/ui/ImageUploadField';
import { BADGE_ICONS, SECTION_FIELDS, SECTION_ITEMS, type Section, type SectionItem } from '@/lib/theme';
import { useApi } from '@/lib/useApi';
import type { Collection } from '@/lib/catalog';

/**
 * One home page section (Module 17 §25): shown or not, its texts, and for
 * testimonials, questions and trust badges a list of entries. Move buttons
 * only when the package includes reordering (`homepage.reorder`); the server
 * keeps the fixed order otherwise.
 */
export default function SectionEditor({
  section,
  index,
  count,
  canEdit,
  canReorder,
  languages = [],
  onChange,
  onMove,
  onRemove,
}: {
  section: Section;
  index: number;
  count: number;
  canEdit: boolean;
  canReorder: boolean;
  onChange: (patch: Partial<Section>) => void;
  onMove: (by: number) => void;
  onRemove: () => void;
  /** Phase B38: the store's other storefront languages. */
  languages?: { code: string; name: string; native: string; dir: string }[];
}) {
  const name = humanize(section.type);
  const fields = SECTION_FIELDS[section.type] ?? [];
  const items = SECTION_ITEMS[section.type];
  const list = (Array.isArray(section.config.items) ? section.config.items : []) as SectionItem[];
  const setConfig = (key: string, value: string | number | SectionItem[]) => onChange({ config: { ...section.config, [key]: value } });
  // Phase B39: the collections a "featured products" section can show.
  const wantsCollections = fields.some((field) => field.kind === 'collection') && section.config.source === 'collection';
  const collections = useApi<{ data: Collection[] }>(wantsCollections ? '/collections' : null);
  const setItem = (i: number, key: string, value: string) => setConfig('items', list.map((item, n) => (n === i ? { ...item, [key]: value } : item)));

  return (
    <li className="rounded-md border border-slate-200 p-3" data-section={section.type}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="font-medium text-slate-900">{name}</span>
        {canEdit && (
          <span className="flex flex-wrap gap-1">
            {canReorder && (
              <>
                <Button size="sm" variant="ghost" disabled={index === 0} onClick={() => onMove(-1)} aria-label={`Move ${name} up`}>
                  ↑
                </Button>
                <Button size="sm" variant="ghost" disabled={index === count - 1} onClick={() => onMove(1)} aria-label={`Move ${name} down`}>
                  ↓
                </Button>
              </>
            )}
            {!['header', 'footer'].includes(section.type) && (
              <Button size="sm" variant="ghost" onClick={onRemove}>
                Remove<span className="sr-only"> {name}</span>
              </Button>
            )}
          </span>
        )}
      </div>
      <div className="mt-2 space-y-3">
        <CheckboxField label="Shown on the home page" checked={section.is_visible} disabled={!canEdit} onChange={(is_visible) => onChange({ is_visible })} />
        {fields.length > 0 && (
          <div className="grid gap-3 sm:grid-cols-2">
            {fields.map((field) =>
              field.kind === 'source' ? (
                <SelectField
                  key={field.key}
                  label={field.label}
                  disabled={!canEdit}
                  value={String(section.config.source ?? 'newest')}
                  onChange={(value) => setConfig('source', value)}
                  options={[{ value: 'newest', label: 'Newest products' }, { value: 'featured', label: 'Products marked as featured' }, { value: 'collection', label: 'A collection' }]}
                />
              ) : field.kind === 'collection' ? (
                section.config.source === 'collection' ? (
                  <SelectField
                    key={field.key}
                    label={field.label}
                    disabled={!canEdit}
                    value={String(section.config.collection ?? '')}
                    onChange={(value) => setConfig('collection', value)}
                    placeholder={collections.loading ? 'Loading…' : 'Choose a collection'}
                    options={(collections.data?.data ?? []).map((c) => ({ value: c.slug, label: c.is_live ? c.name : `${c.name} (not shown now)` }))}
                    hint="Shown in the collection's own order. A collection that is not shown now leaves the section empty."
                  />
                ) : null
              ) : field.kind === 'image' ? (
                <div key={field.key} className="sm:col-span-2">
                  <ImageUploadField label={field.label} purpose="banner" disabled={!canEdit} value={String(section.config[field.key] ?? '')} onChange={(value) => setConfig(field.key, value)} hint="A wide JPG or PNG, at least 200 pixels; about 1600 × 800 looks good. Up to 5 MB." />
                </div>
              ) : field.kind === 'textarea' ? (
                <div key={field.key} className="sm:col-span-2">
                  <TextAreaField label={field.label} optional rows={4} maxLength={2000} disabled={!canEdit} value={String(section.config[field.key] ?? '')} onChange={(value) => setConfig(field.key, value)} />
                </div>
              ) : (
                <TextField
                  key={field.key}
                  label={field.label}
                  optional
                  disabled={!canEdit}
                  type={field.kind === 'url' ? 'url' : field.kind === 'number' ? 'number' : 'text'}
                  value={String(section.config[field.key] ?? '')}
                  onChange={(value) => setConfig(field.key, field.kind === 'number' && value !== '' ? Number(value) : value)}
                />
              ),
            )}
          </div>
        )}
        {items && (
          <fieldset className="space-y-3">
            <legend className="text-sm font-medium text-slate-700">
              {humanize(items.noun)}s ({list.length} of at most {items.max})
            </legend>
            {list.map((item, i) => (
              <div key={i} className="grid gap-3 rounded-md bg-slate-50 p-3 sm:grid-cols-2">
                {items.fields.map((field) =>
                  field.kind === 'icon' ? (
                    <SelectField key={field.key} label={field.label} value={item[field.key] ?? ''} disabled={!canEdit} placeholder="Choose an icon" onChange={(value) => setItem(i, field.key, value)} options={BADGE_ICONS} />
                  ) : field.kind === 'textarea' ? (
                    <div key={field.key} className="sm:col-span-2">
                      <TextAreaField label={field.label} optional={!field.required} rows={3} maxLength={1000} disabled={!canEdit} value={item[field.key] ?? ''} onChange={(value) => setItem(i, field.key, value)} />
                    </div>
                  ) : (
                    <TextField key={field.key} label={field.label} optional={!field.required} maxLength={200} disabled={!canEdit} value={item[field.key] ?? ''} onChange={(value) => setItem(i, field.key, value)} />
                  ),
                )}
                {canEdit && (
                  <div className="sm:col-span-2">
                    <Button size="sm" variant="ghost" onClick={() => setConfig('items', list.filter((_, n) => n !== i))}>
                      Remove this {items.noun}
                    </Button>
                  </div>
                )}
              </div>
            ))}
            {canEdit && list.length < items.max && (
              <Button size="sm" onClick={() => setConfig('items', [...list, {}])}>
                Add a {items.noun}
              </Button>
            )}
          </fieldset>
        )}
        {languages.map((language) => (
          <SectionTranslation key={language.code} section={section} language={language} canEdit={canEdit} onChange={onChange} />
        ))}
      </div>
    </li>
  );
}

/**
 * Phase B38 (LOC-002): the section's texts in another storefront language.
 * Empty fields show the original; numbers, links, images and icons are
 * always the original's.
 */
function SectionTranslation({ section, language, canEdit, onChange }: { section: Section; language: { code: string; name: string; native: string; dir: string }; canEdit: boolean; onChange: (patch: Partial<Section>) => void }) {
  const texts = (SECTION_FIELDS[section.type] ?? []).filter((field) => field.kind === undefined || field.kind === 'textarea');
  const items = SECTION_ITEMS[section.type];
  if (texts.length === 0 && !items) return null;
  const all = (section.config.translations ?? {}) as Record<string, Record<string, string | SectionItem[]>>;
  const mine = all[language.code] ?? {};
  const original = (Array.isArray(section.config.items) ? section.config.items : []) as SectionItem[];
  const translatedItems = (Array.isArray(mine.items) ? mine.items : []) as SectionItem[];
  const set = (next: Record<string, string | SectionItem[]>) => onChange({ config: { ...section.config, translations: { ...all, [language.code]: next } } });
  const filled = Object.values(mine).some((value) => (Array.isArray(value) ? value.some((item) => Object.values(item).some(Boolean)) : value !== ''));

  return (
    <details className="rounded-md border border-dashed border-slate-300 p-3" open={filled}>
      <summary className="cursor-pointer text-sm font-medium text-slate-700">
        {language.name} <span lang={language.code}>({language.native})</span>
      </summary>
      <div className="mt-3 grid gap-3 sm:grid-cols-2" dir={language.dir} lang={language.code}>
        {texts.map((field) =>
          field.kind === 'textarea' ? (
            <div key={field.key} className="sm:col-span-2">
              <TextAreaField label={`${field.label} (${language.name})`} optional rows={3} maxLength={2000} disabled={!canEdit} value={String(mine[field.key] ?? '')} onChange={(value) => set({ ...mine, [field.key]: value })} hint={section.config[field.key] ? `Original: ${String(section.config[field.key]).slice(0, 100)}` : undefined} />
            </div>
          ) : (
            <TextField key={field.key} label={`${field.label} (${language.name})`} optional disabled={!canEdit} value={String(mine[field.key] ?? '')} onChange={(value) => set({ ...mine, [field.key]: value })} hint={section.config[field.key] ? `Original: ${String(section.config[field.key]).slice(0, 100)}` : undefined} />
          ),
        )}
        {items &&
          original.map((item, i) => (
            <div key={i} className="grid gap-3 rounded-md bg-slate-50 p-3 sm:col-span-2 sm:grid-cols-2">
              {items.fields
                .filter((field) => field.kind !== 'icon')
                .map((field) => (
                  <TextField
                    key={field.key}
                    label={`${field.label} ${i + 1} (${language.name})`}
                    optional
                    disabled={!canEdit}
                    value={translatedItems[i]?.[field.key] ?? ''}
                    hint={item[field.key] ? `Original: ${item[field.key].slice(0, 80)}` : undefined}
                    onChange={(value) => {
                      const next = original.map((_, n) => ({ ...(translatedItems[n] ?? {}) }));
                      next[i] = { ...next[i], [field.key]: value };
                      set({ ...mine, items: next });
                    }}
                  />
                ))}
            </div>
          ))}
      </div>
    </details>
  );
}
