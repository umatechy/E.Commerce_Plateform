import { useSyncExternalStore } from 'react';

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
  success: 'border-green-300 bg-green-50 text-green-900',
  error: 'border-red-300 bg-red-50 text-red-900',
  warning: 'border-amber-300 bg-amber-50 text-amber-900',
};

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
        <div key={item.id} role={item.kind === 'error' ? 'alert' : 'status'} className={`pointer-events-auto flex items-start justify-between gap-3 rounded-md border p-3 text-sm shadow-md ${STYLE[item.kind]}`}>
          <p>
            <span className="font-semibold">{PREFIX[item.kind]}: </span>
            {item.message}
          </p>
          <button type="button" onClick={() => dismissToast(item.id)} aria-label="Dismiss" className="rounded px-1 text-current hover:bg-black/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600">
            ✕
          </button>
        </div>
      ))}
    </div>
  );
}
