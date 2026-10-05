import { FormEvent, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, SelectField, SwitchField, TextField } from '@/Components/ui/Form';
import Badge from '@/Components/ui/Badge';
import { Card, EmptyPanel } from '@/Components/ui/Page';
import TranslationsPanel from '@/Components/TranslationsPanel';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { BADGE_TONES, type Badge as StoreBadge } from '@/lib/catalog';

/**
 * Phase B43 — Module 06 §36: badges. Automatic ones (new, sale, bestseller,
 * low stock, featured, sold out) are switched on and tuned in Settings; the
 * store's own badges ("Handmade", "Eid special") are defined here and put on
 * products from the product page or the bulk bar. A card shows the badges
 * with the highest priority first, up to the number set in Settings.
 */
type Values = { label: string; tone: string; priority: string; is_active: boolean };

const BLANK: Values = { label: '', tone: 'accent', priority: '50', is_active: true };

const TONE_LABEL: Record<string, string> = { accent: 'Theme accent', success: 'Green (success)', warning: 'Amber (warning)', danger: 'Red (danger)', neutral: 'Dark (neutral)' };
const SWATCH: Record<string, string> = { accent: 'bg-indigo-600', success: 'bg-green-700', warning: 'bg-amber-700', danger: 'bg-red-700', neutral: 'bg-gray-900' };

const AUTOMATIC = [
  ['Sold out', 100, 'when nothing can be bought'],
  ['Sale / % off', 90, 'when the product is on sale'],
  ['Only a few left', 80, 'when stock is at or under your threshold (off by default)'],
  ['New', 70, 'published within the last days you set'],
  ['Bestseller', 60, 'among your top sellers of the last days you set'],
  ['Featured', 40, 'marked as featured'],
] as const;

export default function Badges() {
  const access = useAccess();
  const canManage = access.can('collections.manage');
  const list = useApi<{ data: StoreBadge[] }>('/badges');
  const [editing, setEditing] = useState<StoreBadge | 'new' | null>(null);
  const [translating, setTranslating] = useState<StoreBadge | null>(null);
  const [removing, setRemoving] = useState<StoreBadge | null>(null);
  const { busy, run } = useAction();

  const columns: Column<StoreBadge>[] = [
    {
      key: 'label', header: 'Badge',
      render: (b) => (
        <span className="flex items-center gap-2">
          <span className={`rounded px-2 py-0.5 text-xs font-semibold text-white ${SWATCH[b.tone] ?? SWATCH.accent}`}>{b.label}</span>
          {!b.is_active && <Badge tone="neutral">Hidden</Badge>}
        </span>
      ),
    },
    { key: 'priority', header: 'Priority', align: 'right', render: (b) => b.priority },
    { key: 'products', header: 'Products', align: 'right', render: (b) => b.product_count },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (b) =>
        canManage && (
          <span className="flex justify-end gap-1">
            <Button size="sm" variant="ghost" onClick={() => setEditing(b)}>Edit<span className="sr-only"> {b.label}</span></Button>
            <Button size="sm" variant="ghost" onClick={() => setTranslating(b)}>Translate<span className="sr-only"> {b.label}</span></Button>
            <Button size="sm" variant="ghost" onClick={() => setRemoving(b)}>Delete<span className="sr-only"> {b.label}</span></Button>
          </span>
        ),
    },
  ];

  return (
    <AdminPage
      title="Badges"
      description="Labels on product cards and pages. Your own badges are defined here; automatic ones follow your products and your settings."
      actions={canManage && <Button variant="primary" onClick={() => setEditing('new')}>Add badge</Button>}
    >
      <DataTable
        caption="Your badges"
        columns={columns}
        rows={list.data?.data ?? null}
        rowKey={(b) => b.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={<EmptyPanel title="No badges of your own yet" description="For example “Handmade”, “Eid special” or “Organic”." action={canManage ? <Button variant="primary" onClick={() => setEditing('new')}>Add badge</Button> : undefined} />}
      />

      <div className="mt-6">
        <Card title="Automatic badges" description="Shown when a product qualifies, if switched on. Higher priority shows first; your own badges take their place by their priority.">
          <ul className="divide-y divide-slate-100 text-sm">
            {AUTOMATIC.map(([name, priority, when]) => (
              <li key={name} className="flex flex-wrap justify-between gap-2 py-2">
                <span><span className="font-medium">{name}</span> <span className="text-slate-600">— {when}</span></span>
                <span className="text-slate-600">Priority {priority}</span>
              </li>
            ))}
          </ul>
          <p className="mt-3 text-sm text-slate-700">
            Switch them on or off, set the days and thresholds and how many badges a card shows in{' '}
            <Link href="/settings" className={`rounded text-indigo-700 underline ${FOCUS_RING}`}>Settings</Link>.
          </p>
        </Card>
      </div>

      {editing !== null && <BadgeDialog badge={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); list.reload(); }} />}

      <Dialog open={translating !== null} title={`Translations — ${translating?.label ?? ''}`} onClose={() => setTranslating(null)}>
        {translating && <TranslationsPanel type="badge" id={translating.id} canEdit={canManage} />}
        <div className="mt-4 flex justify-end"><Button onClick={() => setTranslating(null)}>Close</Button></div>
      </Dialog>

      <ConfirmDialog
        open={removing !== null}
        title="Delete this badge?"
        confirmLabel="Delete badge"
        busy={busy === 'delete'}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing &&
          run('delete', () => adminFetch(`/badges/${removing.id}`, { method: 'DELETE' }), { success: 'Badge deleted.' }).then((r) => {
            if (r !== undefined) {
              setRemoving(null);
              list.reload();
            }
          })
        }
      >
        <p><strong>{removing?.label}</strong> is removed from every product that shows it. To keep it for later, hide it instead.</p>
      </ConfirmDialog>
    </AdminPage>
  );
}

