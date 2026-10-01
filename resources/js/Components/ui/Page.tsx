import { KeyboardEvent, ReactNode, useId } from 'react';
import { Link } from '@inertiajs/react';
import Button, { FOCUS_RING } from './Button';

/** Page building blocks of the admin (Phase B31 design system): heading, cards, figures, tabs, and the load states of a page. */

export type Crumb = { label: string; href?: string };

export function Breadcrumbs({ items }: { items: Crumb[] }) {
  if (items.length === 0) return null;

  return (
    <nav aria-label="Breadcrumb" className="mb-2 text-xs text-slate-500">
      <ol className="flex flex-wrap items-center gap-1">
        {items.map((item, index) => (
          <li key={`${item.label}-${index}`} className="flex items-center gap-1">
            {index > 0 && <span aria-hidden="true">/</span>}
            {item.href ? (
              <Link href={item.href} className={`rounded hover:text-slate-900 hover:underline ${FOCUS_RING}`}>
                {item.label}
              </Link>
            ) : (
              <span aria-current={index === items.length - 1 ? 'page' : undefined}>{item.label}</span>
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
    <div className="mb-5">
      {crumbs && <Breadcrumbs items={crumbs} />}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-xl font-semibold text-slate-900">{title}</h1>
          {description && <p className="mt-1 text-sm text-slate-600">{description}</p>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>
    </div>
  );
}

export function Card({ title, description, actions, children, className = '' }: { title?: string; description?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={`rounded-lg border border-slate-200 bg-white ${className}`}>
      {(title || actions) && (
        <div className="flex flex-wrap items-start justify-between gap-2 border-b border-slate-200 px-4 py-3">
          <div>
            {title && <h2 className="text-sm font-semibold text-slate-900">{title}</h2>}
            {description && <p className="mt-0.5 text-xs text-slate-600">{description}</p>}
          </div>
          {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
      )}
      <div className="p-4">{children}</div>
    </section>
  );
}

/** One figure. `value` null means the server gave none: shown as "Not available", never as a made-up zero. */
export function StatCard({ label, value, hint, href }: { label: string; value: ReactNode | null; hint?: ReactNode; href?: string }) {
  const body = (
    <>
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className="mt-1 text-2xl font-semibold text-slate-900">{value ?? <span className="text-base font-normal text-slate-500">Not available</span>}</p>
      {hint && <p className="mt-1 text-xs text-slate-600">{hint}</p>}
    </>
  );
  const box = 'block rounded-lg border border-slate-200 bg-white p-4';

  return href ? (
    <Link href={href} className={`${box} hover:border-slate-400 ${FOCUS_RING}`}>
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
    <div role="tablist" aria-label={label} onKeyDown={onKeyDown} className="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
      {tabs.map((tab) => (
        <button
          key={tab.id}
          id={`${base}-${tab.id}`}
          role="tab"
          type="button"
          aria-selected={tab.id === active}
          tabIndex={tab.id === active ? 0 : -1}
          onClick={() => onChange(tab.id)}
          className={`whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium ${FOCUS_RING} ${
            tab.id === active ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-600 hover:text-slate-900'
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
    <div role="status" className="space-y-3 rounded-lg border border-slate-200 bg-white p-4">
      <span className="sr-only">Loading…</span>
      {Array.from({ length: lines }, (_, index) => (
        <span key={index} className="block h-4 animate-pulse rounded bg-slate-200 motion-reduce:animate-none" style={{ width: `${90 - index * 15}%` }} />
      ))}
    </div>
  );
}

/** An error with a way out. Used wherever a request can fail. */
export function ErrorPanel({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div role="alert" className="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800">
      <p>{message}</p>
      {onRetry && (
        <Button size="sm" className="mt-3" onClick={onRetry}>
          Try again
        </Button>
      )}
    </div>
  );
}

export function EmptyPanel({ title, description, action }: { title: string; description?: ReactNode; action?: ReactNode }) {
  return (
    <div className="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center">
      <p className="font-medium text-slate-900">{title}</p>
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
    <div role="status" className="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
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
    <div role="status" className="rounded-md border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900">
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
      <div className="mt-1 h-1.5 w-full rounded-full bg-slate-200" role="progressbar" aria-valuenow={current} aria-valuemin={0} aria-valuemax={limit} aria-label={label}>
        <div className={`h-1.5 rounded-full ${full ? 'bg-red-600' : percent >= 80 ? 'bg-amber-500' : 'bg-indigo-600'}`} style={{ width: `${percent}%` }} />
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
