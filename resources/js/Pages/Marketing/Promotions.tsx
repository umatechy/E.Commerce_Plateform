import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { CheckboxField, FormError, SelectField, TextField } from '@/Components/ui/Form';
import Badge, { StatusBadge, humanize } from '@/Components/ui/Badge';
import { EmptyPanel, Tabs } from '@/Components/ui/Page';
import ProductPicker from '@/Components/ProductPicker';
import { useAccess } from '@/lib/access';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { fromMinor, money, toMinor } from '@/lib/money';
import { dateTimeOrDash, displayTimezoneName, toStoreLocalInput } from '@/lib/datetime';
import { options } from '@/lib/labels';
import type { Brand, Category } from '@/lib/catalog';
import CurrencyField from '@/Components/CurrencyField';

/**
 * Module 14 "Discounts, Coupons & Promotions" (/api/v1/promotions,
 * /coupons). The server validates every rule (type, value, dates,
 * limits, priority) and applies promotions at checkout; this page only
 * edits them.
 *
 * Start and end times are entered and shown in the store's timezone
 * (Phase B28): the value is sent without an offset and the server reads
 * it as store-local time.
 */
type Promotion = {
  id: string;
  internal_id: number;
  name: string;
  type: string;
  target_scope: string;
  status: string;
  percentage_value: number | null;
  fixed_amount_minor: number | null;
  currency: string | null;
  min_order_value_minor: number | null;
  max_discount_minor: number | null;
  requires_coupon: boolean;
  priority: number;
  usage_limit: number | null;
  used_count: number;
  customer_usage_limit: number | null;
  starts_at: string | null;
  ends_at: string | null;
  target_ids?: number[];
  /** Phase B32: the targets with their names; a target deleted since has no name. */
  targets?: { id: number; name: string | null }[];
};

type Coupon = { id: number; code: string; is_active: boolean; usage_limit: number | null; used_count: number; customer_usage_limit: number | null };

const TYPES = ['percentage', 'fixed_amount', 'free_shipping'] as const;
const SCOPES = ['order', 'product', 'category', 'brand'] as const;
const STATUSES = ['draft', 'active', 'paused', 'disabled', 'archived'] as const;
const SCOPE_LABELS: Record<string, string> = { order: 'The whole order', product: 'Chosen products', category: 'Chosen categories', brand: 'Chosen brands' };

type Values = {
  name: string; type: string; target_scope: string; status: string; percentage_value: string; fixed_amount: string; currency: string;
  min_order_value: string; max_discount: string; requires_coupon: boolean; priority: string; usage_limit: string; customer_usage_limit: string;
  starts_at: string; ends_at: string; target_ids: number[];
};

function valuesFrom(promotion: Promotion | null, currency: string): Values {
  const code = promotion?.currency ?? currency;

  return {
    name: promotion?.name ?? '',
    type: promotion?.type ?? 'percentage',
    target_scope: promotion?.target_scope ?? 'order',
    status: promotion?.status ?? 'draft',
    percentage_value: promotion?.percentage_value?.toString() ?? '',
    fixed_amount: fromMinor(promotion?.fixed_amount_minor, code),
    currency: code,
    min_order_value: fromMinor(promotion?.min_order_value_minor, code),
    max_discount: fromMinor(promotion?.max_discount_minor, code),
    requires_coupon: promotion?.requires_coupon ?? false,
    priority: String(promotion?.priority ?? 0),
    usage_limit: promotion?.usage_limit?.toString() ?? '',
    customer_usage_limit: promotion?.customer_usage_limit?.toString() ?? '',
    starts_at: toStoreLocalInput(promotion?.starts_at),
    ends_at: toStoreLocalInput(promotion?.ends_at),
    target_ids: promotion?.target_ids ?? [],
  };
}

function targetNames(promotion: Promotion | null | undefined): Record<number, string> {
  const names: Record<number, string> = {};
  for (const target of promotion?.targets ?? []) {
    if (target.name !== null) names[target.id] = target.name;
  }

  return names;
}

