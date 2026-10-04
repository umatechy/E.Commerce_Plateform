import Button from '@/Components/ui/Button';
import { CheckboxField, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { humanize } from '@/Components/ui/Badge';
import { BADGE_ICONS, SECTION_FIELDS, SECTION_ITEMS, type Section, type SectionItem } from '@/lib/theme';

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
}) {
  const name = humanize(section.type);
  const fields = SECTION_FIELDS[section.type] ?? [];
  const items = SECTION_ITEMS[section.type];
  const list = (Array.isArray(section.config.items) ? section.config.items : []) as SectionItem[];
  const setConfig = (key: string, value: string | number | SectionItem[]) => onChange({ config: { ...section.config, [key]: value } });
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
              field.kind === 'textarea' ? (
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
      </div>
    </li>
  );
}
