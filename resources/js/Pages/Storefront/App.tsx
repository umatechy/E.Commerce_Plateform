import { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import { Card, ErrorPanel, PackageNotice, Skeleton } from '@/Components/ui/Page';
import Button, { FOCUS_RING } from '@/Components/ui/Button';
import { CheckboxField, FormError, SelectField, TextField } from '@/Components/ui/Form';
import { adminErrorMessage, adminFetch, wasCancelled } from '@/lib/adminApi';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useForm, useUnsavedWarning } from '@/lib/useForm';
import { toast } from '@/Components/ui/toast';

/**
 * Phase B55 (gap G16; Module 25 §5–8, §55–59, §76, §86) — Storefront →
 * Mobile app. A store's customers can add the shop to their phone's home
 * screen and open it like an app. This page sets its name, colours, icon,
 * install offer and phone navigation, shows what it looks like, says what
 * still stands in the way of installing it, and how it is used.
 */
type Settings = {
  enabled: boolean;
  name: string | null;
  short_name: string | null;
  theme_color: string | null;
  background_color: string | null;
  display: string;
  install_prompt: boolean;
  mobile_nav: string;
  push: boolean;
};

type Data = {
  can_manage: boolean;
  included: boolean;
  settings: Settings;
  icon: string | null;
  colors: { theme: string; background: string };
  manifest: { name: string; short_name: string; start_url: string };
  push_available: boolean;
  checks: { key: string; ok: boolean; message: string }[];
  stats: Record<string, number>;
  push_devices: number;
};

const DISPLAY = [
  { value: 'standalone', label: 'Like an app (no browser bar)' },
  { value: 'minimal-ui', label: 'Like an app, with small browser buttons' },
  { value: 'browser', label: 'In the browser' },
];

const NAV = [
  { value: 'installed', label: 'In the installed app only' },
  { value: 'always', label: 'On every phone' },
  { value: 'off', label: 'Never' },
];

type Form = { enabled: boolean; name: string; short_name: string; theme_color: string; background_color: string; display: string; install_prompt: boolean; mobile_nav: string; push: boolean };

function toForm(s: Settings): Form {
  return {
    enabled: s.enabled, name: s.name ?? '', short_name: s.short_name ?? '', theme_color: s.theme_color ?? '', background_color: s.background_color ?? '',
    display: s.display, install_prompt: s.install_prompt, mobile_nav: s.mobile_nav, push: s.push,
  };
}

