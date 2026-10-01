import { FormEvent, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import Button from '@/Components/ui/Button';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, SelectField, SwitchField, TextAreaField, TextField } from '@/Components/ui/Form';
import Badge, { humanize } from '@/Components/ui/Badge';
import { ErrorPanel, Skeleton, AccessNotice } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { formatDateTime } from '@/lib/datetime';

/**
 * Module 33 §64 "Configuration UI" for the typed settings of Phase B17.
 *
 * The list of settings comes from the server (its SettingRegistry): the
 * page can show and change only the keys the API returns, each with the
 * input its type calls for. There is no free "key = value" box. The
 * server validates every value and keeps the history; a secret is never
 * sent to the browser, only whether it is set.
 */
export type Setting = { key: string; scope: string; type: 'string' | 'boolean' | 'integer' | 'string_array' | 'secret'; value: unknown; configured: boolean | null; masked: string | null };
type Revision = { id: number; value: unknown; changed_by_user_id: number | null; reason: string | null; created_at: string };

export type SettingInfo = {
  label: string;
  description: string;
  /** Shown before saving a change that has wide effects. */
  warning?: string;
  /** A fixed list to choose from, instead of free text. */
  choices?: string[];
  itemHint?: string;
};

type Props = {
  listPath: string;
  /** PUT `${updatePath}/{key}` with {value, reason}. */
  updatePath: string;
  info: Record<string, SettingInfo>;
  canManage: boolean;
  /** Store settings keep a history that can be restored; platform settings have no history API. */
  historyPath?: (key: string) => string;
  rollbackPath?: (revisionId: number) => string;
};

function show(setting: Setting): string {
  if (setting.type === 'secret') return setting.configured ? 'Set (hidden)' : 'Not set';
  if (setting.type === 'boolean') return setting.value ? 'On' : 'Off';
  if (Array.isArray(setting.value)) return setting.value.length === 0 ? 'None' : setting.value.join(', ');
  if (setting.value === null || setting.value === undefined || setting.value === '') return 'Not set';

  return String(setting.value);
}

function EditDialog({ setting, info, updatePath, onClose, onDone }: { setting: Setting; info: SettingInfo; updatePath: string; onClose: () => void; onDone: () => void }) {
  const initial = setting.type === 'boolean' ? setting.value === true : setting.type === 'string_array' ? (Array.isArray(setting.value) ? setting.value.join('\n') : '') : setting.type === 'secret' ? '' : String(setting.value ?? '');
  const form = useForm<{ value: string | boolean; reason: string }>({ value: initial, reason: '' });
  const [local, setLocal] = useState<string | null>(null);

  async function save(event: FormEvent) {
    event.preventDefault();
    const raw = form.values.value;
    let value: unknown = raw;
    if (setting.type === 'integer') {
      value = Number(raw);
      if (!Number.isInteger(value) || (value as number) < 1) {
        setLocal('Enter a whole number of 1 or more.');

        return;
      }
    } else if (setting.type === 'string_array') {
      value = String(raw).split('\n').map((line) => line.trim()).filter((line) => line !== '');
      if ((value as string[]).length === 0) {
        setLocal('Enter at least one value. The server does not accept an empty list.');

        return;
      }
    } else if (setting.type !== 'boolean' && String(raw).trim() === '') {
      setLocal('Enter a value.');

      return;
    }
    setLocal(null);
    const saved = await form.submit(() => adminFetch(`${updatePath}/${setting.key}`, { method: 'PUT', body: { value, reason: form.values.reason === '' ? null : form.values.reason } }), 'Setting saved.');
    if (saved !== undefined) onDone();
  }

  const error = local ?? form.errors.value;

  return (
    <Dialog open title={info.label} description={info.description} onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        {info.warning && (
          <p role="note" className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
            <strong>Before you change this: </strong>
            {info.warning}
          </p>
        )}
        {setting.type === 'boolean' ? (
          <SwitchField label={info.label} checked={form.values.value === true} onChange={(v) => form.set('value', v)} />
        ) : info.choices ? (
          <SelectField label="Value" value={String(form.values.value)} onChange={(v) => form.set('value', v)} error={error} options={info.choices.map((choice) => ({ value: choice, label: choice }))} data-autofocus />
        ) : setting.type === 'string_array' ? (
          <TextAreaField label="Values" rows={5} value={String(form.values.value)} onChange={(v) => form.set('value', v)} error={error} hint={info.itemHint ?? 'One per line.'} data-autofocus />
        ) : (
          <TextField
            label={setting.type === 'secret' ? 'New value' : 'Value'}
            type={setting.type === 'secret' ? 'password' : setting.type === 'integer' ? 'number' : 'text'}
            min={setting.type === 'integer' ? 1 : undefined}
            autoComplete="off"
            value={String(form.values.value)}
            onChange={(v) => form.set('value', v)}
            error={error}
            hint={setting.type === 'secret' ? 'The current value is never shown. Typing a new one replaces it.' : info.itemHint}
            data-autofocus
          />
        )}
        <TextField label="Reason for the change" optional value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} maxLength={500} hint="Kept with the change in the audit trail." />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save</Button>
        </div>
      </form>
    </Dialog>
  );
}

