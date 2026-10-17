import { ReactNode, useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import { Spinner } from './Button';
import { useAdminT } from '@/lib/adminI18n';

/**
 * UI-21 — change one number right in a list (a price, a stock count)
 * without opening the record. Click the value (or its pencil), type,
 * Enter or leaving the box saves, Escape keeps the old value. `onSave`
 * answers whether it worked; the box stays open with the text when not.
 */
export default function InlineEdit({ display, initial, label, onSave, inputMode = 'decimal', className = '' }: {
  display: ReactNode;
  initial: string;
  /** Says what is edited, e.g. "Price of Blue Shirt". */
  label: string;
  onSave: (text: string) => Promise<boolean>;
  inputMode?: 'decimal' | 'numeric';
  className?: string;
}) {
  const t = useAdminT();
  const [editing, setEditing] = useState(false);
  const [text, setText] = useState(initial);
  const [busy, setBusy] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  const done = useRef(false);

  useEffect(() => {
    if (editing) {
      done.current = false;
      input.current?.select();
    }
  }, [editing]);

  async function save() {
    if (done.current) return;
    done.current = true;
    if (text.trim() === initial.trim()) {
      setEditing(false);

      return;
    }
    setBusy(true);
    const ok = await onSave(text.trim());
    setBusy(false);
    if (ok) setEditing(false);
    else done.current = false;
  }

  if (!editing) {
    return (
      <button
        type="button"
        onClick={() => {
          setText(initial);
          setEditing(true);
        }}
        title={t('Click to change')}
        className={`group/inline inline-flex items-center gap-1.5 rounded-md px-1.5 py-0.5 transition-colors hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 motion-reduce:transition-none ${className}`}
      >
        {display}
        <Icon name="pencil" size={13} className="text-slate-300 opacity-0 transition-opacity group-hover/inline:opacity-100 group-focus-visible/inline:opacity-100" />
        <span className="sr-only">{t('Change')} {label}</span>
      </button>
    );
  }

  return (
    <span className="inline-flex items-center gap-1.5">
      <input
        ref={input}
        aria-label={label}
        inputMode={inputMode}
        value={text}
        disabled={busy}
        onChange={(e) => setText(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            void save();
          }
          if (e.key === 'Escape') {
            e.preventDefault();
            done.current = true;
            setEditing(false);
          }
        }}
        onBlur={() => void save()}
        onClick={(e) => e.stopPropagation()}
        className="w-24 rounded-lg border border-brand-400 bg-white px-2 py-1 text-end text-sm tabular-nums shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600"
      />
      {busy && <Spinner className="text-brand-600" />}
    </span>
  );
}
