import type { ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import BrandLogo, { useBrand } from '@/Components/Brand';
import Icon, { type IconName } from '@/Components/ui/Icon';

/**
 * UI-1 — the frame of every sign-in page (sign in, two-step code, create a
 * store, forgot and reset password, invitations): the brand and what the
 * platform does on one side, the form on a card on the other. On a phone
 * the brand sits above the form.
 */
const POINTS: { icon: IconName; title: string; text: string }[] = [
  { icon: 'wallet', title: 'Get paid your way', text: 'Cash on delivery, bank transfer, JazzCash and Easypaisa.' },
  { icon: 'language', title: 'Urdu and English', text: 'A shop your customers read in their language, right to left too.' },
  { icon: 'phone', title: 'Your shop as an app', text: 'Customers add it to their home screen, no app store needed.' },
  { icon: 'shieldCheck', title: 'Safe by design', text: 'Two-step sign-in, encrypted details and a full audit trail.' },
];

export default function AuthShell({ title, subtitle, children, footer, wide = false }: { title: string; subtitle?: ReactNode; children: ReactNode; footer?: ReactNode; wide?: boolean }) {
  const brand = useBrand();

  return (
    <div className="flex min-h-screen bg-slate-50 text-slate-900">
      <Head title={`${title} · ${brand.name}`} />
      <aside className="relative hidden w-[44%] max-w-xl flex-col justify-between overflow-hidden bg-gradient-to-br from-brand-700 via-brand-800 to-violet-900 p-10 text-white lg:flex">
        <div aria-hidden="true" className="pointer-events-none absolute -right-24 -top-24 h-80 w-80 rounded-full bg-white/10 blur-2xl" />
        <div aria-hidden="true" className="pointer-events-none absolute -bottom-32 -left-20 h-96 w-96 rounded-full bg-violet-400/20 blur-3xl" />
        <a href="/" className="relative w-fit rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
          <BrandLogo tone="light" />
        </a>
        <div className="relative">
          <h2 className="max-w-md text-3xl font-bold leading-tight">Everything you need to sell online, in one place.</h2>
          <ul className="mt-8 space-y-5">
            {POINTS.map((p) => (
              <li key={p.title} className="flex gap-4">
                <span className="flex h-10 w-10 flex-none items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/20">
                  <Icon name={p.icon} />
                </span>
                <span>
                  <span className="block font-semibold">{p.title}</span>
                  <span className="block text-sm text-brand-100">{p.text}</span>
                </span>
              </li>
            ))}
          </ul>
        </div>
        <p className="relative text-xs text-brand-200">© {new Date().getFullYear()} {brand.name}</p>
      </aside>

      <main className="flex flex-1 flex-col items-center justify-center px-4 py-10 sm:px-6">
        <a href="/" className="mb-8 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 lg:hidden">
          <BrandLogo />
        </a>
        <div className={`w-full ${wide ? 'max-w-lg' : 'max-w-md'}`}>
          <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <h1 className="text-2xl font-bold tracking-tight text-slate-900">{title}</h1>
            {subtitle && <div className="mt-2 text-sm text-slate-600">{subtitle}</div>}
            <div className="mt-6">{children}</div>
          </div>
          {footer && <div className="mt-6 text-center text-sm text-slate-600">{footer}</div>}
        </div>
      </main>
    </div>
  );
}

/** The input style of the sign-in pages. */
export const authInput = 'mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 aria-[invalid=true]:border-red-400';
export const authButton = 'inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 font-semibold text-white shadow-sm transition-colors hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 motion-reduce:transition-none';
export const authLink = 'font-medium text-brand-700 hover:text-brand-800 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 rounded';

export function FieldError({ id, message }: { id?: string; message?: string | null }) {
  if (!message) return null;

  return <p id={id} role="alert" className="mt-1.5 text-sm text-red-600">{message}</p>;
}

export function FormAlert({ tone = 'error', children }: { tone?: 'error' | 'info'; children: ReactNode }) {
  return (
    <p role={tone === 'error' ? 'alert' : 'status'} className={`rounded-lg border px-3.5 py-2.5 text-sm ${tone === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-amber-200 bg-amber-50 text-amber-900'}`}>
      {children}
    </p>
  );
}

export function Spinner() {
  return <span aria-hidden="true" className="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white motion-reduce:animate-none" />;
}
