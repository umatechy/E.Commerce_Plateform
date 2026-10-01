import { ButtonHTMLAttributes, forwardRef } from 'react';
import { Link } from '@inertiajs/react';

/**
 * The admin's buttons (Phase B31 design system). One component so that
 * every action looks and behaves the same: visible keyboard focus, a
 * disabled look that is not only a colour, and "busy" that both says so
 * and stops a second click.
 */
export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost';
export type ButtonSize = 'sm' | 'md';

export const FOCUS_RING = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600 focus-visible:ring-offset-2';

const VARIANT: Record<ButtonVariant, string> = {
  primary: 'bg-indigo-600 text-white hover:bg-indigo-700 border border-transparent',
  secondary: 'bg-white text-slate-800 hover:bg-slate-50 border border-slate-300',
  danger: 'bg-red-600 text-white hover:bg-red-700 border border-transparent',
  ghost: 'bg-transparent text-slate-700 hover:bg-slate-100 border border-transparent',
};

const SIZE: Record<ButtonSize, string> = { sm: 'px-2.5 py-1 text-sm', md: 'px-3.5 py-2 text-sm' };

export function buttonClass(variant: ButtonVariant = 'secondary', size: ButtonSize = 'md', extra = ''): string {
  return `inline-flex items-center justify-center gap-2 rounded-md font-medium transition-colors motion-reduce:transition-none disabled:cursor-not-allowed disabled:opacity-50 ${FOCUS_RING} ${VARIANT[variant]} ${SIZE[size]} ${extra}`;
}

type Props = ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant; size?: ButtonSize; busy?: boolean; busyLabel?: string };

const Button = forwardRef<HTMLButtonElement, Props>(function Button(
  { variant = 'secondary', size = 'md', busy = false, busyLabel, disabled, children, className = '', type = 'button', ...rest },
  ref,
) {
  return (
    <button ref={ref} type={type} disabled={disabled || busy} aria-busy={busy || undefined} className={buttonClass(variant, size, className)} {...rest}>
      {busy ? (busyLabel ?? 'Working…') : children}
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
