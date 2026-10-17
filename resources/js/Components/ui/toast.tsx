import { useSyncExternalStore } from 'react';
import Icon, { type IconName } from './Icon';

/**
 * Short feedback after an action ("Saved", "Could not save"). Kept in a
 * module-level store rather than in the layout's state, because every
 * Inertia page mounts its own layout: a toast raised just before moving
 * to another page still shows there.
 *
 * Used sparingly: one toast per finished action. Errors that belong to a
 * form stay in the form.
 */
export type ToastKind = 'success' | 'error' | 'warning';
export type ToastItem = { id: number; kind: ToastKind; message: string };

let items: ToastItem[] = [];
let nextId = 1;
const listeners = new Set<() => void>();

function emit(): void {
  listeners.forEach((listener) => listener());
}

export function dismissToast(id: number): void {
  items = items.filter((item) => item.id !== id);
  emit();
}

function push(kind: ToastKind, message: string): void {
  const id = nextId++;
  items = [...items.slice(-2), { id, kind, message }];
  emit();
  // Errors stay longer: they usually need reading twice.
  if (typeof window !== 'undefined') window.setTimeout(() => dismissToast(id), kind === 'success' ? 4000 : 8000);
}

export const toast = {
  success: (message: string) => push('success', message),
  error: (message: string) => push('error', message),
  warning: (message: string) => push('warning', message),
};

/** For tests. */
export function clearToasts(): void {
  items = [];
  emit();
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);

  return () => listeners.delete(listener);
}

const STYLE: Record<ToastKind, string> = {
  success: 'text-emerald-600 bg-emerald-50',
  error: 'text-red-600 bg-red-50',
  warning: 'text-amber-600 bg-amber-50',
};

const ICON: Record<ToastKind, IconName> = { success: 'checkCircle', error: 'alert', warning: 'alert' };

const PREFIX: Record<ToastKind, string> = { success: 'Done', error: 'Error', warning: 'Note' };

/** Rendered once by the admin layout. A live region, so readers announce each toast. */
export function Toaster() {
  const current = useSyncExternalStore(
    subscribe,
    () => items,
    () => items,
  );

  return (
    <div aria-live="polite" className="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-[calc(100%-2rem)] max-w-sm flex-col gap-2">
      {current.map((item) => (
        <div key={item.id} role={item.kind === 'error' ? 'alert' : 'status'} className="pointer-events-auto flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-3.5 text-sm text-slate-800 shadow-xl shadow-slate-900/10 motion-safe:animate-fade-in-up">
          <span aria-hidden="true" className={`flex h-8 w-8 flex-none items-center justify-center rounded-lg ${STYLE[item.kind]}`}><Icon name={ICON[item.kind]} size={18} /></span>
          <p className="min-w-0 flex-1 pt-1.5">
            <span className="font-semibold">{PREFIX[item.kind]}: </span>
            {item.message}
          </p>
          <button type="button" onClick={() => dismissToast(item.id)} aria-label="Dismiss" className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
            <Icon name="x" size={16} />
          </button>
        </div>
      ))}
    </div>
  );
}
