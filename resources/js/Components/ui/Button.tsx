import { ButtonHTMLAttributes, forwardRef } from 'react';
import { Link } from '@inertiajs/react';
import { useAdminT } from '@/lib/adminI18n';

/**
 * The admin's buttons (Phase B31 design system). One component so that
 * every action looks and behaves the same: visible keyboard focus, a
 * disabled look that is not only a colour, and "busy" that both says so
 * and stops a second click.
 *
 * UI-7: the theme colour (brand-*), a soft shadow, a small lift on hover
 * and a press on click (both off with reduced motion), a spinner while
 * busy, two more variants (soft, outline) and a large size.
 */
export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost' | 'soft' | 'outline';
export type ButtonSize = 'sm' | 'md' | 'lg';

export const FOCUS_RING = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2';

const VARIANT: Record<ButtonVariant, string> = {
  primary: 'border border-brand-700/20 bg-gradient-to-b from-brand-500 to-brand-600 text-white shadow-md shadow-brand-600/25 hover:from-brand-600 hover:to-brand-700 hover:shadow-lg hover:shadow-brand-600/30',
  secondary: 'border border-slate-200 bg-white text-slate-800 shadow-sm shadow-slate-900/5 hover:border-slate-300 hover:bg-slate-50',
  danger: 'border border-red-700/20 bg-gradient-to-b from-red-500 to-red-600 text-white shadow-md shadow-red-600/20 hover:from-red-600 hover:to-red-700 hover:shadow-lg',
  ghost: 'border border-transparent bg-transparent text-slate-700 hover:bg-slate-100 hover:text-slate-900',
  soft: 'border border-transparent bg-brand-50 text-brand-700 hover:bg-brand-100',
  outline: 'border border-brand-200 bg-white text-brand-700 hover:border-brand-300 hover:bg-brand-50',
};

const SIZE: Record<ButtonSize, string> = {
  sm: 'min-h-8 px-3 py-1 text-xs sm:text-sm',
  md: 'min-h-11 px-4 py-2 text-sm',
  lg: 'min-h-12 px-6 py-2.5 text-base',
};

export function buttonClass(variant: ButtonVariant = 'secondary', size: ButtonSize = 'md', extra = ''): string {
  return `inline-flex select-none items-center justify-center gap-2 rounded-xl font-semibold transition duration-150 ease-out hover:-translate-y-px active:translate-y-0 active:scale-[0.98] disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 motion-reduce:transform-none motion-reduce:transition-none ${FOCUS_RING} ${VARIANT[variant]} ${SIZE[size]} ${extra}`;
}

/** A small spinner for busy buttons (decorative; the busy label says it in words). */
export function Spinner({ className = '' }: { className?: string }) {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" className={`h-4 w-4 animate-spin motion-reduce:animate-none ${className}`} fill="none">
      <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.25" strokeWidth="3" />
      <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
    </svg>
  );
}

type Props = ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant; size?: ButtonSize; busy?: boolean; busyLabel?: string };

const Button = forwardRef<HTMLButtonElement, Props>(function Button(
  { variant = 'secondary', size = 'md', busy = false, busyLabel, disabled, children, className = '', type = 'button', ...rest },
  ref,
) {
  const t = useAdminT();

  return (
    <button ref={ref} type={type} disabled={disabled || busy} aria-busy={busy || undefined} className={buttonClass(variant, size, className)} {...rest}>
      {busy ? <><Spinner />{busyLabel ?? t('Working…')}</> : children}
    </button>
  );
});

export default Button;

/** A link that looks like a button (navigation, not an action). */
export function ButtonLink({ href, variant = 'secondary', size = 'md', children, className = '' }: { href: string; variant?: ButtonVariant; size?: ButtonSize; children: React.ReactNode; className?: string }) {
  return (
    <Link href={href} className={buttonClass(variant, size, className)}>
      {children}
    </Link>
  );
}