export default function MobileApp() {
  const access = useAccess();
  const state = useApi<{ data: Data }>('/pwa/settings');
  const form = useForm<Form>({ enabled: true, name: '', short_name: '', theme_color: '', background_color: '', display: 'standalone', install_prompt: true, mobile_nav: 'installed', push: false });
  const loaded = state.data?.data;
  useUnsavedWarning(form.dirty);

  useEffect(() => {
    if (loaded) form.reset(toForm(loaded.settings));
    // Only when the server's values arrive.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loaded]);

  if (state.error) return <AdminPage title="Mobile app"><ErrorPanel message={state.error} onRetry={state.reload} /></AdminPage>;
  if (!loaded) return <AdminPage title="Mobile app"><Skeleton lines={8} /></AdminPage>;

  const v = form.values;
  const canManage = loaded.can_manage;
  const name = v.name.trim() || loaded.manifest.name;
  const shortName = (v.short_name.trim() || name).slice(0, 12);
  const theme = /^#[0-9A-Fa-f]{6}$/.test(v.theme_color) ? v.theme_color : loaded.colors.theme;
  const background = /^#[0-9A-Fa-f]{6}$/.test(v.background_color) ? v.background_color : loaded.colors.background;
  const ready = loaded.checks.every((c) => c.ok);

  function save() {
    form
      .submit(
        (values) => adminFetch('/pwa/settings', { method: 'PUT', body: { ...values, name: values.name || null, short_name: values.short_name || null, theme_color: values.theme_color || null, background_color: values.background_color || null } }),
        'Saved. Customers get the new app details the next time they open your store.',
      )
      .then((result) => result !== undefined && state.reload());
  }

  return (
    <AdminPage
      title="Mobile app"
      description="Customers can add your shop to their phone’s home screen and open it like an app — no app store, no download. It works on Android, iPhone and computers."
    >
      {!loaded.included ? (
        <PackageNotice title="The mobile app is not in your package" packageName={access.packageName} canSeeBilling={access.can('billing.view')} />
      ) : (
        <div className="grid gap-6 lg:grid-cols-3">
          <div className="space-y-6 lg:col-span-2">
            <Card title={ready ? 'Customers can install your app' : 'Before customers can install it'} description={ready ? 'Everything is in place.' : 'These still need attention.'}>
              <ul className="space-y-2 text-sm">
                {loaded.checks.map((c) => (
                  <li key={c.key} className="flex gap-2">
                    <span aria-hidden="true" className={c.ok ? 'text-green-700' : 'text-amber-700'}>{c.ok ? '✓' : '○'}</span>
                    <span className="sr-only">{c.ok ? 'Done: ' : 'To do: '}</span>
                    <span className={c.ok ? 'text-slate-700' : 'text-slate-900'}>{c.message}</span>
                  </li>
                ))}
              </ul>
            </Card>

            <Card title="App details">
              <form
                className="space-y-4"
                noValidate
                onSubmit={(e) => {
                  e.preventDefault();
                  save();
                }}
              >
                <FormError message={form.formError} />
                <CheckboxField label="Offer my shop as an app" checked={v.enabled} disabled={!canManage} onChange={(x) => form.set('enabled', x)} hint="Off: phones see a normal website, and an app already added stops working offline." />
                <div className="grid gap-4 sm:grid-cols-2">
                  <TextField label="App name" optional value={v.name} disabled={!canManage} onChange={(x) => form.set('name', x)} maxLength={45} placeholder={loaded.manifest.name} hint="Empty: your store name." error={form.errors.name} />
                  <TextField label="Name under the icon" optional value={v.short_name} disabled={!canManage} onChange={(x) => form.set('short_name', x)} maxLength={12} placeholder={shortName} hint="Up to 12 letters; longer names are cut on the home screen." error={form.errors.short_name} />
                  <ColorField label="Top bar colour" value={v.theme_color} fallback={loaded.colors.theme} disabled={!canManage} onChange={(x) => form.set('theme_color', x)} error={form.errors.theme_color} />
                  <ColorField label="Opening screen colour" value={v.background_color} fallback={loaded.colors.background} disabled={!canManage} onChange={(x) => form.set('background_color', x)} error={form.errors.background_color} />
                  <SelectField label="How it opens" value={v.display} disabled={!canManage} onChange={(x) => form.set('display', x)} options={DISPLAY} />
                  <SelectField label="App buttons at the bottom of phones" value={v.mobile_nav} disabled={!canManage} onChange={(x) => form.set('mobile_nav', x)} options={NAV} hint="Home, Shop, Search, Cart and Account, one tap away." />
                </div>
                <CheckboxField label="Suggest installing to returning visitors" checked={v.install_prompt} disabled={!canManage} onChange={(x) => form.set('install_prompt', x)} hint="A small offer after a visitor has looked at a few pages; once dismissed, it does not come back for a month." />
                <p className="rounded-md bg-slate-50 p-3 text-sm text-slate-600">
                  <strong>Notifications on the phone:</strong> not available yet. They come with a later update; your customers are not asked until then.
                </p>
                {canManage && (
                  <div className="flex justify-end">
                    <Button type="submit" variant="primary" busy={form.busy} disabled={!form.dirty}>
                      Save
                    </Button>
                  </div>
                )}
              </form>
            </Card>

            <IconCard icon={loaded.icon} canManage={canManage} onSaved={state.reload} />
          </div>

          <div className="space-y-6">
            <Card title="Preview">
              <p className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-500">On the home screen</p>
              <div className="flex justify-center rounded-lg bg-gradient-to-b from-slate-700 to-slate-900 p-6">
                <div className="flex w-20 flex-col items-center gap-1 text-center">
                  <AppIcon icon={loaded.icon} name={name} color={theme} size="h-14 w-14" />
                  <span className="w-full truncate text-xs text-white">{shortName}</span>
                </div>
              </div>
              <p className="mb-2 mt-4 text-xs font-medium uppercase tracking-wide text-slate-500">While it opens</p>
              <div className="flex h-48 flex-col items-center justify-center gap-3 rounded-lg border border-slate-200" style={{ background }}>
                <AppIcon icon={loaded.icon} name={name} color={theme} size="h-16 w-16" />
                <span className="text-sm font-medium" style={{ color: readableOn(background) }}>{name}</span>
              </div>
              <div className="mt-2 h-2 rounded" style={{ background: theme }} aria-hidden="true" />
              <p className="mt-1 text-xs text-slate-500">The bar above is the top bar colour.</p>
            </Card>

            <Card title="Last 30 days">
              <dl className="grid grid-cols-2 gap-3 text-sm">
                <Stat label="Installed" value={loaded.stats.installed ?? 0} />
                <Stat label="Opened from the home screen" value={loaded.stats.launched ?? 0} />
                <Stat label="Used offline" value={loaded.stats.offline_view ?? 0} />
              </dl>
              <p className="mt-3 text-xs text-slate-500">Counted per day, without anything that identifies a phone. iPhones do not report installs.</p>
            </Card>

            <Card title="Learn more">
              <Link href="/help" className={`rounded text-sm text-indigo-700 hover:underline ${FOCUS_RING}`}>
                Help center
              </Link>
            </Card>
          </div>
        </div>
      )}
    </AdminPage>
  );
}

function ColorField({ label, value, fallback, disabled, onChange, error }: { label: string; value: string; fallback: string; disabled: boolean; onChange: (v: string) => void; error?: string }) {
  return (
    <div className="flex items-end gap-2">
      <TextField label={label} optional value={value} disabled={disabled} onChange={onChange} placeholder={fallback} hint="Like #1A2B3C. Empty: from your theme." error={error} className="flex-1" />
      <input
        type="color"
        aria-label={`${label}: pick`}
        value={/^#[0-9A-Fa-f]{6}$/.test(value) ? value : fallback}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value.toUpperCase())}
        className="mb-6 h-10 w-12 cursor-pointer rounded border border-slate-300"
      />
    </div>
  );
}

function AppIcon({ icon, name, color, size }: { icon: string | null; name: string; color: string; size: string }) {
  return icon ? (
    <img src={icon} alt="" className={`${size} rounded-2xl object-cover shadow`} />
  ) : (
    <span aria-hidden="true" className={`${size} flex items-center justify-center rounded-2xl text-2xl font-bold text-white shadow`} style={{ background: color }}>
      {name.slice(0, 1).toUpperCase()}
    </span>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-md bg-slate-50 p-3">
      <dt className="text-xs text-slate-600">{label}</dt>
      <dd className="text-xl font-semibold text-slate-900">{value}</dd>
    </div>
  );
}

function IconCard({ icon, canManage, onSaved }: { icon: string | null; canManage: boolean; onSaved: () => void }) {
  const picker = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function upload(file: File | undefined) {
    if (!file) return;
    setBusy(true);
    setError(null);
    try {
      const body = new FormData();
      body.append('file', file);
      await adminFetch('/pwa/icon', { method: 'POST', body, timeoutMs: 60000 });
      toast.success('App icon saved.');
      onSaved();
    } catch (e) {
      if (!wasCancelled(e)) setError(adminErrorMessage(e));
    } finally {
      setBusy(false);
      if (picker.current) picker.current.value = '';
    }
  }

  return (
    <Card title="App icon" description="A square JPG or PNG, at least 512 × 512 pixels. Keep the important part in the middle: some phones round the corners.">
      <div className="flex flex-wrap items-center gap-4">
        <div className="flex h-24 w-24 items-center justify-center overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
          {icon ? <img src={icon} alt="Current app icon" className="h-full w-full object-cover" /> : <span className="text-xs text-slate-400">No icon</span>}
        </div>
        {canManage && (
          <div>
            <input ref={picker} type="file" accept="image/jpeg,image/png" className="sr-only" id="app-icon-file" onChange={(e) => upload(e.target.files?.[0])} />
            <Button busy={busy} busyLabel="Uploading…" onClick={() => picker.current?.click()}>
              {icon ? 'Change icon' : 'Choose icon'}
            </Button>
            <p className="mt-1 text-xs text-slate-500">Phones need an icon before they offer to install the app.</p>
          </div>
        )}
      </div>
      {error && (
        <p role="alert" className="mt-2 text-sm text-red-700">
          {error}
        </p>
      )}
    </Card>
  );
}

/** Dark or light text, whichever reads on the opening screen colour. */
function readableOn(hex: string): string {
  const n = parseInt(hex.slice(1), 16);
  const [r, g, b] = [(n >> 16) & 255, (n >> 8) & 255, n & 255];

  return r * 0.299 + g * 0.587 + b * 0.114 > 150 ? '#111827' : '#FFFFFF';
}
