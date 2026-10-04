import { FormEvent, useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Button, { ButtonLink } from '@/Components/ui/Button';
import { CheckboxField, FormError, SelectField, SwitchField, TextAreaField, TextField } from '@/Components/ui/Form';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import { Card, EmptyPanel, ErrorPanel, PackageNotice, Skeleton, Tabs, AccessNotice } from '@/Components/ui/Page';
import { StatusBadge } from '@/Components/ui/Badge';
import { toast } from '@/Components/ui/toast';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction, useForm, useUnsavedWarning } from '@/lib/useForm';
import { adminErrorMessage, adminFetch, AdminApiError } from '@/lib/adminApi';
import { fromMinor, money, toMinor } from '@/lib/money';
import { options } from '@/lib/labels';
import CurrencyField from '@/Components/CurrencyField';
import TranslationsPanel from '@/Components/TranslationsPanel';
import RelatedProducts from '@/Components/Catalog/RelatedProducts';
import SpecificationsCard from '@/Components/Catalog/SpecificationsCard';
import {
  categoryTree,
  PRODUCT_STATUSES,
  PRODUCT_TYPES,
  parseOptionLines,
  parseTags,
  PRODUCT_VISIBILITIES,
  VARIANT_STATUSES,
  variantLabel,
  type Brand,
  type Category,
  type Collection,
  type Product,
  type ProductImage,
  type Variant,
} from '@/lib/catalog';

/**
 * Module 06 §65 "Product Form Architecture": a product is edited in
 * sections (details, pricing, organisation, variants, images) rather than
 * as one long form. Everything is saved through /api/v1/products; the
 * server validates and enforces the package's product limit. Fields the
 * backend does not have are not offered.
 */
type FormValues = {
  type: string;
  name: string;
  sku: string;
  short_description: string;
  description: string;
  status: string;
  visibility: string;
  brand_id: string;
  primary_category_id: string;
  category_ids: number[];
  price: string;
  sale_price: string;
  cost_price: string;
  currency: string;
  /** Phase B39 (Module 06 §35–37). */
  is_featured: boolean;
  tags: string;
  collection_ids: string[];
};

function blank(currency: string): FormValues {
  return {
    type: 'simple', name: '', sku: '', short_description: '', description: '', status: 'draft', visibility: 'public',
    brand_id: '', primary_category_id: '', category_ids: [], price: '', sale_price: '', cost_price: '', currency,
    is_featured: false, tags: '', collection_ids: [],
  };
}

function fromProduct(product: Product, fallbackCurrency: string): FormValues {
  const currency = product.currency ?? fallbackCurrency;

  return {
    type: product.type,
    name: product.name,
    sku: product.sku ?? '',
    short_description: product.short_description ?? '',
    description: product.description ?? '',
    status: product.status,
    visibility: product.visibility,
    brand_id: product.brand_id === null ? '' : String(product.brand_id),
    primary_category_id: product.primary_category_id === null ? '' : String(product.primary_category_id),
    category_ids: product.category_ids ?? [],
    price: fromMinor(product.price_minor, currency),
    sale_price: fromMinor(product.sale_price_minor, currency),
    cost_price: fromMinor(product.cost_price_minor, currency),
    currency,
    is_featured: product.is_featured ?? false,
    tags: (product.tags ?? []).join(', '),
    collection_ids: product.collection_ids ?? [],
  };
}

/** An amount field's text as minor units: null for empty, or an error message. */
function amount(text: string, currency: string): { minor: number | null; error?: string } {
  if (text.trim() === '') return { minor: null };
  const minor = toMinor(text, currency);

  return minor === null ? { minor: null, error: `Enter an amount such as 12.50 (${currency}).` } : { minor };
}