function BadgeDialog({ badge, onClose, onSaved }: { badge: StoreBadge | null; onClose: () => void; onSaved: () => void }) {
  const form = useForm<Values>(badge ? { label: badge.label, tone: badge.tone, priority: String(badge.priority), is_active: badge.is_active } : BLANK);
  const { values: v, set } = form;

  async function save(event: FormEvent) {
    event.preventDefault();
    const body = { label: v.label, tone: v.tone, priority: Number(v.priority), is_active: v.is_active };
    const saved = await form.submit(() => adminFetch(badge === null ? '/badges' : `/badges/${badge.id}`, { method: badge === null ? 'POST' : 'PUT', body }), badge === null ? 'Badge created.' : 'Badge saved.');
    if (saved !== undefined) onSaved();
  }

  return (
    <Dialog open title={badge === null ? 'Add badge' : `Edit ${badge.label}`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <TextField label="Label" value={v.label} onChange={(x) => set('label', x)} error={form.errors.label} required maxLength={40} hint="Short, up to 40 characters." data-autofocus />
        <SelectField label="Colour" value={v.tone} onChange={(x) => set('tone', x)} options={BADGE_TONES.map((t) => ({ value: t, label: TONE_LABEL[t] }))} hint="Uses your theme's colours." />
        <TextField label="Priority" inputMode="numeric" value={v.priority} onChange={(x) => set('priority', x)} error={form.errors.priority} hint="1 to 200; higher shows first. Sale is 90, New 70, Featured 40." />
        <SwitchField label="Shown on the storefront" checked={v.is_active} onChange={(x) => set('is_active', x)} />
        <p className="text-sm text-slate-700">
          Preview: <span className={`rounded px-2 py-0.5 text-xs font-semibold text-white ${SWATCH[v.tone] ?? SWATCH.accent}`}>{v.label || 'Label'}</span>
        </p>
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save badge</Button>
        </div>
      </form>
    </Dialog>
  );
}
