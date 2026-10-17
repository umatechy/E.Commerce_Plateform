import { ReactNode, useEffect, useId, useRef, useState } from 'react';
import { FOCUS_RING } from './Button';
import Icon from './Icon';
import { useAdminT } from '@/lib/adminI18n';

/**
 * A search box that asks the server, not the page: it reports what was
 * typed after a short pause, so a list is not requested on every key.
 */
export function SearchField({ label, value, onChange, placeholder }: { label: string; value: string; onChange: (value: string) => void; placeholder?: string }) {
  const t = useAdminT();
  const id = useId();
  const [text, setText] = useState(value);
  const sent = useRef(value);

  useEffect(() => {
    // The filter was changed from outside (e.g. "Clear filters").
    if (value !== sent.current) {
      sent.current = value;
      setText(value);
    }
  }, [value]);

  useEffect(() => {
    if (text === sent.current) return;
    const timer = window.setTimeout(() => {
      sent.current = text;
      onChange(text);
    }, 300);

    return () => window.clearTimeout(timer);
  }, [text, onChange]);

  return (
    <div className="min-w-[12rem] flex-1">
      <label htmlFor={id} className="block text-sm font-medium text-slate-700">
        {t(label)}
      </label>
      <div className="relative mt-1">
        <Icon name="search" size={16} className="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-slate-400" />
        <input
          id={id}
          type="search"
          value={text}
          placeholder={placeholder && t(placeholder)}
          onChange={(e) => setText(e.target.value)}
          className={`block min-h-10 w-full rounded-lg border border-slate-300 bg-white py-2 ps-9 pe-3 text-sm shadow-sm shadow-slate-900/5 transition-colors placeholder:text-slate-400 hover:border-slate-400 focus-visible:border-brand-500 motion-reduce:transition-none ${FOCUS_RING}`}
        />
      </div>
    </div>
  );
}

/** The row of filters above a list. */
export function FilterBar({ children }: { children: ReactNode }) {
  return <div className="mb-4 flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.04)]">{children}</div>;
}
