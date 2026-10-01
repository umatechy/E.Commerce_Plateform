import { ReactNode, useEffect, useId, useRef, useState } from 'react';
import { FOCUS_RING } from './Button';

/**
 * A search box that asks the server, not the page: it reports what was
 * typed after a short pause, so a list is not requested on every key.
 */
export function SearchField({ label, value, onChange, placeholder }: { label: string; value: string; onChange: (value: string) => void; placeholder?: string }) {
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
        {label}
      </label>
      <input
        id={id}
        type="search"
        value={text}
        placeholder={placeholder}
        onChange={(e) => setText(e.target.value)}
        className={`mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm ${FOCUS_RING}`}
      />
    </div>
  );
}

/** The row of filters above a list. */
export function FilterBar({ children }: { children: ReactNode }) {
  return <div className="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-3">{children}</div>;
}
