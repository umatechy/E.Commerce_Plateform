import { KeyboardEvent, ReactNode, useId } from 'react';
import { Link } from '@inertiajs/react';
import Button, { FOCUS_RING } from './Button';
import Icon, { type IconName } from './Icon';
import { useAdminT } from '@/lib/adminI18n';

/** Page building blocks of the admin (Phase B31 design system): heading, cards, figures, tabs, and the load states of a page. */

export type Crumb = { label: string; href?: string };

export function Breadcrumbs({ items }: { items: Crumb[] }) {
  const t = useAdminT();
  if (items.length === 0) return null;

  return (
    <nav aria-label={t('Breadcrumb')} className="mb-2 text-xs font-medium text-slate-500">
      <ol className="flex flex-wrap items-center gap-1">
        {items.map((item, index) => (
          <li key={`${item.label}-${index}`} className="flex items-center gap-1">
            {index > 0 && <Icon name="chevronDown" size={12} className="-rotate-90 text-slate-300 rtl:rotate-90" />}
            {item.href ? (
              <Link href={item.href} className={`rounded transition-colors hover:text-brand-700 ${FOCUS_RING}`}>
                {t(item.label)}
              </Link>
            ) : (
              <span aria-current={index === items.length - 1 ? 'page' : undefined} className="text-slate-700">{t(item.label)}</span>
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
}

/**
 * The one <h1> of a page, with what the page is for and its main actions.
 * UI-26: `icon` shows the page in its section's colour (the --tone around
 * it), and `below` takes the section's tabs, under the heading.
 */
export function PageHeader({ title, description, actions, crumbs, icon, below }: { title: string; description?: ReactNode; actions?: ReactNode; crumbs?: Crumb[]; icon?: IconName; below?: ReactNode }) {
  const t = useAdminT();
  return (
    <div className="mb-6">
      {crumbs && <Breadcrumbs items={crumbs} />}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex min-w-0 items-start gap-3.5">
          {icon && (
            <span aria-hidden="true" className="tone-chip mt-0.5 hidden h-11 w-11 flex-none items-center justify-center rounded-2xl sm:flex">
              <Icon name={icon} size={22} />
            </span>
          )}
          <div className="min-w-0">
            <h1 className="text-2xl font-semibold tracking-tight text-slate-900">{t(title)}</h1>
            {description && <p className="mt-1 max-w-3xl text-sm text-slate-600">{typeof description === 'string' ? t(description) : description}</p>}
          </div>
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>
      {below}
    </div>
  );
}

/**
 * UI-26 — the other pages of this section, one click away: a row of links
 * under the page heading, the current one filled in the section colour.
 */
export function SectionTabs({ items, current }: { items: { href: string; label: string; icon?: IconName; disabled?: boolean; reason?: string }[]; current: string | null }) {
  const t = useAdminT();
  if (items.length < 2) return null;

  return (
    <nav aria-label={t('In this section')} className="-mx-1 mt-5 overflow-x-auto overflow-y-hidden px-1 pb-1 [scrollbar-width:thin]">
      <ul className="flex w-max gap-1 rounded-2xl border border-slate-200/80 bg-white p-1.5 shadow-sm shadow-slate-900/5">
        {items.map((item) => {
          const active = item.href === current;

          return (
            <li key={item.href}>
              {item.disabled ? (
                <span aria-disabled="true" title={item.reason} className="flex cursor-not-allowed items-center gap-2 whitespace-nowrap rounded-xl px-3.5 py-2 text-sm font-medium text-slate-400">
                  {item.icon && <Icon name={item.icon} size={16} />}
                  {t(item.label)}
                </span>
              ) : (
                <Link
                  href={item.href}
                  aria-current={active ? 'page' : undefined}
                  className={`flex items-center gap-2 whitespace-nowrap rounded-xl px-3.5 py-2 text-sm font-medium transition-colors motion-reduce:transition-none ${FOCUS_RING} ${active ? 'tone-solid shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'}`}
                >
                  {item.icon && <Icon name={item.icon} size={16} className={active ? '' : 'tone-text'} />}
                  {t(item.label)}
                </Link>
              )}
            </li>
          );
        })}
      </ul>
    </nav>
  );
}

/**
 * A block of one task on a page. UI-26: its heading stands out — a strip
 * and a light wash in the section's colour, and an optional icon — so it
 * is clear where one task ends and the next begins.
 */
export function Card({ title, description, actions, children, className = '', icon }: { title?: ReactNode; description?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string; icon?: IconName }) {
  const t = useAdminT();
  return (
    <section className={`rounded-2xl border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04),0_2px_6px_rgba(15,23,42,0.04)] ${className}`}>
      {(title || actions) && (
        <div className={`flex flex-wrap items-center justify-between gap-2 rounded-t-2xl border-b border-slate-100 px-5 py-4 ${title ? 'card-head' : ''}`}>
          <div className="flex min-w-0 items-center gap-3">
            {icon && (
              <span aria-hidden="true" className="tone-chip flex h-9 w-9 flex-none items-center justify-center rounded-xl">
                <Icon name={icon} size={18} />
              </span>
            )}
            <div className="min-w-0">
              {title && <h2 className="text-base font-semibold text-slate-900">{typeof title === 'string' ? t(title) : title}</h2>}
              {description && <p className="mt-0.5 text-[13px] text-slate-500">{typeof description === 'string' ? t(description) : description}</p>}
            </div>
          </div>
          {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
      )}
      <div className="p-5">{children}</div>
    </section>
  );
}

/**
 * One figure. `value` null means the server gave none: shown as "Not available", never as a made-up zero.
 * UI-2: an optional icon, and `trend` (a percentage the server sent) as a coloured arrow before the hint.
 */
export function StatCard({ label, value, hint, href, icon, trend }: { label: string; value: ReactNode | null; hint?: ReactNode; href?: string; icon?: IconName; trend?: number | null }) {
  const t = useAdminT();
  const hasTrend = trend !== undefined && trend !== null;
  const body = (
    <>
      {icon && (
        <span className="absolute end-4 top-4 flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-brand-100 transition-transform duration-200 group-hover:scale-110 motion-reduce:transform-none">
          <Icon name={icon} size={18} />
        </span>
      )}
      <p className={`text-xs font-medium uppercase tracking-wide text-slate-500 ${icon ? 'pe-10' : ''}`}>{t(label)}</p>
      <p className="mt-1 text-2xl font-semibold tracking-tight text-slate-900">{value ?? <span className="text-base font-normal text-slate-500">{t('Not available')}</span>}</p>
      {(hint || hasTrend) && (
        <p className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-slate-600">
          {hasTrend && (
            <span
              aria-hidden="true"
              className={`inline-flex items-center rounded-full px-1.5 py-0.5 font-semibold ${trend > 0 ? 'bg-emerald-50 text-emerald-700' : trend < 0 ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-600'}`}
            >
              {trend > 0 ? '↑' : trend < 0 ? '↓' : '→'} {Math.abs(trend)}%
            </span>
          )}
          {hint && <span>{typeof hint === 'string' ? t(hint) : hint}</span>}
        </p>
      )}
    </>
  );
  const box = 'group relative block rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]';

  return href ? (
    <Link href={href} className={`${box} transition duration-200 hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-lg hover:shadow-slate-900/5 motion-reduce:transform-none motion-reduce:transition-none ${FOCUS_RING}`}>
      {body}
    </Link>
  ) : (
    <div className={box}>{body}</div>
  );
}

export type TabItem = { id: string; label: string };

/** Tabs with the keyboard pattern readers expect: arrows move between tabs, Tab enters the panel. */
export function Tabs({ tabs, active, onChange, label }: { tabs: TabItem[]; active: string; onChange: (id: string) => void; label: string }) {
  const base = useId();

  function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    const index = tabs.findIndex((tab) => tab.id === active);
    const next = event.key === 'ArrowRight' ? index + 1 : event.key === 'ArrowLeft' ? index - 1 : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : null;
    if (next === null) return;
    event.preventDefault();
    const target = tabs[(next + tabs.length) % tabs.length];
    onChange(target.id);
    document.getElementById(`${base}-${target.id}`)?.focus();
  }

  return (
    <div role="tablist" aria-label={label} onKeyDown={onKeyDown} className="relative mb-4 flex gap-1 overflow-x-auto overflow-y-hidden border-b border-slate-200 [scrollbar-width:thin]">
      {tabs.map((tab) => (
        <button
          key={tab.id}
          id={`${base}-${tab.id}`}
          role="tab"
          type="button"
          aria-selected={tab.id === active}
          tabIndex={tab.id === active ? 0 : -1}
          onClick={() => onChange(tab.id)}
          className={`-mb-px whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition-colors motion-reduce:transition-none ${FOCUS_RING} ${
            tab.id === active ? 'border-brand-600 font-semibold text-brand-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-900'
          }`}
        >
          {tab.label}
        </button>
      ))}
    </div>
  );
}

export function Skeleton({ lines = 3 }: { lines?: number }) {
  const t = useAdminT();
  return (
    <div role="status" className="space-y-3 rounded-2xl border border-slate-200/80 bg-white p-5">
      <span className="sr-only">{t('Loading…')}</span>
      {Array.from({ length: lines }, (_, index) => (
        <span key={index} className="block h-4 animate-pulse rounded-md bg-slate-100 motion-reduce:animate-none" style={{ width: `${90 - index * 15}%` }} />
      ))}
    </div>
  );
}

/** An error with a way out. Used wherever a request can fail. */
export function ErrorPanel({ message, onRetry }: { message: string; onRetry?: () => void }) {
  const t = useAdminT();
  return (
    <div role="alert" className="flex gap-3 rounded-2xl border border-red-200 bg-red-50/80 p-4 text-sm text-red-800">
      <Icon name="alert" size={20} className="mt-0.5 text-red-500" />
      <div>
        <p>{message}</p>
        {onRetry && (
          <Button size="sm" className="mt-3" onClick={onRetry}>
            {t('Try again')}
          </Button>
        )}
      </div>
    </div>
  );
}

export function EmptyPanel({ title, description, action, icon = 'layers' }: { title: string; description?: ReactNode; action?: ReactNode; icon?: IconName }) {
  const t = useAdminT();
  return (
    <div className="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
      <span aria-hidden="true" className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 ring-8 ring-brand-50/50">
        <Icon name={icon} size={24} />
      </span>
      <p className="font-semibold text-slate-900">{t(title)}</p>
      {description && <p className="mx-auto mt-1 max-w-md text-sm text-slate-600">{typeof description === 'string' ? t(description) : description}</p>}
      {action && <div className="mt-4 flex justify-center">{action}</div>}
    </div>
  );
}

/**
 * The three states of something loaded from the API: loading, failed
 * (with "Try again"), ready. A 403 is explained as a permission matter,
 * not as a fault.
 */
export function QueryState<T>({
  state,
  children,
  lines,
}: {
  state: { data: T | null; loading: boolean; error: string | null; errorStatus?: number | null; errorCode?: string | null; reload: () => void };
  children: (data: T) => ReactNode;
  lines?: number;
}) {
  if (state.error) {
    return state.errorStatus === 403 ? <AccessNotice message={state.error} code={state.errorCode} /> : <ErrorPanel message={state.error} onRetry={state.reload} />;
  }
  if (state.data === null) return <Skeleton lines={lines} />;

  return <>{children(state.data)}</>;
}

/** Shown when the API refused the page's data: this is about permission or package, not a broken page. */
export function AccessNotice({ message, code }: { message?: string; code?: string | null }) {
  const t = useAdminT();
  // Two-step sign-in is the reason (Phase B29): say what to do about it.
  const mfa = code === 'mfa_enrollment_required' || code === 'mfa_verification_required';

  return (
    <div role="status" className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
      <p className="font-medium">{mfa ? t('Two-step sign-in is needed here.') : t('You cannot open this.')}</p>
      <p className="mt-1">{message ?? t('Your role does not include this page. Ask the store owner if you need it.')}</p>
      {code === 'mfa_enrollment_required' && (
        <p className="mt-2">
          <Link href="/security" className={`font-medium underline ${FOCUS_RING}`}>
            {t('Turn on two-step sign-in')}
          </Link>
        </p>
      )}
      {code === 'mfa_verification_required' && <p className="mt-2">{t('Log out and sign in again with your two-step code.')}</p>}
    </div>
  );
}

/** A package boundary, said plainly: what is not included, the current package, and where to change it. */
export function PackageNotice({ title, children, packageName, canSeeBilling }: { title: string; children?: ReactNode; packageName: string | null; canSeeBilling: boolean }) {
  return (
    <div role="status" className="rounded-2xl border border-brand-200 bg-brand-50 p-4 text-sm text-brand-900">
      <p className="font-medium">{title}</p>
      <p className="mt-1">
        {children}
        {packageName && <> Your store is on the {packageName} package.</>}
      </p>
      {canSeeBilling && (
        <p className="mt-2">
          <Link href="/billing" className={`font-medium underline ${FOCUS_RING}`}>
            See your package and billing
          </Link>
        </p>
      )}
    </div>
  );
}

/** A usage limit as the server reports it: current, limit, and a bar (with the numbers as text). */
export function UsageMeter({ label, current, limit }: { label: string; current: number; limit: number | null }) {
  const t = useAdminT();
  if (limit === null) {
    return (
      <p className="text-sm text-slate-600">
        {t(label)}: {t('{current} used, no limit on your package.', { current })}
      </p>
    );
  }
  const percent = limit > 0 ? Math.min(100, Math.round((current / limit) * 100)) : 100;
  const full = current >= limit;

  return (
    <div>
      <p className={`text-sm ${full ? 'font-medium text-red-700' : 'text-slate-600'}`}>
        {t(label)}: {t('{current} of {limit} used', { current, limit })}{full ? ` — ${t('limit reached')}` : ''}
      </p>
      <div className="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow={current} aria-valuemin={0} aria-valuemax={limit} aria-label={label}>
        <div className={`h-2 rounded-full transition-[width] duration-500 motion-reduce:transition-none ${full ? 'bg-red-600' : percent >= 80 ? 'bg-amber-500' : 'bg-brand-600'}`} style={{ width: `${percent}%` }} />
      </div>
    </div>
  );
}

/** Label and value pairs (a details list). */
export function Details({ items }: { items: { label: string; value: ReactNode }[] }) {
  const t = useAdminT();
  return (
    <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
      {items.map((item) => (
        <div key={item.label}>
          <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{t(item.label)}</dt>
          <dd className="mt-0.5 break-words text-slate-900">{item.value ?? '—'}</dd>
        </div>
      ))}
    </dl>
  );
}