function HistoryDialog({ setting, info, path, rollbackPath, canManage, onClose, onRestored }: { setting: Setting; info: SettingInfo; path: string; rollbackPath?: (id: number) => string; canManage: boolean; onClose: () => void; onRestored: () => void }) {
  const state = useApi<{ data: Revision[] }>(path);
  const [restoring, setRestoring] = useState<Revision | null>(null);
  const { busy, run } = useAction();
  const text = (value: unknown) => (setting.type === 'secret' ? 'Hidden' : typeof value === 'boolean' ? (value ? 'On' : 'Off') : Array.isArray(value) ? value.join(', ') : String(value ?? '—'));

  return (
    <Dialog open side title={`History: ${info.label}`} onClose={onClose}>
      {state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : state.data === null ? (
        <Skeleton lines={3} />
      ) : state.data.data.length === 0 ? (
        <p className="text-sm text-slate-600">This setting has never been changed. It has its standard value.</p>
      ) : (
        <ol className="space-y-2 text-sm">
          {state.data.data.map((revision) => (
            <li key={revision.id} className="rounded-md border border-slate-200 p-3">
              <p className="font-medium text-slate-900">{text(revision.value)}</p>
              <p className="text-xs text-slate-500">{formatDateTime(revision.created_at)}{revision.reason ? ` · ${revision.reason}` : ''}</p>
              {canManage && rollbackPath && setting.type !== 'secret' && (
                <Button size="sm" className="mt-2" onClick={() => setRestoring(revision)}>Use this value again</Button>
              )}
            </li>
          ))}
        </ol>
      )}
      <ConfirmDialog
        open={restoring !== null}
        title="Use this earlier value again?"
        confirmLabel="Use this value"
        variant="primary"
        busy={busy === 'rollback'}
        onClose={() => setRestoring(null)}
        onConfirm={() =>
          restoring && rollbackPath &&
          run('rollback', () => adminFetch(rollbackPath(restoring.id), { method: 'POST' }), { success: 'Setting restored.' }).then((result) => {
            if (result !== undefined) {
              setRestoring(null);
              onRestored();
            }
          })
        }
      >
        <p>
          {info.label} becomes <strong>{restoring ? text(restoring.value) : ''}</strong> again. The change is recorded like any other.
        </p>
      </ConfirmDialog>
    </Dialog>
  );
}

export default function SettingsEditor({ listPath, updatePath, info, canManage, historyPath, rollbackPath }: Props) {
  const state = useApi<{ data: Setting[] }>(listPath);
  const [editing, setEditing] = useState<Setting | null>(null);
  const [history, setHistory] = useState<Setting | null>(null);
  const [search, setSearch] = useState('');

  const infoFor = (setting: Setting): SettingInfo => info[setting.key] ?? { label: humanize(setting.key), description: 'A setting this page has no description for yet.' };
  const settings = useMemo(() => {
    const all = state.data?.data ?? [];
    const term = search.trim().toLowerCase();

    return term === '' ? all : all.filter((setting) => `${setting.key} ${info[setting.key]?.label ?? ''} ${info[setting.key]?.description ?? ''}`.toLowerCase().includes(term));
    // `info` is a constant of the page that renders this.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state.data, search]);

  if (state.error) return state.errorStatus === 403 ? <AccessNotice message={state.error} /> : <ErrorPanel message={state.error} onRetry={state.reload} />;
  if (state.data === null) return <Skeleton lines={5} />;

  return (
    <>
      {state.data.data.length > 6 && (
        <div className="mb-4 max-w-sm">
          <TextField label="Find a setting" type="search" value={search} onChange={setSearch} />
        </div>
      )}
      <ul className="divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
        {settings.map((setting) => {
          const details = infoFor(setting);

          return (
            <li key={setting.key} className="flex flex-wrap items-start justify-between gap-3 p-4">
              <div className="min-w-0 max-w-2xl">
                <p className="font-medium text-slate-900">
                  {details.label} {setting.type === 'secret' && <Badge tone="amber">Secret</Badge>}
                </p>
                <p className="mt-0.5 text-sm text-slate-600">{details.description}</p>
                <p className="mt-1 break-words text-sm text-slate-900">
                  <span className="text-slate-500">Now: </span>
                  {show(setting)}
                </p>
              </div>
              <div className="flex flex-wrap gap-2">
                {historyPath && <Button size="sm" variant="ghost" onClick={() => setHistory(setting)}>History<span className="sr-only"> of {details.label}</span></Button>}
                {canManage && <Button size="sm" onClick={() => setEditing(setting)}>Change<span className="sr-only"> {details.label}</span></Button>}
              </div>
            </li>
          );
        })}
        {settings.length === 0 && <li className="p-4 text-sm text-slate-600">No setting matches.</li>}
      </ul>

      {editing && (
        <EditDialog
          setting={editing}
          info={infoFor(editing)}
          updatePath={updatePath}
          onClose={() => setEditing(null)}
          onDone={() => {
            setEditing(null);
            state.reload();
            // Currency and timezone are shared with every page: fetch them again.
            router.reload({ only: ['auth'] });
          }}
        />
      )}
      {history && historyPath && (
        <HistoryDialog
          setting={history}
          info={infoFor(history)}
          path={historyPath(history.key)}
          rollbackPath={rollbackPath}
          canManage={canManage}
          onClose={() => setHistory(null)}
          onRestored={() => {
            setHistory(null);
            state.reload();
            router.reload({ only: ['auth'] });
          }}
        />
      )}
    </>
  );
}
