import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import { ErrorPanel, Skeleton } from '@/Components/ui/Page';
import { TIER_LABEL, type LibraryTheme } from '@/lib/theme';

/**
 * Module 17 §18, §50 "Theme Library" (Phase B36): every theme with a small
 * drawing in its own colours and fonts, the packages it is in, and whether
 * it is live or in the draft. A theme outside the package is shown, locked,
 * with the package it needs (Module 17 §50 "package limitations").
 */
export default function ThemeLibrary({
  themes,
  error,
  onRetry,
  canEdit,
  dirty,
  busyKey,
  onChoose,
}: {
  themes: LibraryTheme[] | null;
  error: string | null;
  onRetry: () => void;
  canEdit: boolean;
  dirty: boolean;
  busyKey: string | null;
  onChoose: (theme: LibraryTheme) => void;
}) {
  if (error) return <ErrorPanel message={error} onRetry={onRetry} />;
  if (themes === null) return <Skeleton lines={4} />;

  return (
    <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      {themes.map((theme) => (
        <li key={theme.key} className={`flex flex-col overflow-hidden rounded-lg border ${theme.in_draft ? 'border-indigo-500 ring-2 ring-indigo-200' : 'border-slate-200'} bg-white`}>
          <Swatch theme={theme} />
          <div className="flex flex-1 flex-col gap-2 p-4">
            <div className="flex flex-wrap items-center gap-2">
              <h3 className="text-base font-semibold text-slate-900">{theme.name}</h3>
              {theme.published && <Badge tone="green">Live</Badge>}
              {theme.in_draft && !theme.published && <Badge tone="blue">In your draft</Badge>}
              {!theme.included && <Badge tone="amber">{TIER_LABEL[theme.tier]}</Badge>}
            </div>
            {theme.description && <p className="text-sm text-slate-600">{theme.description}</p>}
            <p className="text-xs text-slate-500">
              {theme.tokens.heading_font} · {theme.tokens.font_family} · motion: {String(theme.motion.profile)} · version {theme.version}
            </p>
            <div className="mt-auto pt-2">
              {!theme.included ? (
                <p className="text-sm text-amber-800">Needs the {TIER_LABEL[theme.tier]} package.</p>
              ) : canEdit ? (
                <Button
                  size="sm"
                  variant={theme.in_draft ? 'secondary' : 'primary'}
                  disabled={dirty}
                  busy={busyKey === theme.key}
                  busyLabel="Choosing…"
                  title={dirty ? 'Save or discard your changes first' : undefined}
                  onClick={() => onChoose(theme)}
                >
                  {theme.in_draft ? 'Reset to this theme’s defaults' : 'Use this theme'}
                  <span className="sr-only"> ({theme.name})</span>
                </Button>
              ) : null}
            </div>
          </div>
        </li>
      ))}
    </ul>
  );
}

/** A miniature storefront in the theme's own colours, fonts and header style — drawn, not a screenshot. */
function Swatch({ theme }: { theme: LibraryTheme }) {
  const t = theme.tokens;
  const radius = { none: '0', sm: '3px', md: '6px', lg: '10px' }[t.radius] ?? '6px';
  const heading = t.heading_font === 'system-ui' ? 'system-ui' : `"${t.heading_font}", system-ui`;
  const split = theme.layout.header_style === 'split';
  const centered = theme.layout.header_style === 'centered';

  return (
    <div aria-hidden="true" className="h-36 p-3" style={{ background: t.background, color: t.text, fontFamily: heading }}>
      <div className={`flex items-center gap-2 px-2 py-1.5 ${centered ? 'justify-center' : 'justify-between'}`} style={{ background: split ? t.primary : 'transparent', color: split ? '#fff' : t.text, borderRadius: radius, borderBottom: split ? 'none' : `1px solid ${t.border}` }}>
        <span className="text-sm font-bold">{theme.name}</span>
        {!centered && <span className="h-2 w-10 rounded-full" style={{ background: split ? 'rgba(255,255,255,.5)' : t.border }} />}
      </div>
      <div className="mt-2 grid grid-cols-3 gap-2">
        {[0, 1, 2].map((i) => (
          <div key={i} className="overflow-hidden" style={{ borderRadius: radius, border: theme.layout.product_card === 'standard' ? `1px solid ${t.border}` : 'none', background: t.surface, boxShadow: theme.layout.product_card === 'elevated' ? '0 2px 6px rgba(0,0,0,.12)' : 'none' }}>
            <div className="h-10" style={{ background: i === 1 ? t.accent : t.secondary, opacity: 0.35 }} />
            <div className="space-y-1 p-1.5">
              <div className="h-1.5 w-3/4 rounded" style={{ background: t.text, opacity: 0.6 }} />
              <div className="h-1.5 w-1/3 rounded" style={{ background: t.primary }} />
            </div>
          </div>
        ))}
      </div>
      <div className="mt-2 inline-block px-3 py-1 text-[10px] font-semibold text-white" style={{ background: t.primary, borderRadius: radius }}>
        Shop now
      </div>
    </div>
  );
}
