import { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes, useId } from 'react';
import { FOCUS_RING } from './Button';

/**
 * Form fields for the admin (Phase B31 design system). Each field has a
 * real <label>, and its hint and error are tied to the control with
 * aria-describedby, so a screen reader reads them with the field. An
 * error is text (and an icon-free "Error:" prefix for readers), never a
 * red border alone.
 *
 * Validation here is convenience only (required, min, type). The server
 * decides what is valid; its 422 messages are shown under each field.
 */
const CONTROL =
  'block w-full rounded-md border bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 disabled:bg-slate-100 disabled:text-slate-500';

function controlClass(hasError: boolean, extra = ''): string {
  return `${CONTROL} ${hasError ? 'border-red-500' : 'border-slate-300'} ${FOCUS_RING} ${extra}`;
}

type Shell = { label: string; hint?: ReactNode; error?: string | null; optional?: boolean };

function FieldShell({ id, label, hint, error, optional, children }: Shell & { id: string; children: ReactNode }) {
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-medium text-slate-700">
        {label}
        {optional && <span className="ml-1 font-normal text-slate-500">(optional)</span>}
      </label>
      <div className="mt-1">{children}</div>
      {hint && !error && (
        <p id={`${id}-hint`} className="mt-1 text-xs text-slate-500">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} role="alert" className="mt-1 text-xs font-medium text-red-700">
          <span className="sr-only">Error: </span>
          {error}
        </p>
      )}
    </div>
  );
}

function describedBy(id: string, hint: ReactNode, error?: string | null): string | undefined {
  if (error) return `${id}-error`;

  return hint ? `${id}-hint` : undefined;
}

type TextProps = Shell & Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'value'> & { value: string; onChange: (value: string) => void };

export function TextField({ label, hint, error, optional, value, onChange, className, ...rest }: TextProps) {
  const id = useId();

  return (
    <FieldShell id={id} label={label} hint={hint} error={error} optional={optional}>
      <input
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, hint, error)}
        className={controlClass(Boolean(error), className)}
        {...rest}
      />
    </FieldShell>
  );
}

type AreaProps = Shell & Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'onChange' | 'value'> & { value: string; onChange: (value: string) => void };

export function TextAreaField({ label, hint, error, optional, value, onChange, rows = 4, className, ...rest }: AreaProps) {
  const id = useId();

  return (
    <FieldShell id={id} label={label} hint={hint} error={error} optional={optional}>
      <textarea
        id={id}
        rows={rows}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, hint, error)}
        className={controlClass(Boolean(error), className)}
        {...rest}
      />
    </FieldShell>
  );
}

export type Option = { value: string; label: string; disabled?: boolean };

type SelectProps = Shell &
  Omit<SelectHTMLAttributes<HTMLSelectElement>, 'onChange' | 'value'> & { value: string; onChange: (value: string) => void; options: Option[]; placeholder?: string };

export function SelectField({ label, hint, error, optional, value, onChange, options, placeholder, className, ...rest }: SelectProps) {
  const id = useId();

  return (
    <FieldShell id={id} label={label} hint={hint} error={error} optional={optional}>
      <select
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedBy(id, hint, error)}
        className={controlClass(Boolean(error), className)}
        {...rest}
      >
        {placeholder !== undefined && <option value="">{placeholder}</option>}
        {options.map((option) => (
          <option key={option.value} value={option.value} disabled={option.disabled}>
            {option.label}
          </option>
        ))}
      </select>
    </FieldShell>
  );
}

type CheckProps = { label: string; hint?: ReactNode; checked: boolean; onChange: (checked: boolean) => void; disabled?: boolean; error?: string | null };

export function CheckboxField({ label, hint, checked, onChange, disabled, error }: CheckProps) {
  const id = useId();

  return (
    <div className="flex items-start gap-2">
      <input
        id={id}
        type="checkbox"
        checked={checked}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked)}
        aria-describedby={describedBy(id, hint, error)}
        className={`mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600 ${FOCUS_RING}`}
      />
      <div>
        <label htmlFor={id} className="text-sm font-medium text-slate-700">
          {label}
        </label>
        {hint && !error && (
          <p id={`${id}-hint`} className="text-xs text-slate-500">
            {hint}
          </p>
        )}
        {error && (
          <p id={`${id}-error`} role="alert" className="text-xs font-medium text-red-700">
            {error}
          </p>
        )}
      </div>
    </div>
  );
}

/** An on/off setting. A real checkbox with role="switch", so the keyboard and readers treat it as one. */
export function SwitchField({ label, hint, checked, onChange, disabled }: CheckProps) {
  const id = useId();

  return (
    <div className="flex items-start justify-between gap-4">
      <div>
        <label htmlFor={id} className="text-sm font-medium text-slate-700">
          {label}
        </label>
        {hint && (
          <p id={`${id}-hint`} className="text-xs text-slate-500">
            {hint}
          </p>
        )}
      </div>
      <span className="relative inline-flex shrink-0 items-center">
        <input
          id={id}
          type="checkbox"
          role="switch"
          checked={checked}
          disabled={disabled}
          onChange={(e) => onChange(e.target.checked)}
          aria-describedby={hint ? `${id}-hint` : undefined}
          className={`peer h-6 w-11 cursor-pointer appearance-none rounded-full bg-slate-300 transition-colors checked:bg-indigo-600 disabled:cursor-not-allowed disabled:opacity-50 motion-reduce:transition-none ${FOCUS_RING}`}
        />
        <span
          aria-hidden="true"
          className="pointer-events-none absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5 motion-reduce:transition-none"
        />
        <span className="ml-2 w-6 text-xs text-slate-600">{checked ? 'On' : 'Off'}</span>
      </span>
    </div>
  );
}

/** A form-level error (not tied to one field), with the field messages listed when there are several. */
export function FormError({ message, errors }: { message?: string | null; errors?: Record<string, string> }) {
  const list = Object.values(errors ?? {});
  if (!message && list.length === 0) return null;

  return (
    <div role="alert" className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800">
      {message && <p className="font-medium">{message}</p>}
      {list.length > 1 && (
        <ul className="mt-1 list-disc pl-5">
          {list.map((text) => (
            <li key={text}>{text}</li>
          ))}
        </ul>
      )}
    </div>
  );
}