/** What the promotion applies to, in words: "Rose Attar, Oud (+2 more)". */
function targetSummary(promotion: Promotion): string {
  const label = SCOPE_LABELS[promotion.target_scope] ?? humanize(promotion.target_scope);
  if (promotion.target_scope === 'order' || !promotion.targets || promotion.targets.length === 0) return label;
  const shown = promotion.targets.slice(0, 2).map((target) => target.name ?? `deleted #${target.id}`);
  const more = promotion.targets.length - shown.length;

  return `${shown.join(', ')}${more > 0 ? ` (+${more} more)` : ''}`;
}

function discountText(promotion: Promotion): string {
  if (promotion.type === 'percentage') return `${promotion.percentage_value ?? 0}% off`;
  if (promotion.type === 'fixed_amount') return `${money(promotion.fixed_amount_minor, promotion.currency)} off`;

  return 'Free shipping';
}

function Targets({ scope, ids, known, onChange }: { scope: string; ids: number[]; known: Record<number, string>; onChange: (ids: number[]) => void }) {
  const categories = useApi<{ data: Category[] }>(scope === 'category' ? '/categories' : null);
  const brands = useApi<{ data: Brand[] }>(scope === 'brand' ? '/brands' : null);
  // Names of the products: those saved come with the promotion (its
  // `targets`), those picked in this dialog from the picker. A product
  // deleted since keeps only its number.
  const [picked, setPicked] = useState<Record<number, string>>({});
  const names: Record<number, string> = { ...known, ...picked };

  if (scope === 'order') return null;

  if (scope === 'product') {
    return (
      <fieldset className="space-y-2">
        <legend className="text-sm font-medium text-slate-700">Products this applies to</legend>
        {ids.length > 0 && (
          <ul className="flex flex-wrap gap-2">
            {ids.map((id) => (
              <li key={id} className="flex items-center gap-1 rounded-full bg-slate-100 py-0.5 pl-3 pr-1 text-sm">
                {names[id] ?? `Deleted product (#${id})`}
                <Button size="sm" variant="ghost" onClick={() => onChange(ids.filter((value) => value !== id))} aria-label={`Remove ${names[id] ?? `product ${id}`}`}>✕</Button>
              </li>
            ))}
          </ul>
        )}
        <ProductPicker
          label="Add a product"
          onPick={({ product }) => {
            setPicked((current) => ({ ...current, [product.internal_id]: product.name }));
            if (!ids.includes(product.internal_id)) onChange([...ids, product.internal_id]);
          }}
        />
      </fieldset>
    );
  }

  const source = scope === 'category' ? categories : brands;
  const list = (source.data?.data ?? []) as { id: number; name: string }[];

  return (
    <fieldset>
      <legend className="text-sm font-medium text-slate-700">{scope === 'category' ? 'Categories' : 'Brands'} this applies to</legend>
      {source.error ? (
        <p className="text-sm text-red-700">{source.error}</p>
      ) : source.data === null ? (
        <p className="text-sm text-slate-500">Loading…</p>
      ) : list.length === 0 ? (
        <p className="text-sm text-slate-600">There are none yet. Add them under Catalog first.</p>
      ) : (
        <div className="mt-2 grid gap-2 sm:grid-cols-2">
          {list.map((item) => (
            <CheckboxField key={item.id} label={item.name} checked={ids.includes(item.id)} onChange={(checked) => onChange(checked ? [...ids, item.id] : ids.filter((value) => value !== item.id))} />
          ))}
        </div>
      )}
    </fieldset>
  );
}

