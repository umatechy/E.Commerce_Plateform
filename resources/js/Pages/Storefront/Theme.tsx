import { useEffect, useId, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { CheckboxField, FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import Badge, { humanize } from '@/Components/ui/Badge';
import { Card, ErrorPanel, PackageNotice, Skeleton, Tabs, AccessNotice } from '@/Components/ui/Page';
import ThemeLibrary from '@/Components/Theme/ThemeLibrary';
import ImageUploadField from '@/Components/ui/ImageUploadField';
import SectionEditor from '@/Components/Theme/SectionEditor';
import { useAccess, useAuth } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useAction, useUnsavedWarning } from '@/lib/useForm';
import { adminErrorMessage, adminFetch, wasCancelled } from '@/lib/adminApi';
import { toast } from '@/Components/ui/toast';
import { formatDateTime } from '@/lib/datetime';
import {
  ADVANCED_SECTIONS,
  BASIC_SECTIONS,
  canonicalSort,
  contrast,
  DENSITY,
  FONTS,
  LAYOUT_FIELDS,
  MOTION_INTENSITY,
  MOTION_PROFILES,
  RADIUS,
  SHADOW,
  type Config,
  type LibraryTheme,
  type Section,
  type StoreTheme,
} from '@/lib/theme';

/**
 * Module 17 "Theme, Branding & Design System" and Module 18 "Animation"
 * (/api/v1/store/theme, /api/v1/store/themes).
 *
 * The theme is configuration, not code. This page edits the DRAFT;
 * customers keep seeing the published version until "Publish". Every value
 * is validated by the server (hex colours, https addresses, known section
 * types, the package); an invalid one comes back as its message.
 *
 * Phase B36: a library of themes to choose from, layout and animation
 * settings, and more home page sections. What the package does not include
 * is shown but disabled, with the package that has it; the server refuses
 * it as well. Custom CSS belongs to `theme.custom_css`.
 */
type Publication = { id: number; published_at: string };

const COLORS: [string, string][] = [
  ['primary', 'Primary (buttons)'], ['secondary', 'Secondary'], ['accent', 'Accent (links)'], ['background', 'Background'], ['surface', 'Surface (panels)'], ['text', 'Text'],
  ['muted', 'Muted text'], ['border', 'Borders'], ['success', 'Success'], ['warning', 'Warning'], ['error', 'Error / sale'],
];
const SOCIAL = ['facebook', 'instagram', 'twitter', 'tiktok', 'youtube'];

function normalize(config: Config | undefined, canReorder: boolean): Config {
  const sections = [...(config?.sections ?? [])].sort((a, b) => a.position - b.position).map((section) => ({ ...section, config: { ...(section.config ?? {}) } }));

  return {
    theme: config?.theme,
    tokens: { ...(config?.tokens ?? {}) },
    layout: config?.layout ? { ...config.layout } : undefined,
    motion: config?.motion ? { ...config.motion } : undefined,
    branding: { ...(config?.branding ?? {}) },
    sections: canReorder ? sections : canonicalSort(sections),
  };
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
    ...(config.theme ? { theme: config.theme } : {}),
    tokens,
    ...(config.layout && Object.keys(config.layout).length > 0 ? { layout: config.layout } : {}),
    ...(config.motion && Object.keys(config.motion).length > 0 ? { motion: config.motion } : {}),
    branding,
    sections: config.sections.map((section, index) => ({
      type: section.type,
      position: index,
      is_visible: section.is_visible,
      config: Object.fromEntries(
        Object.entries(section.config)
          .map(([key, value]) => [key, Array.isArray(value) ? value.map((item) => Object.fromEntries(Object.entries(item).filter(([, v]) => v !== ''))).filter((item) => Object.keys(item).length > 0) : value])
          .filter(([, value]) => value !== '' && value !== null),
      ),
    })),
  };
}