function ProductForm({ product, onSaved }: { product: Product | null; onSaved: (product: Product) => void }) {
  const access = useAccess();
  const canCost = access.can('products.view_cost');
  const canSave = product === null ? access.can('products.create') : access.can('products.update');
  const form = useForm<FormValues>(product ? fromProduct(product, access.currency) : blank(access.currency));
  const brands = useApi<{ data: Brand[] }>('/brands');
  const categories = useApi<{ data: Category[] }>('/categories');
  // Phase B39: only hand-picked collections take a product directly; rule-based ones choose by themselves.
  const canCollections = access.can('collections.manage');
  const collections = useApi<{ data: Collection[] }>(canCollections ? '/collections' : null);
  const manualCollections = (collections.data?.data ?? []).filter((collection) => collection.type === 'manual');
  const [amountErrors, setAmountErrors] = useState<Record<string, string>>({});
  const [limitReached, setLimitReached] = useState(false);
  const [tab, setTab] = useState('details');
  // Phase B37: pictures chosen while creating; uploaded right after the product exists.
  const [pictures, setPictures] = useState<File[]>([]);
  const [previews, setPreviews] = useState<string[]>([]);
  useEffect(() => {
    const urls = pictures.map((file) => URL.createObjectURL(file));
    setPreviews(urls);

    return () => urls.forEach((url) => URL.revokeObjectURL(url));
  }, [pictures]);
  useUnsavedWarning(form.dirty || pictures.length > 0);

  const { values, set } = form;
  const tree = categoryTree(categories.data?.data ?? []);

  async function save(event: FormEvent) {
    event.preventDefault();
    const price = amount(values.price, values.currency);
    const sale = amount(values.sale_price, values.currency);
    const cost = amount(values.cost_price, values.currency);
    const problems: Record<string, string> = {};
    if (price.error) problems.price_minor = price.error;
    if (sale.error) problems.sale_price_minor = sale.error;
    if (cost.error) problems.cost_price_minor = cost.error;
    setAmountErrors(problems);
    if (Object.keys(problems).length > 0) {
      setTab('pricing');

      return;
    }
    setLimitReached(false);

    const body: Record<string, unknown> = {
      name: values.name,
      sku: values.sku === '' ? null : values.sku,
      short_description: values.short_description === '' ? null : values.short_description,
      description: values.description === '' ? null : values.description,
      status: values.status,
      visibility: values.visibility,
      brand_id: values.brand_id === '' ? null : Number(values.brand_id),
      primary_category_id: values.primary_category_id === '' ? null : Number(values.primary_category_id),
      category_ids: values.category_ids,
      price_minor: price.minor,
      sale_price_minor: sale.minor,
      currency: values.currency.toUpperCase(),
      is_featured: values.is_featured,
      tags: parseTags(values.tags),
    };
    if (canCollections && collections.data) body.collection_ids = values.collection_ids;
    // A user who cannot see the cost price must not blank it by saving.
    if (canCost) body.cost_price_minor = cost.minor;
    if (product === null) body.type = values.type;

    const saved = await form.submit(async () => {
      try {
        return await adminFetch<{ data: Product }>(product === null ? '/products' : `/products/${product.id}`, { method: product === null ? 'POST' : 'PUT', body });
      } catch (e) {
        if (e instanceof AdminApiError && e.status === 403 && (e.code === 'usage_limit_exceeded' || /product limit/i.test(e.message))) setLimitReached(true);
        throw e;
      }
    }, product === null ? 'Product created.' : 'Product saved.');
    if (saved && product === null && pictures.length > 0) {
      let failed = 0;
      for (const file of pictures) {
        const upload = new FormData();
        upload.append('image', file);
        try {
          await adminFetch(`/products/${saved.data.id}/images`, { method: 'POST', body: upload, timeoutMs: 60000 });
        } catch {
          failed++;
        }
      }
      if (failed > 0) toast.error(`${failed} of ${pictures.length} pictures could not be added. Add them again under Images.`);
      setPictures([]);
    }
    if (saved) onSaved(saved.data);
  }

  const errors = { ...form.errors, ...amountErrors };
  const tabs = [
    { id: 'details', label: 'Details' },
    { id: 'pricing', label: 'Pricing' },
    { id: 'organisation', label: 'Organisation' },
  ];

  return (
    <form onSubmit={save} noValidate>
      {limitReached && (
        <div className="mb-4">
          <PackageNotice title="Your package's product limit is reached" packageName={access.packageName} canSeeBilling={access.can('billing.view')}>
            Archive a product you no longer sell, or move to a larger package, to add this one.
          </PackageNotice>
        </div>
      )}
      <div className="mb-4">
        <FormError message={form.formError} errors={errors} />
      </div>

      <Tabs tabs={tabs} active={tab} onChange={setTab} label="Product sections" />

      <div role="tabpanel" hidden={tab !== 'details'} className="space-y-4">
        <Card>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <TextField label="Name" value={values.name} onChange={(v) => set('name', v)} error={errors.name} required maxLength={255} />
            </div>
            {product === null ? (
              <SelectField label="Type" value={values.type} onChange={(v) => set('type', v)} options={options(PRODUCT_TYPES)} error={errors.type} hint="Cannot be changed after the product is created." />
            ) : (
              <TextField label="Type" value={values.type} onChange={() => undefined} disabled hint="Set when the product was created." />
            )}
            <TextField label="SKU" optional value={values.sku} onChange={(v) => set('sku', v)} error={errors.sku} maxLength={64} />
            <SelectField label="Status" value={values.status} onChange={(v) => set('status', v)} options={options(PRODUCT_STATUSES)} error={errors.status} hint="Only active products can be bought." />
            <SelectField label="Visibility" value={values.visibility} onChange={(v) => set('visibility', v)} options={options(PRODUCT_VISIBILITIES)} error={errors.visibility} />
            <div className="sm:col-span-2">
              <TextAreaField label="Short description" optional value={values.short_description} onChange={(v) => set('short_description', v)} error={errors.short_description} rows={2} maxLength={500} hint="Up to 500 characters. Shown in product lists." />
            </div>
            <div className="sm:col-span-2">
              <TextAreaField label="Description" optional value={values.description} onChange={(v) => set('description', v)} error={errors.description} rows={8} hint="Plain text. It is shown as text on the product page." />
            </div>
          </div>
        </Card>
        {product === null && (
          <Card title="Pictures" description="Choose JPG or PNG files from your computer. They are added when you create the product; the first one is the main picture.">
            <label htmlFor="new-product-pictures" className="block text-sm font-medium text-slate-700">Choose files (JPG, PNG)</label>
            <input
              id="new-product-pictures"
              type="file"
              multiple
              accept="image/jpeg,image/png"
              className="mt-1 block w-full text-sm"
              onChange={(e) => {
                const chosen = Array.from(e.target.files ?? []).filter((file) => ['image/jpeg', 'image/png'].includes(file.type));
                setPictures((current) => [...current, ...chosen].slice(0, 12));
                e.target.value = '';
              }}
            />
            <p className="mt-1 text-xs text-slate-500">Up to 12 pictures, 5 MB each.</p>
            {pictures.length > 0 && (
              <ul className="mt-3 grid grid-cols-3 gap-3 sm:grid-cols-6">
                {pictures.map((file, index) => (
                  <li key={`${file.name}-${index}`} className="rounded-md border border-slate-200 p-1">
                    <img src={previews[index]} alt={`Picture ${index + 1}: ${file.name}`} className="aspect-square w-full rounded object-cover" />
                    <Button size="sm" variant="ghost" className="mt-1 w-full" onClick={() => setPictures((current) => current.filter((_, i) => i !== index))}>
                      Remove<span className="sr-only"> {file.name}</span>
                    </Button>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        )}
      </div>

      <div role="tabpanel" hidden={tab !== 'pricing'} className="space-y-4">
        <Card description="Amounts are entered in the currency below and stored exactly.">
          <div className="grid gap-4 sm:grid-cols-2">
            <CurrencyField value={values.currency} onChange={(v) => set('currency', v)} error={errors.currency} hint="New products start in your store currency." />
            <TextField label="Price" optional inputMode="decimal" value={values.price} onChange={(v) => set('price', v)} error={errors.price_minor} hint="The price of the product itself. Each variant has its own price." />
            <TextField label="Sale price" optional inputMode="decimal" value={values.sale_price} onChange={(v) => set('sale_price', v)} error={errors.sale_price_minor} hint="Must be lower than the price." />
            {canCost && <TextField label="Cost price" optional inputMode="decimal" value={values.cost_price} onChange={(v) => set('cost_price', v)} error={errors.cost_price_minor} hint="Never shown to customers." />}
          </div>
        </Card>
      </div>

      <div role="tabpanel" hidden={tab !== 'organisation'} className="space-y-4">
        <Card>
          <div className="grid gap-4 sm:grid-cols-2">
            <SelectField
              label="Brand"
              optional
              value={values.brand_id}
              onChange={(v) => set('brand_id', v)}
              error={errors.brand_id ?? (brands.error ? 'Brands could not be loaded.' : undefined)}
              placeholder="No brand"
              options={(brands.data?.data ?? []).map((brand) => ({ value: String(brand.id), label: brand.name }))}
            />
            <SelectField
              label="Main category"
              optional
              value={values.primary_category_id}
              onChange={(v) => set('primary_category_id', v)}
              error={errors.primary_category_id ?? (categories.error ? 'Categories could not be loaded.' : undefined)}
              placeholder="No main category"
              options={tree.map(({ category, depth }) => ({ value: String(category.id), label: `${'— '.repeat(depth)}${category.name}` }))}
            />
          </div>
          <fieldset className="mt-4">
            <legend className="text-sm font-medium text-slate-700">Also listed in</legend>
            {tree.length === 0 ? (
              <p className="mt-1 text-sm text-slate-600">No categories yet. Create them under Catalog → Categories.</p>
            ) : (
              <div className="mt-2 grid gap-2 sm:grid-cols-2">
                {tree.map(({ category, depth }) => (
                  <div key={category.id} style={{ paddingLeft: `${depth * 16}px` }}>
                    <CheckboxField
                      label={category.name}
                      checked={values.category_ids.includes(category.id)}
                      onChange={(checked) => set('category_ids', checked ? [...values.category_ids, category.id] : values.category_ids.filter((id) => id !== category.id))}
                    />
                  </div>
                ))}
              </div>
            )}
            {errors.category_ids && <p role="alert" className="mt-1 text-xs font-medium text-red-700">{errors.category_ids}</p>}
          </fieldset>
        </Card>
        <Card title="Merchandising" description="How this product is grouped and highlighted on your storefront.">
          <div className="space-y-4">
            <SwitchField label="Featured product" hint="Featured products can fill the “Featured products” section of your home page." checked={values.is_featured} onChange={(v) => set('is_featured', v)} />
            <TextField label="Tags" optional value={values.tags} onChange={(v) => set('tags', v)} error={errors.tags ?? errors['tags.0']} hint="Separate tags with commas, for example: Eid, Handmade. Up to 20, each up to 60 characters." />
            {canCollections && (
              <fieldset>
                <legend className="text-sm font-medium text-slate-700">Hand-picked collections</legend>
                {collections.error ? (
                  <p className="mt-1 text-sm text-red-700">Collections could not be loaded.</p>
                ) : manualCollections.length === 0 ? (
                  <p className="mt-1 text-sm text-slate-600">No hand-picked collections yet. Create them under Catalog → Collections. Rule-based collections pick their products by themselves.</p>
                ) : (
                  <div className="mt-2 grid gap-2 sm:grid-cols-2">
                    {manualCollections.map((collection) => (
                      <CheckboxField
                        key={collection.id}
                        label={collection.name}
                        checked={values.collection_ids.includes(collection.id)}
                        onChange={(checked) => set('collection_ids', checked ? [...values.collection_ids, collection.id] : values.collection_ids.filter((id) => id !== collection.id))}
                      />
                    ))}
                  </div>
                )}
                {errors.collection_ids && <p role="alert" className="mt-1 text-xs font-medium text-red-700">{errors.collection_ids}</p>}
              </fieldset>
            )}
          </div>
        </Card>
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-3">
        {canSave ? (
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">
            {product === null ? 'Create product' : 'Save changes'}
          </Button>
        ) : (
          <p className="text-sm text-slate-600">Your role can view this product but not change it.</p>
        )}
        <ButtonLink href="/products">Back to products</ButtonLink>
        {form.dirty && <span className="text-sm text-amber-800">You have unsaved changes.</span>}
      </div>
    </form>
  );
}

type VariantValues = { sku: string; barcode: string; price: string; sale_price: string; cost_price: string; weight: string; status: string; options: string };

function variantValues(variant: Variant | null, currency: string): VariantValues {
  return {
    sku: variant?.sku ?? '',
    barcode: variant?.barcode ?? '',
    price: fromMinor(variant?.price_minor, currency),
    sale_price: fromMinor(variant?.sale_price_minor, currency),
    cost_price: fromMinor(variant?.cost_price_minor, currency),
    weight: variant?.weight === null || variant?.weight === undefined ? '' : String(variant.weight),
    status: variant?.status ?? 'active',
    options: Object.entries(variant?.option_values ?? {}).map(([key, value]) => `${key}: ${value}`).join('\n'),
  };
}

function VariantsSection({ product, onChanged }: { product: Product; onChanged: () => void }) {
  const access = useAccess();
  const currency = product.currency ?? access.currency;
  const canEdit = access.can('products.update');
  const canCost = access.can('products.view_cost');
  const [editing, setEditing] = useState<Variant | 'new' | null>(null);
  const [removing, setRemoving] = useState<Variant | null>(null);
  const form = useForm<VariantValues>(variantValues(null, currency));
  const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
  const { busy, run } = useAction();

  function open(target: Variant | 'new') {
    form.reset(variantValues(target === 'new' ? null : target, currency));
    setLocalErrors({});
    setEditing(target);
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    const v = form.values;
    const price = amount(v.price, currency);
    const sale = amount(v.sale_price, currency);
    const cost = amount(v.cost_price, currency);
    const parsed = parseOptionLines(v.options);
    const problems: Record<string, string> = {};
    if (price.error) problems.price_minor = price.error;
    if (sale.error) problems.sale_price_minor = sale.error;
    if (cost.error) problems.cost_price_minor = cost.error;
    if (parsed.error) problems.option_values = parsed.error;
    if (v.weight !== '' && Number.isNaN(Number(v.weight))) problems.weight = 'Enter a number.';
    setLocalErrors(problems);
    if (Object.keys(problems).length > 0) return;

    const body: Record<string, unknown> = {
      sku: v.sku === '' ? null : v.sku,
      barcode: v.barcode === '' ? null : v.barcode,
      price_minor: price.minor,
      sale_price_minor: sale.minor,
      weight: v.weight === '' ? null : Number(v.weight),
      status: v.status,
      option_values: Object.keys(parsed.values).length === 0 ? null : parsed.values,
    };
    if (canCost) body.cost_price_minor = cost.minor;

    const target = editing;
    const saved = await form.submit(
      () => adminFetch(target === 'new' ? `/products/${product.id}/variants` : `/products/${product.id}/variants/${(target as Variant).id}`, { method: target === 'new' ? 'POST' : 'PUT', body }),
      target === 'new' ? 'Variant added.' : 'Variant saved.',
    );
    if (saved !== undefined) {
      setEditing(null);
      onChanged();
    }
  }

  const errors = { ...form.errors, ...localErrors };
  const columns: Column<Variant>[] = [
    { key: 'options', header: 'Variant', render: (variant) => <span className="font-medium">{variantLabel(variant)}</span> },
    { key: 'sku', header: 'SKU', render: (variant) => <span className="font-mono text-xs">{variant.sku ?? '—'}</span> },
    { key: 'price', header: 'Price', align: 'right', priority: true, render: (variant) => (variant.effective_price_minor === null ? <span className="text-amber-800">No price: cannot be ordered</span> : money(variant.effective_price_minor, currency)) },
    { key: 'status', header: 'Status', render: (variant) => <StatusBadge status={variant.status} /> },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (variant) =>
        canEdit && (
          <span className="flex justify-end gap-1">
            <Button size="sm" variant="ghost" onClick={() => open(variant)}>Edit</Button>
            {access.can('products.delete') && <Button size="sm" variant="ghost" onClick={() => setRemoving(variant)}>Delete</Button>}
          </span>
        ),
    },
  ];

  return (
    <Card title="Variants" description="Sellable versions of this product, such as sizes or colours." actions={canEdit && <Button size="sm" onClick={() => open('new')}>Add variant</Button>}>
      <DataTable
        caption="Variants"
        columns={columns}
        rows={product.variants ?? []}
        rowKey={(variant) => variant.id}
        empty={<p className="text-sm text-slate-600">No variants. The product is sold as a single item at its own price.</p>}
      />

      <Dialog open={editing !== null} title={editing === 'new' ? 'Add variant' : 'Edit variant'} onClose={() => setEditing(null)} busy={form.busy} wide>
        <form onSubmit={save} className="space-y-4" noValidate>
          <FormError message={form.formError} />
          <TextAreaField label="Options" optional rows={3} value={form.values.options} onChange={(v) => form.set('options', v)} error={errors.option_values} hint='One per line, as "Name: value". For example "Size: M".' data-autofocus />
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label="SKU" optional value={form.values.sku} onChange={(v) => form.set('sku', v)} error={errors.sku} maxLength={64} />
            <TextField label="Barcode" optional value={form.values.barcode} onChange={(v) => form.set('barcode', v)} error={errors.barcode} maxLength={64} />
            <TextField label={`Price (${currency})`} optional inputMode="decimal" value={form.values.price} onChange={(v) => form.set('price', v)} error={errors.price_minor} hint="A variant is sold at its own price. Without one it cannot be ordered." />
            <TextField label={`Sale price (${currency})`} optional inputMode="decimal" value={form.values.sale_price} onChange={(v) => form.set('sale_price', v)} error={errors.sale_price_minor} />
            {canCost && <TextField label={`Cost price (${currency})`} optional inputMode="decimal" value={form.values.cost_price} onChange={(v) => form.set('cost_price', v)} error={errors.cost_price_minor} />}
            <TextField label="Weight" optional inputMode="decimal" value={form.values.weight} onChange={(v) => form.set('weight', v)} error={errors.weight} />
            <SelectField label="Status" value={form.values.status} onChange={(v) => form.set('status', v)} options={options(VARIANT_STATUSES)} error={errors.status} />
          </div>
          <div className="flex justify-end gap-2">
            <Button onClick={() => setEditing(null)} disabled={form.busy}>Cancel</Button>
            <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save variant</Button>
          </div>
        </form>
      </Dialog>

      <ConfirmDialog
        open={removing !== null}
        title="Delete this variant?"
        confirmLabel="Delete variant"
        busy={busy === 'delete'}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing &&
          run('delete', () => adminFetch(`/products/${product.id}/variants/${removing.id}`, { method: 'DELETE' }), { success: 'Variant deleted.' }).then((result) => {
            if (result !== undefined) {
              setRemoving(null);
              onChanged();
            }
          })
        }
      >
        <p>
          <strong>{removing ? variantLabel(removing) : ''}</strong> will no longer be sold. Past orders keep their record of it. This cannot be undone from the admin.
        </p>
      </ConfirmDialog>
    </Card>
  );
}

function ImagesSection({ product }: { product: Product }) {
  const access = useAccess();
  const canEdit = access.can('products.update');
  const images = useApi<{ data: ProductImage[] }>(`/products/${product.id}/images`);
  const file = useRef<HTMLInputElement>(null);
  const [alt, setAlt] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [removing, setRemoving] = useState<ProductImage | null>(null);
  const { busy, run } = useAction();

  function upload(event: FormEvent) {
    event.preventDefault();
    const chosen = file.current?.files?.[0];
    if (!chosen) {
      setError('Choose an image file first.');

      return;
    }
    setError(null);
    const body = new FormData();
    body.append('image', chosen);
    if (alt !== '') body.append('alt', alt);
    // An upload can take longer than a normal request.
    void run('upload', () => adminFetch(`/products/${product.id}/images`, { method: 'POST', body, timeoutMs: 60000 }), { success: 'Image added.', onError: setError }).then((result) => {
      if (result !== undefined) {
        if (file.current) file.current.value = '';
        setAlt('');
        images.reload();
      }
    });
  }

  function move(index: number, by: number) {
    const list = images.data?.data ?? [];
    const order = list.map((image) => image.id);
    const [moved] = order.splice(index, 1);
    order.splice(index + by, 0, moved);
    void run('order', () => adminFetch<{ data: ProductImage[] }>(`/products/${product.id}/images/order`, { method: 'PUT', body: { images: order } })).then((result) => result && images.setData(result));
  }

  const list = images.data?.data ?? null;

  return (
    <Card title="Images" description="Shown on the storefront in this order. The first is the main image.">
      {images.error ? (
        <ErrorPanel message={images.error} onRetry={images.reload} />
      ) : list === null ? (
        <Skeleton lines={2} />
      ) : list.length === 0 ? (
        <p className="text-sm text-slate-600">No images yet.</p>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
          {list.map((image, index) => (
            <li key={image.id} className="rounded-md border border-slate-200 p-2">
              <img src={image.url} alt={image.alt ?? ''} className="aspect-square w-full rounded object-cover" loading="lazy" />
              <p className="mt-1 truncate text-xs text-slate-600">{image.alt || 'No description'}</p>
              {canEdit && (
                <div className="mt-1 flex flex-wrap gap-1">
                  <Button size="sm" variant="ghost" disabled={index === 0 || busy !== null} onClick={() => move(index, -1)} aria-label={`Move image ${index + 1} earlier`}>←</Button>
                  <Button size="sm" variant="ghost" disabled={index === list.length - 1 || busy !== null} onClick={() => move(index, 1)} aria-label={`Move image ${index + 1} later`}>→</Button>
                  <Button size="sm" variant="ghost" onClick={() => setRemoving(image)}>Remove<span className="sr-only"> image {index + 1}</span></Button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      {canEdit && (
        <form onSubmit={upload} className="mt-4 grid gap-3 border-t border-slate-200 pt-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end" noValidate>
          <div>
            <label htmlFor="product-image" className="block text-sm font-medium text-slate-700">Add an image</label>
            <input id="product-image" ref={file} type="file" accept="image/jpeg,image/png,image/webp" className="mt-1 block w-full text-sm" />
            <p className="mt-1 text-xs text-slate-500">Choose a file: JPG, PNG or WebP.</p>
          </div>
          <TextField label="Description (alt text)" optional value={alt} onChange={setAlt} maxLength={255} hint="Read out to people who cannot see the image." />
          <Button type="submit" busy={busy === 'upload'} busyLabel="Uploading…">Upload</Button>
          {error && <p role="alert" className="text-sm font-medium text-red-700 sm:col-span-3">{error}</p>}
        </form>
      )}

      <ConfirmDialog
        open={removing !== null}
        title="Remove this image?"
        confirmLabel="Remove image"
        busy={busy === 'remove'}
        onClose={() => setRemoving(null)}
        onConfirm={() =>
          removing &&
          run('remove', () => adminFetch(`/products/${product.id}/images/${removing.id}`, { method: 'DELETE' }), { success: 'Image removed.' }).then((result) => {
            if (result !== undefined) {
              setRemoving(null);
              images.reload();
            }
          })
        }
      >
        <p>The image is deleted from this product and from your storefront. This cannot be undone.</p>
      </ConfirmDialog>
    </Card>
  );
}

export default function ProductEdit({ productId }: { productId: string | null }) {
  const access = useAccess();
  const canTranslate = access.can('products.update');
  const { busy, run } = useAction();
  const state = useApi<{ data: Product }>(productId === null ? null : `/products/${productId}`);
  const product = state.data?.data ?? null;

  // A deleted or foreign product: say so once, then the list is the place to be.
  useEffect(() => {
    if (state.errorStatus === 404) toast.error('This product was not found.');
  }, [state.errorStatus]);

  if (productId === null) {
    return (
      <AdminPage title="Add product" trail={[{ label: 'New product' }]} description="Add the details and pictures. Variants are added after the product is created.">
        <ProductForm product={null} onSaved={(created) => router.visit(`/products/${created.id}`)} />
      </AdminPage>
    );
  }

  return (
    <AdminPage
      title={product?.name ?? 'Product'}
      trail={[{ label: product?.name ?? 'Product' }]}
      actions={
        product !== null &&
        access.can('products.create') && (
          // Phase B39 (Module 06 §60): a hidden draft copy, without SKU, stock or reviews.
          <Button
            busy={busy === 'duplicate'}
            busyLabel="Duplicating…"
            onClick={() =>
              run('duplicate', () => adminFetch<{ data: Product }>(`/products/${product.id}/duplicate`, { method: 'POST' }), { success: 'Copy created as a hidden draft.' }).then((copy) => {
                if (copy) router.visit(`/products/${copy.data.id}`);
              })
            }
          >
            Duplicate
          </Button>
        )
      }
    >
      {state.error ? (
        state.errorStatus === 403 ? <AccessNotice message={state.error} /> : state.errorStatus === 404 ? (
          <EmptyPanel title="Product not found" description="It may have been deleted." action={<ButtonLink href="/products">Back to products</ButtonLink>} />
        ) : <ErrorPanel message={adminErrorMessage(null, state.error)} onRetry={state.reload} />
      ) : product === null ? (
        <Skeleton lines={6} />
      ) : (
        <div className="space-y-6">
          <ProductForm key={product.id} product={product} onSaved={(saved) => state.setData({ data: saved })} />
          <VariantsSection product={product} onChanged={state.reload} />
          <ImagesSection product={product} />
          <SpecificationsCard key={`specs-${product.primary_category_id ?? 0}`} productId={product.id} canEdit={canTranslate} />
          <RelatedProducts productId={product.id} canEdit={canTranslate} />
          {/* Phase B38: name and descriptions in the other storefront languages. */}
          <Card title="Translations" description="How this product reads in your other storefront languages. A field left empty shows the original.">
            <TranslationsPanel type="product" id={product.id} canEdit={canTranslate} />
          </Card>
          <Card title="Stock">
            <p className="text-sm text-slate-700">Stock for this product and its variants is kept per warehouse.</p>
            <div className="mt-3">
              <ButtonLink href="/inventory" size="sm">Open inventory</ButtonLink>
            </div>
          </Card>
        </div>
      )}
    </AdminPage>
  );
}