function PromotionDialog({ promotion, onClose, onDone }: { promotion: Promotion | null; onClose: () => void; onDone: () => void }) {
  const access = useAccess();
  const form = useForm<Values>(valuesFrom(promotion, access.currency));
  const [local, setLocal] = useState<Record<string, string>>({});
  const [tab, setTab] = useState('discount');
  const { values, set } = form;

  async function save(event: FormEvent) {
    event.preventDefault();
    const code = values.currency.toUpperCase();
    const problems: Record<string, string> = {};
    const amountOf = (text: string, field: string): number | null => {
      if (text.trim() === '') return null;
      const minor = toMinor(text, code);
      if (minor === null) problems[field] = `Enter an amount such as 10.00 (${code}).`;

      return minor;
    };
    const fixed = amountOf(values.fixed_amount, 'fixed_amount_minor');
    const minOrder = amountOf(values.min_order_value, 'min_order_value_minor');
    const maxDiscount = amountOf(values.max_discount, 'max_discount_minor');
    setLocal(problems);
    if (Object.keys(problems).length > 0) return;

    const whole = (text: string) => (text.trim() === '' ? null : Number(text));
    const body: Record<string, unknown> = {
      name: values.name,
      type: values.type,
      target_scope: values.target_scope,
      status: values.status,
      min_order_value_minor: minOrder,
      max_discount_minor: maxDiscount,
      requires_coupon: values.requires_coupon,
      priority: whole(values.priority) ?? 0,
      usage_limit: whole(values.usage_limit),
      customer_usage_limit: whole(values.customer_usage_limit),
      starts_at: values.starts_at === '' ? null : values.starts_at,
      ends_at: values.ends_at === '' ? null : values.ends_at,
      target_ids: values.target_scope === 'order' ? [] : values.target_ids,
    };
    // Only the value that belongs to the chosen type is sent.
    if (values.type === 'percentage') body.percentage_value = whole(values.percentage_value);
    if (values.type === 'fixed_amount') {
      body.fixed_amount_minor = fixed;
      body.currency = code;
    }

    const saved = await form.submit(
      () => adminFetch(promotion === null ? '/promotions' : `/promotions/${promotion.id}`, { method: promotion === null ? 'POST' : 'PUT', body }),
      promotion === null ? 'Promotion created.' : 'Promotion saved.',
    );
    if (saved !== undefined) onDone();
  }

  const errors = { ...form.errors, ...local };

  return (
    <Dialog open wide title={promotion === null ? 'Add promotion' : `Edit ${promotion.name}`} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} noValidate>
        <div className="mb-3">
          <FormError message={form.formError} errors={errors} />
        </div>
        <Tabs label="Promotion sections" active={tab} onChange={setTab} tabs={[{ id: 'discount', label: 'Discount' }, { id: 'applies', label: 'Applies to' }, { id: 'limits', label: 'Limits and dates' }]} />

        <div role="tabpanel" hidden={tab !== 'discount'} className="space-y-4">
          <TextField label="Name" value={values.name} onChange={(v) => set('name', v)} error={errors.name} required maxLength={255} data-autofocus />
          <div className="grid gap-4 sm:grid-cols-2">
            <SelectField label="Type" value={values.type} onChange={(v) => set('type', v)} error={errors.type} options={options(TYPES)} />
            <SelectField label="Status" value={values.status} onChange={(v) => set('status', v)} error={errors.status} options={options(STATUSES)} hint="Only active promotions apply at checkout." />
            {values.type === 'percentage' && <TextField label="Percent off" type="number" min={1} max={100} value={values.percentage_value} onChange={(v) => set('percentage_value', v)} error={errors.percentage_value} required />}
            {values.type === 'fixed_amount' && (
              <>
                <TextField label="Amount off" inputMode="decimal" value={values.fixed_amount} onChange={(v) => set('fixed_amount', v)} error={errors.fixed_amount_minor} required />
                <CurrencyField value={values.currency} onChange={(v) => set('currency', v)} error={errors.currency} />
              </>
            )}
          </div>
          <CheckboxField label="Needs a coupon code" hint="Customers must enter one of this promotion's codes. Add the codes after saving." checked={values.requires_coupon} onChange={(v) => set('requires_coupon', v)} />
        </div>

        <div role="tabpanel" hidden={tab !== 'applies'} className="space-y-4">
          <SelectField label="Applies to" value={values.target_scope} onChange={(v) => { set('target_scope', v); set('target_ids', []); }} error={errors.target_scope} options={options(SCOPES, SCOPE_LABELS)} />
          <Targets scope={values.target_scope} ids={values.target_ids} known={values.target_scope === promotion?.target_scope ? targetNames(promotion) : {}} onChange={(ids) => set('target_ids', ids)} />
          {errors.target_ids && <p role="alert" className="text-xs font-medium text-red-700">{errors.target_ids}</p>}
        </div>

        <div role="tabpanel" hidden={tab !== 'limits'} className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label={`Minimum order value (${values.currency})`} optional inputMode="decimal" value={values.min_order_value} onChange={(v) => set('min_order_value', v)} error={errors.min_order_value_minor} />
            <TextField label={`Largest discount (${values.currency})`} optional inputMode="decimal" value={values.max_discount} onChange={(v) => set('max_discount', v)} error={errors.max_discount_minor} />
            <TextField label="Total uses allowed" optional type="number" min={1} value={values.usage_limit} onChange={(v) => set('usage_limit', v)} error={errors.usage_limit} />
            <TextField label="Uses per customer" optional type="number" min={1} value={values.customer_usage_limit} onChange={(v) => set('customer_usage_limit', v)} error={errors.customer_usage_limit} />
            <TextField label="Starts" optional type="datetime-local" value={values.starts_at} onChange={(v) => set('starts_at', v)} error={errors.starts_at} />
            <TextField label="Ends" optional type="datetime-local" value={values.ends_at} onChange={(v) => set('ends_at', v)} error={errors.ends_at} />
            <TextField label="Priority" type="number" min={0} value={values.priority} onChange={(v) => set('priority', v)} error={errors.priority} hint="Decides the order promotions are applied in." />
          </div>
          <p className="text-xs text-slate-600">Start and end are in your store's timezone ({displayTimezoneName()}).</p>
        </div>

        <div className="mt-5 flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save promotion</Button>
        </div>
      </form>
    </Dialog>
  );
}