function ColorField({ label, value, placeholder, onChange, disabled }: { label: string; value: string; placeholder: string; onChange: (value: string) => void; disabled: boolean }) {
  const id = useId();
  const valid = /^#[0-9a-fA-F]{6}$/.test(value);

  return (
    <div>
      <label htmlFor={id} className="block text-sm font-medium text-slate-700">{label}</label>
      <div className="mt-1 flex items-center gap-2">
        <input type="color" aria-label={`${label} colour picker`} value={valid ? value : /^#[0-9a-fA-F]{6}$/.test(placeholder) ? placeholder : '#ffffff'} disabled={disabled} onChange={(e) => onChange(e.target.value.toUpperCase())} className={`h-9 w-10 rounded border border-slate-300 bg-white p-0.5 ${FOCUS_RING}`} />
        <input id={id} value={value} disabled={disabled} onChange={(e) => onChange(e.target.value)} placeholder={placeholder ? `Theme: ${placeholder}` : 'Not set'} maxLength={7} className={`block w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm ${FOCUS_RING}`} />
      </div>
    </div>
  );
}

export default function Theme() {
  const access = useAccess();
  const auth = useAuth();
  const canEdit = access.can('theme.manage');
  const canPublish = access.can('theme.publish');
  const has = (feature: string) => access.feature(feature) !== false;
  const cssIncluded = has('theme.custom_css');
  const canReorder = has('homepage.reorder');
  const advancedSections = has('homepage.advanced_sections');
  const state = useApi<{ data: StoreTheme }>('/store/theme');
  const library = useApi<{ data: LibraryTheme[] }>('/store/themes');
  const history = useApi<{ data: Publication[] }>('/store/theme/publications');
  const [config, setConfig] = useState<Config | null>(null);
  const [css, setCss] = useState('');
  const [dirty, setDirty] = useState(false);
  const [tab, setTab] = useState('themes');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [confirm, setConfirm] = useState<'publish' | { rollback: Publication } | null>(null);
  const [adding, setAdding] = useState('hero');
  const { busy, run } = useAction();
  useUnsavedWarning(dirty);

  const theme = state.data?.data ?? null;

  useEffect(() => {
    if (theme) {
      setConfig(normalize(theme.draft_config, canReorder));
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

  async function chooseTheme(choice: LibraryTheme) {
    const saved = await run(`select:${choice.key}`, () => adminFetch<{ data: StoreTheme }>('/store/theme/select', { method: 'POST', body: { theme: choice.key } }), {
      success: `"${choice.name}" is in your draft. Preview it, then publish.`,
    });
    if (saved) {
      state.setData(saved);
      library.reload();
    }
  }

  if (state.error) {
    return <AdminPage title="Theme">{state.errorStatus === 403 ? <AccessNotice message={state.error} /> : <ErrorPanel message={state.error} onRetry={state.reload} />}</AdminPage>;
  }
  if (theme === null || config === null) {
    return <AdminPage title="Theme"><Skeleton lines={6} /></AdminPage>;
  }

  const unpublished = JSON.stringify(theme.draft_config) !== JSON.stringify(theme.published_config);
  const current = library.data?.data.find((item) => item.key === (config.theme ?? theme.theme)) ?? null;
  const defaults = { tokens: current?.tokens ?? {}, layout: current?.layout ?? {}, motion: current?.motion ?? {} };
  const token = (key: string) => config.tokens[key] ?? '';
  const effective = (key: string) => config.tokens[key] || defaults.tokens[key] || '';
  const layoutValue = (key: string) => String(config.layout?.[key] ?? defaults.layout[key] ?? '');
  const motionValue = (key: string) => config.motion?.[key] ?? defaults.motion[key];
  const setLayout = (key: string, value: string | boolean) => change({ ...config, layout: { ...(config.layout ?? {}), [key]: key === 'grid_columns' ? Number(value) : value } });
  const setMotion = (key: string, value: string | boolean) => change({ ...config, motion: { ...(config.motion ?? {}), [key]: value } });
  const locked = (feature?: string) => (feature ? !has(feature) : false);
  const buttonContrast = contrast(effective('primary'), '#FFFFFF');
  const textContrast = contrast(effective('text'), effective('background'));

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
  const addSection = () => {
    const sections = [...config.sections, { type: adding, position: config.sections.length, is_visible: true, config: {} }];
    change({ ...config, sections: canReorder ? sections : canonicalSort(sections) });
  };

  return (
    <AdminPage
      title="Theme"
      description={`${current?.name ?? humanize(theme.theme)} theme. You edit a draft; customers see it only after you publish.`}
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

      <Tabs
        label="Theme settings"
        active={tab}
        onChange={setTab}
        tabs={[
          { id: 'themes', label: 'Themes' },
          { id: 'look', label: 'Colours and type' },
          { id: 'layout', label: 'Layout' },
          { id: 'motion', label: 'Animation' },
          { id: 'brand', label: 'Branding' },
          { id: 'sections', label: 'Home page' },
          { id: 'css', label: 'Custom CSS' },
          { id: 'history', label: 'History' },
        ]}
      />

      <div role="tabpanel" hidden={tab !== 'themes'} className="space-y-4">
        <p className="text-sm text-slate-600">Choosing a theme puts it in your draft with its own colours, fonts, layout and animation. Your logo, texts and home page sections stay. Nothing changes for customers until you publish.</p>
        <ThemeLibrary themes={library.data?.data ?? null} error={library.error} onRetry={library.reload} canEdit={canEdit} dirty={dirty} busyKey={busy?.startsWith('select:') ? busy.slice(7) : null} onChoose={(choice) => void chooseTheme(choice)} />
      </div>

      <div role="tabpanel" hidden={tab !== 'look'} className="space-y-4">
        <Card title="Colours" description="Six-digit hex colours, such as #1A2B3C. Leave one empty to use the theme’s own (shown in grey).">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {COLORS.map(([key, label]) => (
              <ColorField key={key} label={label} value={token(key)} placeholder={defaults.tokens[key] ?? ''} disabled={!canEdit} onChange={(value) => change({ ...config, tokens: { ...config.tokens, [key]: value } })} />
            ))}
          </div>
          {((buttonContrast !== null && buttonContrast < 4.5) || (textContrast !== null && textContrast < 4.5)) && (
            <p role="status" className="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-900">
              Some text may be hard to read:
              {buttonContrast !== null && buttonContrast < 4.5 && ` white text on the primary colour has a contrast of ${buttonContrast.toFixed(1)}:1;`}
              {textContrast !== null && textContrast < 4.5 && ` the text colour on the background has ${textContrast.toFixed(1)}:1;`} 4.5:1 or more is recommended.
            </p>
          )}
        </Card>
        <Card title="Type and shape">
          <div className="grid gap-4 sm:grid-cols-2">
            <SelectField label="Heading font" value={token('heading_font')} disabled={!canEdit} onChange={(value) => change({ ...config, tokens: { ...config.tokens, heading_font: value } })} placeholder={`Theme default (${defaults.tokens.heading_font ?? '—'})`} options={FONTS.map((font) => ({ value: font, label: font }))} />
            <SelectField label="Body font" value={token('font_family')} disabled={!canEdit} onChange={(value) => change({ ...config, tokens: { ...config.tokens, font_family: value } })} placeholder={`Theme default (${defaults.tokens.font_family ?? '—'})`} options={FONTS.map((font) => ({ value: font, label: font }))} />
            <SelectField label="Corner rounding" value={token('radius')} disabled={!canEdit} onChange={(value) => change({ ...config, tokens: { ...config.tokens, radius: value } })} placeholder="Theme default" options={RADIUS} />
            <SelectField label="Shadows" value={token('shadow')} disabled={!canEdit} onChange={(value) => change({ ...config, tokens: { ...config.tokens, shadow: value } })} placeholder="Theme default" options={SHADOW} />
            <SelectField label="Spacing" value={token('density')} disabled={!canEdit} onChange={(value) => change({ ...config, tokens: { ...config.tokens, density: value } })} placeholder="Theme default" options={DENSITY} />
          </div>
          <p className="mt-3 text-sm text-slate-600">Fonts are served from your store’s own address; no font service sees your customers.</p>
        </Card>
      </div>

      <div role="tabpanel" hidden={tab !== 'layout'} className="space-y-4">
        {!has('layout.advanced') && (
          <PackageNotice title="Premium layouts are in the Premium package" packageName={access.packageName} canSeeBilling={access.can('billing.view')}>
            The centred and coloured headers, split and full-width heroes, and elevated and overlay product cards are marked “Premium”.
          </PackageNotice>
        )}
        <Card title="Layout" description="How your storefront is arranged. Leave a field on the theme default to follow the theme.">
          <div className="grid gap-4 sm:grid-cols-2">
            {LAYOUT_FIELDS.map((field) => (
              <SelectField
                key={field.key}
                label={field.label}
                value={config.layout?.[field.key] !== undefined ? String(config.layout[field.key]) : ''}
                disabled={!canEdit}
                placeholder={`Theme default (${field.choices.find((choice) => choice.value === String(defaults.layout[field.key] ?? ''))?.label.split(' — ')[0] ?? '—'})`}
                onChange={(value) => {
                  if (value === '') {
                    const { [field.key]: _removed, ...rest } = config.layout ?? {};
                    void _removed;
                    change({ ...config, layout: rest });
                  } else {
                    setLayout(field.key, value);
                  }
                }}
                options={field.choices.map((choice) => ({ value: choice.value, label: locked(choice.feature) ? `${choice.label} (Premium)` : choice.label, disabled: locked(choice.feature) }))}
              />
            ))}
          </div>
          <div className="mt-4">
            <CheckboxField label="Keep the header at the top while scrolling" checked={layoutValue('sticky_header') === 'true'} disabled={!canEdit} onChange={(value) => setLayout('sticky_header', value)} />
          </div>
        </Card>
      </div>

      <div role="tabpanel" hidden={tab !== 'motion'} className="space-y-4">
        {!has('animation.premium') && (
          <PackageNotice title={has('animation.advanced') ? 'Premium and Playful animation are in the Premium package' : 'More animation comes with the Business and Premium packages'} packageName={access.packageName} canSeeBilling={access.can('billing.view')}>
            Your package includes the profiles that are not marked.
          </PackageNotice>
        )}
        <Card title="Animation profile" description="How your storefront moves. Customers who ask their device for reduced motion always get a still storefront.">
          <fieldset>
            <legend className="sr-only">Animation profile</legend>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {MOTION_PROFILES.map((profile) => {
                const off = locked(profile.feature);
                const chosen = motionValue('profile') === profile.value;

                return (
                  <label key={profile.value} className={`flex cursor-pointer gap-3 rounded-md border p-3 text-sm ${chosen ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200'} ${off ? 'cursor-not-allowed opacity-60' : ''}`}>
                    <input type="radio" name="motion-profile" value={profile.value} checked={chosen} disabled={!canEdit || off} onChange={() => setMotion('profile', profile.value)} className="mt-0.5 h-4 w-4 text-indigo-600 focus:ring-indigo-500" />
                    <span>
                      <span className="font-medium text-slate-900">
                        {profile.label}
                        {off ? ' (not in your package)' : ''}
                      </span>
                      <span className="block text-slate-600">{profile.description}</span>
                    </span>
                  </label>
                );
              })}
            </div>
          </fieldset>
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <SelectField label="Intensity" value={String(motionValue('intensity') ?? '')} disabled={!canEdit} onChange={(value) => setMotion('intensity', value)} options={MOTION_INTENSITY.map((choice) => ({ value: choice.value, label: locked(choice.feature) ? `${choice.label} (Premium)` : choice.label, disabled: locked(choice.feature) }))} />
          </div>
          <div className="mt-4 space-y-2">
            <CheckboxField label="Sections and products ease in as customers scroll" checked={motionValue('reveal_on_scroll') === true} disabled={!canEdit || locked('animation.advanced')} onChange={(value) => setMotion('reveal_on_scroll', value)} hint={locked('animation.advanced') ? 'Business and Premium packages.' : undefined} />
            <CheckboxField label="Cards lift and images zoom on hover" checked={motionValue('hover_effects') !== false} disabled={!canEdit} onChange={(value) => setMotion('hover_effects', value)} />
          </div>
          {canEdit && (config.motion || config.layout || Object.keys(config.tokens).length > 0) && (
            <div className="mt-4 border-t border-slate-200 pt-4">
              <Button size="sm" variant="ghost" onClick={() => change({ ...config, tokens: {}, layout: undefined, motion: undefined })}>
                Use the theme’s colours, type, layout and animation again
              </Button>
            </div>
          )}
        </Card>
      </div>

      <div role="tabpanel" hidden={tab !== 'brand'} className="space-y-4">
        <Card title="Brand" description="Choose a JPG or PNG from your computer, or paste an address that starts with https://.">
          <div className="grid gap-4 sm:grid-cols-2">
            <ImageUploadField label="Logo" purpose="logo" disabled={!canEdit} value={config.branding.logo_url ?? ''} onChange={(value) => change({ ...config, branding: { ...config.branding, logo_url: value } })} hint="Shown in the header instead of the store name. A wide image about 400 × 120 pixels works well. Up to 5 MB." />
            <ImageUploadField label="Favicon" purpose="favicon" disabled={!canEdit} value={config.branding.favicon_url ?? ''} onChange={(value) => change({ ...config, branding: { ...config.branding, favicon_url: value } })} hint="The small icon in the browser tab. A square image, at least 32 × 32 pixels." />
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
        {(!canReorder || !advancedSections) && (
          <PackageNotice title={!advancedSections ? 'More sections come with the Business and Premium packages' : 'Changing the order of sections is in the Premium package'} packageName={access.packageName} canSeeBilling={access.can('billing.view')}>
            {!advancedSections ? 'Trust badges, best sellers, sale, brands, testimonials, questions and a text block. ' : ''}
            {!canReorder ? 'On your package sections appear in a fixed order.' : ''}
          </PackageNotice>
        )}
        <Card title="Home page sections" description={canReorder ? 'Shown from top to bottom in this order.' : 'Shown from top to bottom in the fixed order.'}>
          <ul className="space-y-3">
            {config.sections.map((item, index) => (
              <SectionEditor
                key={`${item.type}-${index}`}
                section={item}
                index={index}
                count={config.sections.length}
                canEdit={canEdit}
                canReorder={canReorder}
                onChange={(patch) => section(index, patch)}
                onMove={(by) => moveSection(index, by)}
                onRemove={() => change({ ...config, sections: config.sections.filter((_, i) => i !== index) })}
              />
            ))}
          </ul>
          {canEdit && (
            <div className="mt-4 flex flex-wrap items-end gap-3 border-t border-slate-200 pt-4">
              <div className="w-64">
                <SelectField
                  label="Add a section"
                  value={adding}
                  onChange={setAdding}
                  options={[...BASIC_SECTIONS.map((type) => ({ value: type, label: humanize(type) })), ...ADVANCED_SECTIONS.map((type) => ({ value: type, label: advancedSections ? humanize(type) : `${humanize(type)} (Business)`, disabled: !advancedSections }))]}
                />
              </div>
              <Button onClick={addSection}>Add</Button>
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
        <Card title="Custom CSS" description="For fine adjustments. The server removes scripts, imports and anything that is not CSS. A theme update may not match custom CSS.">
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
              library.reload();
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
