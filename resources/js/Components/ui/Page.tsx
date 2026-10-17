import { KeyboardEvent, ReactNode, useId } from 'react';
import { Link } from '@inertiajs/react';
import Button, { FOCUS_RING } from './Button';
import Icon, { type IconName } from './Icon';

/** Page building blocks of the admin (Phase B31 design system): heading, cards, figures, tabs, and the load states of a page. */

export type Crumb = { label: string; href?: string };

export function Breadcrumbs({ items }: { items: Crumb[] }) {
  if (items.length === 0) return null;

  return (
    <nav aria-label="Breadcrumb" className="mb-2 text-xs font-medium text-slate-500">
      <ol className="flex flex-wrap items-center gap-1">
        {items.map((item, index) => (
          <li key={`${item.label}-${index}`} className="flex items-center gap-1">
            {index > 0 && <Icon name="chevronDown" size={12} className="-rotate-90 text-slate-300" />}
            {item.href ? (
              <Link href={item.href} className={`rounded transition-colors hover:text-brand-700 ${FOCUS_RING}`}>
                {item.label}
              </Link>
            ) : (
              <span aria-current={index === items.length - 1 ? 'page' : undefined} className="text-slate-700">{item.label}</span>
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
}

/** The one <h1> of a page, with what the page is for and its main actions. */
export function PageHeader({ title, description, actions, crumbs }: { title: string; description?: ReactNode; actions?: ReactNode; crumbs?: Crumb[] }) {
  return (
    <div className="mb-6">
      {crumbs && <Breadcrumbs items={crumbs} />}
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">{title}</h1>
          {description && <p className="mt-1 max-w-3xl text-sm text-slate-600">{description}</p>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>
    </div>
  );
}

export function Card({ title, description, actions, children, className = '' }: { title?: ReactNode; description?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={`rounded-2xl border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04),0_1px_3px_rgba(15,23,42,0.04)] ${className}`}>
      {(title || actions) && (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3.5">
          <div className="min-w-0">
            {title && <h2 className="text-[15px] font-semibold text-slate-900">{title}</h2>}
            {description && <p className="mt-0.5 text-xs text-slate-500">{description}</p>}
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
  const hasTrend = trend !== undefined && trend !== null;
  const body = (
    <>
      {icon && (
        <span className="absolute right-4 top-4 flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-brand-100 transition-transform duration-200 group-hover:scale-110 motion-reduce:transform-none">
          <Icon name={icon} size={18} />
        </span>
      )}
      <p className={`text-xs font-medium uppercase tracking-wide text-slate-500 ${icon ? 'pr-10' : ''}`}>{label}</p>
      <p className="mt-1 text-2xl font-semibold tracking-tight text-slate-900">{value ?? <span className="text-base font-normal text-slate-500">Not available</span>}</p>
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
          {hint && <span>{hint}</span>}
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
    <div role="tablist" aria-label={label} onKeyDown={onKeyDown} className="relative mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
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
            tab.id === active ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-900'
          }`}
        >
          {tab.label}
        </button>
      ))}
    </div>
  );
}

export function Skeleton({ lines = 3 }: { lines?: number }) {
  return (
    <div role="status" className="space-y-3 rounded-2xl border border-slate-200/80 bg-white p-5">
      <span className="sr-only">Loading…</span>
      {Array.from({ length: lines }, (_, index) => (
        <span key={index} className="block h-4 animate-pulse rounded-md bg-slate-100 motion-reduce:animate-none" style={{ width: `${90 - index * 15}%` }} />
      ))}
    </div>
  );
}

/** An error with a way out. Used wherever a request can fail. */
export function ErrorPanel({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div role="alert" className="flex gap-3 rounded-2xl border border-red-200 bg-red-50/80 p-4 text-sm text-red-800">
      <Icon name="alert" size={20} className="mt-0.5 text-red-500" />
      <div>
        <p>{message}</p>
        {onRetry && (
          <Button size="sm" className="mt-3" onClick={onRetry}>
            Try again
          </Button>
        )}
      </div>
    </div>
  );
}

export function EmptyPanel({ title, description, action, icon = 'layers' }: { title: string; description?: ReactNode; action?: ReactNode; icon?: IconName }) {
  return (
    <div className="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
      <span aria-hidden="true" className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 ring-8 ring-brand-50/50">
        <Icon name={icon} size={24} />
      </span>
      <p className="font-semibold text-slate-900">{title}</p>
      {description && <p className="mx-auto mt-1 max-w-md text-sm text-slate-600">{description}</p>}
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
  // Two-step sign-in is the reason (Phase B29): say what to do about it.
  const mfa = code === 'mfa_enrollment_required' || code === 'mfa_verification_required';

  return (
    <div role="status" className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
      <p className="font-medium">{mfa ? 'Two-step sign-in is needed here.' : 'You cannot open this.'}</p>
      <p className="mt-1">{message ?? 'Your role does not include this page. Ask the store owner if you need it.'}</p>
      {code === 'mfa_enrollment_required' && (
        <p className="mt-2">
          <Link href="/security" className={`font-medium underline ${FOCUS_RING}`}>
            Turn on two-step sign-in
          </Link>
        </p>
      )}
      {code === 'mfa_verification_required' && <p className="mt-2">Log out and sign in again with your two-step code.</p>}
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
  if (limit === null) {
    return (
      <p className="text-sm text-slate-600">
        {label}: {current} used, no limit on your package.
      </p>
    );
  }
  const percent = limit > 0 ? Math.min(100, Math.round((current / limit) * 100)) : 100;
  const full = current >= limit;

  return (
    <div>
      <p className={`text-sm ${full ? 'font-medium text-red-700' : 'text-slate-600'}`}>
        {label}: {current} of {limit} used{full ? ' — limit reached' : ''}
      </p>
      <div className="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow={current} aria-valuemin={0} aria-valuemax={limit} aria-label={label}>
        <div className={`h-2 rounded-full transition-[width] duration-500 motion-reduce:transition-none ${full ? 'bg-red-600' : percent >= 80 ? 'bg-amber-500' : 'bg-brand-600'}`} style={{ width: `${percent}%` }} />
      </div>
    </div>
  );
}

/** Label and value pairs (a details list). */
export function Details({ items }: { items: { label: string; value: ReactNode }[] }) {
  return (
    <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
      {items.map((item) => (
        <div key={item.label}>
          <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{item.label}</dt>
          <dd className="mt-0.5 break-words text-slate-900">{item.value ?? '—'}</dd>
        </div>
      ))}
    </dl>
  );
}