function CouponsDrawer({ promotion, canManage, onClose }: { promotion: Promotion; canManage: boolean; onClose: () => void }) {
  const list = useApi<{ data: Coupon[] }>(`/promotions/${promotion.id}/coupons`);
  const form = useForm({ code: '', usage_limit: '', customer_usage_limit: '' });
  const [removing, setRemoving] = useState<Coupon | null>(null);
  const { busy, run } = useAction();

  async function add(event: FormEvent) {
    event.preventDefault();
    const v = form.values;
    const body = { promotion_id: promotion.internal_id, code: v.code, usage_limit: v.usage_limit === '' ? null : Number(v.usage_limit), customer_usage_limit: v.customer_usage_limit === '' ? null : Number(v.customer_usage_limit) };
    if ((await form.submit(() => adminFetch('/coupons', { method: 'POST', body }), 'Coupon code added.')) !== undefined) {
      form.reset({ code: '', usage_limit: '', customer_usage_limit: '' });
      list.reload();
    }
  }

  const columns: Column<Coupon>[] = [
    { key: 'code', header: 'Code', render: (coupon) => <span className="font-mono font-medium">{coupon.code}</span> },
    { key: 'used', header: 'Used', align: 'right', priority: true, render: (coupon) => `${coupon.used_count}${coupon.usage_limit === null ? '' : ` of ${coupon.usage_limit}`}` },
    { key: 'state', header: 'Status', priority: true, render: (coupon) => <Badge tone={coupon.is_active ? 'green' : 'neutral'}>{coupon.is_active ? 'Active' : 'Switched off'}</Badge> },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (coupon) => canManage && coupon.is_active && <Button size="sm" variant="ghost" onClick={() => setRemoving(coupon)}>Switch off<span className="sr-only"> {coupon.code}</span></Button>,
    },
  ];

  return (
    <Dialog open side title="Coupon codes" description={promotion.name} onClose={onClose} busy={form.busy}>
      <div className="space-y-5">
        <DataTable caption="Coupon codes" columns={columns} rows={list.data?.data ?? null} rowKey={(coupon) => coupon.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<p className="text-sm text-slate-600">No codes yet.</p>} />
        {canManage && (
          <form onSubmit={add} className="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-4" noValidate>
            <h3 className="text-sm font-semibold text-slate-900">Add a code</h3>
            <FormError message={form.formError} />
            <TextField label="Code" value={form.values.code} onChange={(v) => form.set('code', v)} error={form.errors.code} required maxLength={64} hint="What the customer types at checkout." />
            <div className="grid gap-3 sm:grid-cols-2">
              <TextField label="Total uses" optional type="number" min={1} value={form.values.usage_limit} onChange={(v) => form.set('usage_limit', v)} error={form.errors.usage_limit} />
              <TextField label="Uses per customer" optional type="number" min={1} value={form.values.customer_usage_limit} onChange={(v) => form.set('customer_usage_limit', v)} error={form.errors.customer_usage_limit} />
            </div>
            <div className="flex justify-end">
              <Button type="submit" variant="primary" busy={form.busy} busyLabel="Adding…">Add code</Button>
            </div>
          </form>
        )}
      </div>
      <ConfirmDialog
        open={removing !== null}
        title="Switch off this code?"
        confirmLabel="Switch off"
        busy={busy === 'off'}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing &&
          run('off', () => adminFetch(`/coupons/${removing.id}`, { method: 'DELETE' }), { success: 'Coupon code switched off.' }).then((result) => {
            if (result !== undefined) {
              setRemoving(null);
              list.reload();
            }
          })
        }
      >
        <p>
          <strong>{removing?.code}</strong> stops working at checkout. Orders that already used it are not changed. It cannot be switched back on from this page.
        </p>
      </ConfirmDialog>
    </Dialog>
  );
}

