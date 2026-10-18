import { useCallback, useEffect, useRef, useState } from 'react';
import { adminErrorMessage, fieldErrors, wasCancelled } from './adminApi';
import { toast } from '@/Components/ui/toast';
import { registerDirty } from './leaveGuard';

/**
 * Form state for a page that saves through the JSON API (Phase B31).
 *
 * - The server validates. Its 422 messages land under their fields; any
 *   other failure is one message for the whole form.
 * - While a save is in flight a second submit is ignored (no duplicate
 *   submission).
 * - `dirty` tells whether the user changed something since the last
 *   load or save; `useUnsavedWarning` uses it.
 */
export function useForm<T extends Record<string, unknown>>(initial: T) {
  const [values, setValues] = useState<T>(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [dirty, setDirty] = useState(false);
  const inFlight = useRef(false);

  const set = useCallback(<K extends keyof T>(field: K, value: T[K]) => {
    setValues((current) => ({ ...current, [field]: value }));
    setDirty(true);
    setErrors((current) => {
      if (!(field in current)) return current;
      const next = { ...current };
      delete next[field as string];

      return next;
    });
  }, []);

  /** Replace everything (after loading the record, or to clear the form). */
  const reset = useCallback((next: T) => {
    setValues(next);
    setErrors({});
    setFormError(null);
    setDirty(false);
  }, []);

  /**
   * Runs the save. Resolves with its result, or undefined when it failed
   * (the errors are then on the form) or was cancelled at the step-up
   * dialog.
   */
  const submit = useCallback(async <R>(save: (values: T) => Promise<R>, success?: string): Promise<R | undefined> => {
    if (inFlight.current) return undefined;
    inFlight.current = true;
    setBusy(true);
    setErrors({});
    setFormError(null);
    try {
      const result = await save(valuesRef.current);
      setDirty(false);
      if (success) toast.success(success);

      return result;
    } catch (e) {
      if (wasCancelled(e)) return undefined;
      const fields = fieldErrors(e);
      setErrors(fields);
      // A message for a field this form has no input for ("items", "items.0.quantity") is
      // shown at the top — otherwise "Check the highlighted fields" would point at nothing.
      const unplaced = Object.entries(fields).filter(([field]) => !(field in valuesRef.current)).map(([, text]) => text);
      setFormError(unplaced.length > 0 ? unplaced.join(' ') : Object.keys(fields).length > 0 ? 'Check the highlighted fields.' : adminErrorMessage(e));

      return undefined;
    } finally {
      inFlight.current = false;
      setBusy(false);
    }
  }, []);

  // The latest values, for `submit` (stable identity) to read.
  const valuesRef = useRef(values);
  valuesRef.current = values;

  return { values, set, reset, errors, formError, setFormError, busy, dirty, submit };
}

/**
 * Asks before leaving the page with unsaved changes: the browser's question
 * for closing, reloading or a typed address, and (UI-13, lib/leaveGuard)
 * the admin's "Leave without saving?" dialog for links inside the admin.
 */
export function useUnsavedWarning(dirty: boolean): void {
  useEffect(() => {
    if (!dirty) return;
    const warn = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = '';
    };
    window.addEventListener('beforeunload', warn);
    const unregister = registerDirty();

    return () => {
      window.removeEventListener('beforeunload', warn);
      unregister();
    };
  }, [dirty]);
}

/**
 * Runs one-off actions (a button, a confirmation). `busy` holds the key
 * of the action in flight, so its button can show it and the others can
 * wait. Failures are reported as a toast unless the caller handles them.
 */
export function useAction() {
  const [busy, setBusy] = useState<string | null>(null);
  const inFlight = useRef(false);

  const run = useCallback(async <R>(key: string, action: () => Promise<R>, options: { success?: string; onError?: (message: string) => void } = {}): Promise<R | undefined> => {
    if (inFlight.current) return undefined;
    inFlight.current = true;
    setBusy(key);
    try {
      const result = await action();
      if (options.success) toast.success(options.success);

      return result;
    } catch (e) {
      if (wasCancelled(e)) return undefined;
      const message = adminErrorMessage(e);
      if (options.onError) options.onError(message);
      else toast.error(message);

      return undefined;
    } finally {
      inFlight.current = false;
      setBusy(null);
    }
  }, []);

  return { busy, run };
}
