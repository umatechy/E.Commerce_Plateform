import { useRef, useState } from 'react';

/**
 * UI-1 — the six-digit two-step code. One real input (so typing, pasting,
 * the phone's one-time-code suggestion and screen readers all work) shown
 * as six boxes. A pasted six-digit code calls `onComplete` at once.
 */
export default function CodeField({ id, value, onChange, onComplete, invalid, describedBy }: {
  id: string; value: string; onChange: (value: string) => void; onComplete?: (code: string) => void; invalid?: boolean; describedBy?: string;
}) {
  const input = useRef<HTMLInputElement>(null);
  const [focused, setFocused] = useState(true);
  const digits = value.padEnd(6, ' ').slice(0, 6).split('');
  const active = Math.min(value.length, 5);

  return (
    <div className="relative" onClick={() => input.current?.focus()}>
      <div aria-hidden="true" className="grid grid-cols-6 gap-2">
        {digits.map((d, i) => (
          <span
            key={i}
            className={`flex h-14 items-center justify-center rounded-xl border bg-white text-2xl font-semibold tabular-nums text-slate-900 shadow-sm transition-colors motion-reduce:transition-none ${
              invalid ? 'border-red-400' : focused && i === active ? 'border-brand-500 ring-2 ring-brand-500/30' : 'border-slate-300'
            }`}
          >
            {d.trim()}
          </span>
        ))}
      </div>
      <input
        ref={input}
        id={id}
        type="text"
        inputMode="numeric"
        pattern="[0-9]*"
        autoComplete="one-time-code"
        autoFocus
        maxLength={6}
        value={value}
        aria-invalid={invalid ? true : undefined}
        aria-describedby={describedBy}
        onFocus={() => setFocused(true)}
        onBlur={() => setFocused(false)}
        onChange={(e) => {
          const next = e.target.value.replace(/\D/g, '').slice(0, 6);
          onChange(next);
        }}
        onPaste={(e) => {
          // A pasted code is sent at once; typed digits wait for the button.
          const pasted = e.clipboardData.getData("text").replace(/\D/g, "").slice(0, 6);
          if (pasted.length === 6) {
            e.preventDefault();
            onChange(pasted);
            onComplete?.(pasted);
          }
        }}
        className="absolute inset-0 h-full w-full cursor-text bg-transparent text-transparent caret-transparent outline-none selection:bg-transparent"
      />
    </div>
  );
}
