import { ReactNode, useEffect, useId, useRef, useState } from 'react';
import Button, { ButtonVariant } from './Button';
import { TextField } from './Form';
import Icon from './Icon';

/**
 * A modal dialog (Phase B31 design system): role="dialog", labelled by
 * its title, focus moves in when it opens and back when it closes, Tab
 * stays inside, Escape and the backdrop close it (unless it is busy).
 * `side` shows the same dialog as a drawer from the right, for details
 * next to a list.
 *
 * UI-13: once something was typed or chosen in it, Escape, the backdrop and
 * the close button ask "Discard your changes?" first, inside the dialog.
 * The dialog's own Cancel button (the caller's onClose) still closes at once.
 */
type DialogProps = {
  open: boolean;
  title: string;
  description?: ReactNode;
  onClose: () => void;
  children?: ReactNode;
  footer?: ReactNode;
  side?: boolean;
  /** While true the dialog cannot be dismissed (a request is in flight). */
  busy?: boolean;
  wide?: boolean;
  /** Ask before closing a dialog with changes (default true; off for confirmations). */
  guard?: boolean;
};

/** The open dialogs, the top one last. Only the top one answers the keyboard. */
const openDialogs: symbol[] = [];

const FOCUSABLE = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

export default function Dialog({ open, title, description, onClose, children, footer, side = false, busy = false, wide = false, guard = true }: DialogProps) {
  const titleId = useId();
  const descriptionId = useId();
  const panel = useRef<HTMLDivElement>(null);
  const returnTo = useRef<Element | null>(null);
  const [touched, setTouched] = useState(false);
  const [asking, setAsking] = useState(false);
  useEffect(() => {
    setTouched(false);
    setAsking(false);
  }, [open]);
  // Escape, the backdrop and ✕: ask first when the form inside was changed.
  const requestClose = () => {
    if (busy) return;
    if (guard && touched) setAsking(true);
    else onClose();
  };
  const latest = useRef({ busy, requestClose });
  latest.current = { busy, requestClose };

  useEffect(() => {
    if (!open) return;
    returnTo.current = document.activeElement;
    const first = panel.current?.querySelector<HTMLElement>('[data-autofocus]') ?? panel.current?.querySelector<HTMLElement>(FOCUSABLE);
    (first ?? panel.current)?.focus();

    return () => {
      if (returnTo.current instanceof HTMLElement) returnTo.current.focus();
    };
  }, [open]);

  // Escape and Tab are handled on the document, not on the panel: when the
  // focused control disappears (a button replaced after saving), the focus
  // falls to the page body, and the dialog must still close on Escape and
  // take the focus back on Tab.
  useEffect(() => {
    if (!open) return;
    const id = Symbol('dialog');
    openDialogs.push(id);

    function onKeyDown(event: globalThis.KeyboardEvent) {
      if (openDialogs[openDialogs.length - 1] !== id || !panel.current) return;
      if (event.key === 'Escape') {
        if (!latest.current.busy) {
          event.stopPropagation();
          latest.current.requestClose();
        }

        return;
      }
      if (event.key !== 'Tab') return;
      const items = Array.from(panel.current.querySelectorAll<HTMLElement>(FOCUSABLE));
      if (items.length === 0) {
        event.preventDefault();
        panel.current.focus();

        return;
      }
      const first = items[0];
      const last = items[items.length - 1];
      const inside = panel.current.contains(document.activeElement);
      if (event.shiftKey && (!inside || document.activeElement === first)) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (!inside || document.activeElement === last)) {
        event.preventDefault();
        first.focus();
      }
    }

    document.addEventListener('keydown', onKeyDown);

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      openDialogs.splice(openDialogs.indexOf(id), 1);
    };
  }, [open]);

  if (!open) return null;

  return (
    <div className={`fixed inset-0 z-50 flex ${side ? 'justify-end' : 'items-end justify-center sm:items-center'} bg-slate-950/40 p-0 backdrop-blur-[2px] motion-safe:animate-fade-in sm:p-4`} onMouseDown={(e) => e.target === e.currentTarget && requestClose()}>
      <div
        ref={panel}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={description ? descriptionId : undefined}
        tabIndex={-1}
        onInput={() => setTouched(true)}
        onChange={() => setTouched(true)}
        className={
          side
            ? 'flex h-full w-full max-w-xl flex-col bg-white shadow-2xl focus:outline-none motion-safe:animate-slide-in-right'
            : `flex max-h-[92vh] w-full flex-col rounded-t-2xl bg-white shadow-2xl ring-1 ring-slate-900/5 focus:outline-none motion-safe:animate-scale-in sm:rounded-2xl ${wide ? 'sm:max-w-2xl' : 'sm:max-w-lg'}`
        }
      >
        <div className="flex items-start justify-between gap-4 border-b border-slate-100 px-6 py-4">
          <div>
            <h2 id={titleId} className="text-base font-semibold text-slate-900">
              {title}
            </h2>
            {description && (
              <div id={descriptionId} className="mt-1 text-sm text-slate-600">
                {description}
              </div>
            )}
          </div>
          <Button variant="ghost" size="sm" onClick={requestClose} disabled={busy} aria-label="Close" className="-mr-2 !px-2">
            <Icon name="x" size={18} />
          </Button>
        </div>
        {asking && (
          <div role="alert" className="flex flex-wrap items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-6 py-3 text-sm text-amber-900 motion-safe:animate-fade-in">
            <span className="font-medium">Discard your changes in this form?</span>
            <span className="flex gap-2">
              <Button size="sm" onClick={() => setAsking(false)} data-autofocus>Keep editing</Button>
              <Button size="sm" variant="danger" onClick={onClose}>Discard changes</Button>
            </span>
          </div>
        )}
        {children !== undefined && <div className="flex-1 overflow-y-auto px-6 py-5">{children}</div>}
        {footer && <div className="flex flex-wrap justify-end gap-2 rounded-b-2xl border-t border-slate-100 bg-slate-50/60 px-6 py-3.5">{footer}</div>}
      </div>
    </div>
  );
}