export default function Promotions() {
  const access = useAccess();
  const canManage = access.can('promotions.manage');
  const [page, setPage] = useState(1);
  const list = usePagedApi<Promotion>('/promotions', { page });
  const [editing, setEditing] = useState<Promotion | 'new' | null>(null);
  const [coupons, setCoupons] = useState<Promotion | null>(null);

  const columns: Column<Promotion>[] = [
    {
      key: 'name',
      header: 'Promotion',
      render: (promotion) => (
        <div>
          <span className="font-medium">{promotion.name}</span>
          <p className="text-xs text-slate-600">{discountText(promotion)} · {targetSummary(promotion)}{promotion.requires_coupon ? ' · needs a code' : ''}</p>
        </div>
      ),
    },
    { key: 'status', header: 'Status', priority: true, render: (promotion) => <StatusBadge status={promotion.status} /> },
    { key: 'used', header: 'Used', align: 'right', render: (promotion) => `${promotion.used_count}${promotion.usage_limit === null ? '' : ` of ${promotion.usage_limit}`}` },
    { key: 'starts', header: 'Starts', render: (promotion) => dateTimeOrDash(promotion.starts_at) },
    { key: 'ends', header: 'Ends', render: (promotion) => dateTimeOrDash(promotion.ends_at) },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (promotion) => (
        <span className="flex flex-wrap justify-end gap-1">
          <Button size="sm" variant="ghost" onClick={() => setCoupons(promotion)}>Codes<span className="sr-only"> of {promotion.name}</span></Button>
          {canManage && <Button size="sm" variant="ghost" onClick={() => setEditing(promotion)}>Edit<span className="sr-only"> {promotion.name}</span></Button>}
        </span>
      ),
    },
  ];

  return (
    <AdminPage
      title="Promotions"
      description="Discounts for your customers, with or without a coupon code. To retire a promotion, set its status to Archived."
      actions={canManage && <Button variant="primary" onClick={() => setEditing('new')}>Add promotion</Button>}
    >
      <DataTable
        caption="Promotions"
        columns={columns}
        rows={list.rows}
        rowKey={(promotion) => promotion.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={<EmptyPanel title="No promotions yet" description="Create a discount to run a sale or reward a coupon code." action={canManage ? <Button variant="primary" onClick={() => setEditing('new')}>Add promotion</Button> : undefined} />}
      />
      <Pagination meta={list.meta} disabled={list.loading} onPage={setPage} />

      {editing !== null && (
        <PromotionDialog
          promotion={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onDone={() => {
            setEditing(null);
            list.reload();
          }}
        />
      )}
      {coupons && <CouponsDrawer promotion={coupons} canManage={canManage} onClose={() => setCoupons(null)} />}
    </AdminPage>
  );
}
