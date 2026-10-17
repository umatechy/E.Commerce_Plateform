import { useEffect, useRef, useState } from 'react';
import { FOCUS_RING } from './Button';
import Icon, { type IconName } from './Icon';

/**
 * UI-3 — the "⋯" menu of a list row: less used or risky actions (duplicate,
 * delete) out of the way, so a delete is never one stray click. The button
 * names the row ("Actions for Blue Shirt"); Escape and a click elsewhere
 * close it; arrow keys move between items.
 */
export type RowAction = { label: string; icon?: IconName; onSelect: () => void; danger?: boolean; disabled?: boolean };

export default function RowMenu({ label, actions }: { label: string; actions: RowAction[] }) {
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement>(null);
  const items = useRef<(HTMLButtonElement | null)[]>([]);

  useEffect(() => {
    if (!open) return;
    const close = (event: MouseEvent) => {
      if (box.current && !box.current.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    items.current[0]?.focus();

    return () => document.removeEventListener('mousedown', close);
  }, [open]);

  if (actions.length === 0) return null;

  return (
    <div
      ref={box}
      className="relative inline-block text-left"
      onClick={(e) => e.stopPropagation()}
      onKeyDown={(e) => {
        if (e.key === 'Escape') setOpen(false);
        if (!open || !['ArrowDown', 'ArrowUp'].includes(e.key)) return;
        e.preventDefault();
        const index = items.current.findIndex((item) => item === document.activeElement);
        const next = (index + (e.key === 'ArrowDown' ? 1 : -1) + actions.length) % actions.length;
        items.current[next]?.focus();
      }}
    >
      <button
        type="button"
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
        className={`flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800 ${FOCUS_RING}`}
      >
        <span aria-hidden="true" className="text-lg leading-none">⋯</span>
      </button>
      {open && (
        <div role="menu" aria-label={label} className="absolute right-0 z-30 mt-1 w-44 rounded-lg border border-slate-200 bg-white p-1 text-sm shadow-lg">
          {actions.map((action, i) => (
            <button
              key={action.label}
              ref={(el) => { items.current[i] = el; }}
              type="button"
              role="menuitem"
              disabled={action.disabled}
              onClick={() => { setOpen(false); action.onSelect(); }}
              className={`flex w-full items-center gap-2 rounded-md px-3 py-1.5 text-left disabled:cursor-not-allowed disabled:opacity-50 ${FOCUS_RING} ${action.danger ? 'text-red-700 hover:bg-red-50' : 'text-slate-700 hover:bg-slate-100'}`}
            >
              {action.icon && <Icon name={action.icon} size={16} className={action.danger ? 'text-red-500' : 'text-slate-400'} />}
              {action.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
