import { useEffect, useId, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { CheckboxField, FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import Badge, { humanize } from '@/Components/ui/Badge';
import { Card, ErrorPanel, PackageNotice, Skeleton, Tabs, AccessNotice } from '@/Components/ui/Page';
import { useAccess, useAuth } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction, useUnsavedWarning } from '@/lib/useForm';
import { adminErrorMessage, adminFetch, wasCancelled } from '@/lib/adminApi';
import { toast } from '@/Components/ui/toast';
import { formatDateTime } from '@/lib/datetime';

/**
 * Module 17 "Theme, Branding & Design System" (/api/v1/store/theme).
 *
 * The theme is configuration, not code: colours, type, branding and the
 * home page's sections, in the shape ThemeConfigValidator accepts. This
 * page edits the DRAFT; customers keep seeing the published version until
 * "Publish". Every value is validated by the server (hex colours, https
 * addresses, known section types); an invalid one comes back as its
 * message. Custom CSS belongs to the `theme.custom_css` package feature
 * and is cleaned by the server.
 */
type Section = { type: string; position: number; is_visible: boolean; config: Record<string, string | number> };
type Config = { tokens: Record<string, string>; branding: { logo_url?: string; favicon_url?: string; tagline?: string; social_links?: Record<string, string> }; sections: Section[] };
type StoreTheme = { theme: string; draft_config: Config; published_config: Config; custom_css: string | null; is_published: boolean; published_at: string | null };
type Publication = { id: number; published_at: string };

const COLORS: [string, string][] = [
  ['primary', 'Primary'], ['secondary', 'Secondary'], ['accent', 'Accent'], ['background', 'Background'], ['surface', 'Surface'], ['text', 'Text'],
  ['muted', 'Muted text'], ['border', 'Borders'], ['success', 'Success'], ['warning', 'Warning'], ['error', 'Error'],
];
const FONTS = ['system-ui', 'Inter', 'Roboto', 'Georgia', 'Playfair Display', 'Merriweather'];
const SOCIAL = ['facebook', 'instagram', 'twitter', 'tiktok', 'youtube'];

/** The fields each section type has (ThemeConfigValidator::validateSectionConfig). */
const SECTION_FIELDS: Record<string, { key: string; label: string; kind?: 'url' | 'number' }[]> = {
  announcement_bar: [{ key: 'message', label: 'Message' }],
  hero: [{ key: 'heading', label: 'Heading' }, { key: 'subheading', label: 'Subheading' }, { key: 'image_url', label: 'Image address', kind: 'url' }, { key: 'cta_url', label: 'Button link', kind: 'url' }],
  promotional_banner: [{ key: 'heading', label: 'Heading' }, { key: 'image_url', label: 'Image address', kind: 'url' }, { key: 'cta_url', label: 'Link', kind: 'url' }],
  newsletter: [{ key: 'heading', label: 'Heading' }, { key: 'subheading', label: 'Subheading' }],
  featured_products: [{ key: 'heading', label: 'Heading' }, { key: 'limit', label: 'How many (1–50)', kind: 'number' }],
  featured_categories: [{ key: 'heading', label: 'Heading' }, { key: 'limit', label: 'How many (1–50)', kind: 'number' }],
  header: [],
  footer: [],
};
const ADDABLE = ['announcement_bar', 'hero', 'featured_products', 'featured_categories', 'promotional_banner', 'newsletter'];

function normalize(config: Config | undefined): Config {
  return { tokens: { ...(config?.tokens ?? {}) }, branding: { ...(config?.branding ?? {}) }, sections: [...(config?.sections ?? [])].sort((a, b) => a.position - b.position).map((section) => ({ ...section, config: { ...(section.config ?? {}) } })) };
}

/** The config as the server takes it: empty values left out, positions following the order shown. */
export function toPayload(config: Config): Config {
  const tokens = Object.fromEntries(Object.entries(config.tokens).filter(([, value]) => value !== ''));
  const social = Object.fromEntries(Object.entries(config.branding.social_links ?? {}).filter(([, value]) => value !== ''));
  const branding: Config['branding'] = {};
  if (config.branding.logo_url) branding.logo_url = config.branding.logo_url;
  if (config.branding.favicon_url) branding.favicon_url = config.branding.favicon_url;
  if (config.branding.tagline) branding.tagline = config.branding.tagline;
  if (Object.keys(social).length > 0) branding.social_links = social;

  return {
    tokens,
    branding,
    sections: config.sections.map((section, index) => ({
      type: section.type,
      position: index,
      is_visible: section.is_visible,
      config: Object.fromEntries(Object.entries(section.config).filter(([, value]) => value !== '' && value !== null)),
    })),
  };
}

function ColorField({ label, value, onChange, disabled }: { label: string; value: string; onChange: (value: string) => void; disabled: boolean }) {
  const id = useId();
  const valid = /^#[0-9a-fA-F]{6}$/.test(value);

  return (
    <div>
      <label htmlFor={id} className="block text-sm font-medium text-slate-700">{label}</label>
      <div className="mt-1 flex items-center gap-2">
        <input type="color" aria-label={`${label} colour picker`} value={valid ? value : '#ffffff'} disabled={disabled} onChange={(e) => onChange(e.target.value.toUpperCase())} className={`h-9 w-10 rounded border border-slate-300 bg-white p-0.5 ${FOCUS_RING}`} />
        <input id={id} value={value} disabled={disabled} onChange={(e) => onChange(e.target.value)} placeholder="Not set" maxLength={7} className={`block w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm ${FOCUS_RING}`} />
      </div>
    </div>
  );
}

export default function Theme() {
  const access = useAccess();
  const auth = useAuth();
  const canEdit = access.can('theme.manage');
  const canPublish = access.can('theme.publish');
  const cssIncluded = access.feature('theme.custom_css') !== false;
  const state = useApi<{ data: StoreTheme }>('/store/theme');
  const history = useApi<{ data: Publication[] }>('/store/theme/publications');
  const [config, setConfig] = useState<Config | null>(null);
  const [css, setCss] = useState('');
  const [dirty, setDirty] = useState(false);
  const [tab, setTab] = useState('look');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [confirm, setConfirm] = useState<'publish' | { rollback: Publication } | null>(null);
  const [adding, setAdding] = useState('hero');
  const { busy, run } = useAction();
  useUnsavedWarning(dirty);

  const theme = state.data?.data ?? null;

  useEffect(() => {
    if (theme) {
      setConfig(normalize(theme.draft_config));
      setCss(theme.custom_css ?? '');
      setDirty(false);
    }
    // Only when a new answer arrives from the server.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state.data]);

  function change(next: Config) {
    setConfig(next);
    setDirty(true);
  }

  async function saveDraft(): Promise<boolean> {
    if (config === null) return false;
    setSaving(true);
    setError(null);
    try {
      const body: Record<string, unknown> = { config: toPayload(config) };
      if (cssIncluded) body.custom_css = css.trim() === '' ? null : css;
      const saved = await adminFetch<{ data: StoreTheme }>('/store/theme/draft', { method: 'PUT', body });
      state.setData(saved);
      toast.success('Draft saved. Customers still see the published theme.');

      return true;
    } catch (e) {
      if (!wasCancelled(e)) setError(adminErrorMessage(e));

      return false;
    } finally {
      setSaving(false);
    }
  }

  if (state.error) {
    return <AdminPage title="Theme">{state.errorStatus === 403 ? <AccessNotice message={state.error} /> : <ErrorPanel message={state.error} onRetry={state.reload} />}</AdminPage>;
  }
  if (theme === null || config === null) {
    return <AdminPage title="Theme"><Skeleton lines={6} /></AdminPage>;
  }

  const unpublished = JSON.stringify(theme.draft_config) !== JSON.stringify(theme.published_config);

  // Phase B32 (Module 17 §19): a short-lived link that shows this store's
  // saved draft to whoever opens it, marked as a preview and not indexed.
  // The tab is opened at the click (a browser blocks one opened later) and
  // sent to the link once the server has made it.
  async function previewDraft() {
    const tab = window.open('about:blank', '_blank');
    const link = await run('preview', () => adminFetch<{ data: { url: string; expires_at: string } }>('/store/theme/preview', { method: 'POST' }));
    if (!link) {
      tab?.close();

      return;
    }
    if (tab) {
      tab.opener = null;
      tab.location.href = link.data.url;
    } else {
      window.location.href = link.data.url;
    }
  }
  const section = (index: number, patch: Partial<Section>) => change({ ...config, sections: config.sections.map((item, i) => (i === index ? { ...item, ...patch } : item)) });
  const moveSection = (index: number, by: number) => {
    const sections = [...config.sections];
    const [moved] = sections.splice(index, 1);
    sections.splice(index + by, 0, moved);
    change({ ...config, sections });
  };

  return (
    <AdminPage
      title="Theme"
      description={`Theme "${theme.theme}". You edit a draft; customers see it only after you publish.`}
      actions={
        <>
          {auth.activeStore && (
            <a href={`/shop/${auth.activeStore.slug}`} target="_blank" rel="noreferrer" className={`rounded text-sm text-indigo-700 hover:underline ${FOCUS_RING}`}>
              View published storefront<span className="sr-only"> (opens in a new tab)</span>
            </a>
          )}
          <Button
            onClick={() => void previewDraft()}
            busy={busy === 'preview'}
            busyLabel="Opening…"
            disabled={dirty}
            title={dirty ? 'Save the draft first: the preview shows the saved draft' : 'Opens your storefront with the draft, for 30 minutes. Customers do not see it.'}
          >
            Preview draft<span className="sr-only"> (opens in a new tab)</span>
          </Button>
          {canEdit && <Button onClick={() => void saveDraft()} busy={saving} busyLabel="Saving…" disabled={!dirty}>Save draft</Button>}
          {canPublish && <Button variant="primary" onClick={() => setConfirm('publish')} disabled={dirty || !unpublished} title={dirty ? 'Save the draft first' : !unpublished ? 'The draft is already published' : undefined}>Publish</Button>}
        </>
      }
    >
      <div className="mb-4 flex flex-wrap items-center gap-2 text-sm">
        {dirty ? <Badge tone="amber">Unsaved changes</Badge> : unpublished ? <Badge tone="blue">Draft saved, not published</Badge> : <Badge tone="green">Published</Badge>}
        {theme.published_at && <span className="text-slate-600">Last published {formatDateTime(theme.published_at)}</span>}
      </div>
      {error && <div className="mb-4"><FormError message={error} /></div>}

      <Tabs label="Theme sections" active={tab} onChange={setTab} tabs={[{ id: 'look', label: 'Colours and type' }, { id: 'brand', label: 'Branding' }, { id: 'sections', label: 'Home page' }, { id: 'css', label: 'Custom CSS' }, { id: 'history', label: 'History' }]} />

      <div role="tabpanel" hidden={tab !== 'look'} className="space-y-4">
        <Card title="Colours" description="Six-digit hex colours, such as #1A2B3C. Leave one empty to use the theme's own.">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {COLORS.map(([key, label]) => (
              <ColorField key={key} label={label} value={config.tokens[key] ?? ''} disabled={!canEdit} onChange={(value) => change({ ...config, tokens: { ...config.tokens, [key]: value } })} />
            ))}
          </div>
        </Card>
        <Card title="Type and shape">
          <div className="grid gap-4 sm:grid-cols-2">
            <SelectField label="Font" value={config.tokens.font_family ?? ''} disabled={!canEdit} onChange={(value) => change({ ...config, tokens: { ...config.tokens, font_family: value } })} placeholder="Theme default" options={FONTS.map((font) => ({ value: font, label: font }))} />
            <SelectField label="Corner rounding" value={config.tokens.radius ?? ''} disabled={!canEdit} onChange={(value) => change({ ...config, tokens: { ...config.tokens, radius: value } })} placeholder="Theme default" options={[{ value: 'sm', label: 'Small' }, { value: 'md', label: 'Medium' }, { value: 'lg', label: 'Large' }]} />
          </div>
        </Card>
      </div>

      <div role="tabpanel" hidden={tab !== 'brand'} className="space-y-4">
        <Card title="Brand" description="Addresses must start with https://. Upload the image somewhere it can be reached, then paste its address.">
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label="Logo address" optional type="url" disabled={!canEdit} value={config.branding.logo_url ?? ''} onChange={(value) => change({ ...config, branding: { ...config.branding, logo_url: value } })} />
            <TextField label="Favicon address" optional type="url" disabled={!canEdit} value={config.branding.favicon_url ?? ''} onChange={(value) => change({ ...config, branding: { ...config.branding, favicon_url: value } })} />
            <div className="sm:col-span-2">
              <TextField label="Tagline" optional disabled={!canEdit} maxLength={255} value={config.branding.tagline ?? ''} onChange={(value) => change({ ...config, branding: { ...config.branding, tagline: value } })} />
            </div>
          </div>
        </Card>
        <Card title="Social links">
          <div className="grid gap-4 sm:grid-cols-2">
            {SOCIAL.map((network) => (
              <TextField
                key={network}
                label={humanize(network)}
                optional
                type="url"
                disabled={!canEdit}
                value={config.branding.social_links?.[network] ?? ''}
                onChange={(value) => change({ ...config, branding: { ...config.branding, social_links: { ...(config.branding.social_links ?? {}), [network]: value } } })}
              />
            ))}
          </div>
        </Card>
      </div>

      <div role="tabpanel" hidden={tab !== 'sections'} className="space-y-4">
        <Card title="Home page sections" description="Shown from top to bottom in this order.">
          <ul className="space-y-3">
            {config.sections.map((item, index) => (
              <li key={`${item.type}-${index}`} className="rounded-md border border-slate-200 p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="font-medium text-slate-900">{humanize(item.type)}</span>
                  {canEdit && (
                    <span className="flex flex-wrap gap-1">
                      <Button size="sm" variant="ghost" disabled={index === 0} onClick={() => moveSection(index, -1)} aria-label={`Move ${humanize(item.type)} up`}>↑</Button>
                      <Button size="sm" variant="ghost" disabled={index === config.sections.length - 1} onClick={() => moveSection(index, 1)} aria-label={`Move ${humanize(item.type)} down`}>↓</Button>
                      {!['header', 'footer'].includes(item.type) && <Button size="sm" variant="ghost" onClick={() => change({ ...config, sections: config.sections.filter((_, i) => i !== index) })}>Remove</Button>}
                    </span>
                  )}
                </div>
                <div className="mt-2 space-y-3">
                  <CheckboxField label="Shown on the home page" checked={item.is_visible} disabled={!canEdit} onChange={(is_visible) => section(index, { is_visible })} />
                  {(SECTION_FIELDS[item.type] ?? []).length > 0 && (
                    <div className="grid gap-3 sm:grid-cols-2">
                      {(SECTION_FIELDS[item.type] ?? []).map((field) => (
                        <TextField
                          key={field.key}
                          label={field.label}
                          optional
                          disabled={!canEdit}
                          type={field.kind === 'url' ? 'url' : field.kind === 'number' ? 'number' : 'text'}
                          value={String(item.config[field.key] ?? '')}
                          onChange={(value) => section(index, { config: { ...item.config, [field.key]: field.kind === 'number' && value !== '' ? Number(value) : value } })}
                        />
                      ))}
                    </div>
                  )}
                </div>
              </li>
            ))}
          </ul>
          {canEdit && (
            <div className="mt-4 flex flex-wrap items-end gap-3 border-t border-slate-200 pt-4">
              <div className="w-56">
                <SelectField label="Add a section" value={adding} onChange={setAdding} options={ADDABLE.map((type) => ({ value: type, label: humanize(type) }))} />
              </div>
              <Button onClick={() => change({ ...config, sections: [...config.sections, { type: adding, position: config.sections.length, is_visible: true, config: {} }] })}>Add</Button>
            </div>
          )}
        </Card>
      </div>

      <div role="tabpanel" hidden={tab !== 'css'} className="space-y-4">
        {!cssIncluded && (
          <PackageNotice title="Custom CSS is not included in your package" packageName={access.packageName} canSeeBilling={access.can('billing.view')}>
            Colours, fonts, branding and sections are available on every package.
          </PackageNotice>
        )}
        <Card title="Custom CSS" description="For fine adjustments. The server removes scripts, imports and anything that is not CSS.">
          <TextAreaField label="CSS" optional rows={14} disabled={!canEdit || !cssIncluded} maxLength={20000} value={css} onChange={(value) => { setCss(value); setDirty(true); }} className="font-mono" />
        </Card>
      </div>

      <div role="tabpanel" hidden={tab !== 'history'} className="space-y-4">
        <Card title="Published versions" description="Going back to a version makes it the live theme and the draft.">
          {history.error ? (
            <ErrorPanel message={history.error} onRetry={history.reload} />
          ) : history.data === null ? (
            <Skeleton lines={3} />
          ) : history.data.data.length === 0 ? (
            <p className="text-sm text-slate-600">Nothing has been published yet.</p>
          ) : (
            <ul className="divide-y divide-slate-100 text-sm">
              {history.data.data.map((publication, index) => (
                <li key={publication.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                  <span>
                    {formatDateTime(publication.published_at)} {index === 0 && <Badge tone="green">Live</Badge>}
                  </span>
                  {canPublish && index > 0 && <Button size="sm" onClick={() => setConfirm({ rollback: publication })}>Go back to this version</Button>}
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <ConfirmDialog
        open={confirm !== null}
        title={confirm === 'publish' ? 'Publish the draft?' : 'Go back to this version?'}
        confirmLabel={confirm === 'publish' ? 'Publish' : 'Go back'}
        variant="primary"
        busy={busy !== null}
        onClose={() => setConfirm(null)}
        onConfirm={() => {
          const target = confirm;
          if (target === null) return;
          void run(
            'theme',
            () => adminFetch<{ data: StoreTheme }>(target === 'publish' ? '/store/theme/publish' : `/store/theme/publications/${target.rollback.id}/rollback`, { method: 'POST' }),
            { success: target === 'publish' ? 'Theme published. Customers see it now.' : 'Theme restored.' },
          ).then((result) => {
            if (result) {
              state.setData(result);
              history.reload();
              setConfirm(null);
            }
          });
        }}
      >
        {confirm === 'publish' ? (
          <p>Your storefront changes for every customer straight away. You can go back to an earlier version from History.</p>
        ) : (
          <p>The version of {confirm ? formatDateTime(confirm.rollback.published_at) : ''} becomes the live theme, and your current draft is replaced by it.</p>
        )}
      </ConfirmDialog>
    </AdminPage>
  );
}