/**
 * Confirmation for an action that removes or changes something. It says
 * what will happen and whether it can be undone. For the riskiest
 * actions the user types a word back (`confirmText`) before the button
 * works.
 */
type ConfirmProps = {
  open: boolean;
  title: string;
  /** What will happen, to what, and whether it can be undone. */
  children: ReactNode;
  confirmLabel: string;
  variant?: ButtonVariant;
  /** The exact text the user must type before confirming. */
  confirmText?: string;
  busy?: boolean;
  error?: string | null;
  /** The label of the button that keeps things as they are (default Cancel). */
  cancelLabel?: string;
  onConfirm: () => void;
  onClose: () => void;
};

export function ConfirmDialog({ open, title, children, confirmLabel, variant = 'danger', confirmText, busy = false, error, cancelLabel = 'Cancel', onConfirm, onClose }: ConfirmProps) {
  const [typed, setTyped] = useState('');

  useEffect(() => {
    if (open) setTyped('');
  }, [open]);

  const ready = confirmText === undefined || typed.trim() === confirmText;

  return (
    <Dialog
      open={open}
      title={title}
      onClose={onClose}
      busy={busy}
      guard={false}
      footer={
        <>
          <Button onClick={onClose} disabled={busy} data-autofocus>
            {cancelLabel}
          </Button>
          <Button variant={variant} onClick={onConfirm} busy={busy} disabled={!ready}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="space-y-3 text-sm text-slate-700">
        {children}
        {confirmText !== undefined && <TextField label={`Type ${confirmText} to confirm`} value={typed} onChange={setTyped} autoComplete="off" />}
        {error && (
          <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-2.5 text-red-800">
            {error}
          </p>
        )}
      </div>
    </Dialog>
  );
}
