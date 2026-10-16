import { useState } from 'react';
import Icon from '@/Components/ui/Icon';
import { FieldError, authInput } from './AuthShell';

/**
 * UI-1 — a password input with a show/hide button and, for new passwords,
 * a strength hint. The hint helps people choose; the server's password
 * rules decide.
 */
export function passwordStrength(value: string): { score: 0 | 1 | 2 | 3 | 4; label: string } {
  if (value === '') return { score: 0, label: '' };
  let score = 0;
  if (value.length >= 10) score++;
  if (value.length >= 14) score++;
  if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
  if (/\d/.test(value) && /[^A-Za-z0-9]/.test(value)) score++;
  if (value.length < 10) score = Math.min(score, 1);
  const s = Math.max(1, Math.min(4, score)) as 1 | 2 | 3 | 4;

  return { score: s, label: ['', 'Too weak', 'Fair', 'Good', 'Strong'][s] };
}

const BAR = ['bg-slate-200', 'bg-red-500', 'bg-amber-500', 'bg-lime-500', 'bg-emerald-600'];

export default function PasswordField({ id, label, value, onChange, error, autoComplete, strength = false, autoFocus = false }: {
  id: string; label: string; value: string; onChange: (value: string) => void; error?: string | null; autoComplete: string; strength?: boolean; autoFocus?: boolean;
}) {
  const [shown, setShown] = useState(false);
  const meter = passwordStrength(value);

  return (
    <div>
      <label htmlFor={id} className="block text-sm font-medium text-slate-700">{label}</label>
      <div className="relative">
        <input
          id={id}
          type={shown ? 'text' : 'password'}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          autoComplete={autoComplete}
          autoFocus={autoFocus}
          aria-invalid={error ? true : undefined}
          aria-describedby={[error ? `${id}-error` : null, strength && value ? `${id}-strength` : null].filter(Boolean).join(' ') || undefined}
          className={`${authInput} pr-11`}
        />
        <button
          type="button"
          onClick={() => setShown((s) => !s)}
          aria-label={shown ? `Hide ${label.toLowerCase()}` : `Show ${label.toLowerCase()}`}
          aria-pressed={shown}
          className="absolute inset-y-0 right-0 mt-1.5 flex w-11 items-center justify-center rounded-r-lg text-slate-500 hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
        >
          <Icon name={shown ? 'eyeOff' : 'eye'} size={18} />
        </button>
      </div>
      {strength && value !== '' && (
        <div id={`${id}-strength`} className="mt-2" aria-live="polite">
          <div className="flex gap-1" aria-hidden="true">
            {[1, 2, 3, 4].map((i) => <span key={i} className={`h-1.5 flex-1 rounded-full ${i <= meter.score ? BAR[meter.score] : BAR[0]}`} />)}
          </div>
          <p className="mt-1 text-xs text-slate-500">Strength: <span className="font-medium text-slate-700">{meter.label}</span> · at least 10 characters; longer is stronger.</p>
        </div>
      )}
      <FieldError id={`${id}-error`} message={error} />
    </div>
  );
}
