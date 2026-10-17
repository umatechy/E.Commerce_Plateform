import { FormEvent, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import Button from '@/Components/ui/Button';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, SelectField, SwitchField, TextAreaField, TextField } from '@/Components/ui/Form';
import Badge, { humanize } from '@/Components/ui/Badge';
import Icon, { type IconName } from '@/Components/ui/Icon';
import { FOCUS_RING } from '@/Components/ui/Button';
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
 *
 * UI-9: settings are shown in groups (a card each, with a side menu on
 * large screens) and each current value as a chip. A setting's group is
 * `info.group`, else the first part of its key.
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
  /** The group it is listed under (a key of `groups`). */
  group?: string;
};

export type SettingGroup = { title: string; description?: string; icon?: IconName };

type Props = {
  listPath: string;
  /** PUT `${updatePath}/{key}` with {value, reason}. */
  updatePath: string;
  info: Record<string, SettingInfo>;
  canManage: boolean;
  /** Where a setting's history is read and an earlier value put back (store and platform settings). */
  historyPath?: (key: string) => string;
  rollbackPath?: (revisionId: number) => string;
  /** Group titles in the order they are shown. Groups not named here follow, titled from the key. */
  groups?: Record<string, SettingGroup>;
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
          <p role="note" className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
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
            <li key={revision.id} className="rounded-xl border border-slate-200 p-3.5">
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

/** The current value as a chip: On/Off coloured, "Not set" muted. */
function ValueChip({ setting }: { setting: Setting }) {
  const text = show(setting);
  const muted = text === 'Not set' || text === 'None';
  const tone = setting.type === 'boolean' ? (setting.value ? 'bg-emerald-50 text-emerald-800 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200') : muted ? 'bg-slate-50 text-slate-500 ring-slate-200' : 'bg-brand-50 text-brand-800 ring-brand-100';

  return (
    <span className={`inline-flex max-w-full items-center gap-1.5 break-all rounded-lg px-2.5 py-1 text-sm font-medium ring-1 ring-inset ${tone}`}>
      {setting.type === 'boolean' && <span aria-hidden="true" className={`h-1.5 w-1.5 rounded-full ${setting.value ? 'bg-emerald-500' : 'bg-slate-400'}`} />}
      {text}
    </span>
  );
}

const groupId = (key: string) => `settings-${key.replace(/[^a-z0-9]+/gi, '-')}`;

export default function SettingsEditor({ listPath, updatePath, info, canManage, historyPath, rollbackPath, groups = {} }: Props) {
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

  // Settings in their groups: the named groups first, in the order given, then the rest by key.
  const grouped = useMemo(() => {
    const byGroup = new Map<string, Setting[]>();
    for (const setting of settings) {
      const group = info[setting.key]?.group ?? setting.key.split('.')[0];
      byGroup.set(group, [...(byGroup.get(group) ?? []), setting]);
    }
    const named = Object.keys(groups).filter((key) => byGroup.has(key));
    const rest = [...byGroup.keys()].filter((key) => !(key in groups)).sort();

    return [...named, ...rest].map((key) => ({ key, meta: groups[key] ?? { title: humanize(key) }, items: byGroup.get(key) ?? [] }));
    // `info` and `groups` are constants of the page that renders this.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [settings]);

  if (state.error) return state.errorStatus === 403 ? <AccessNotice message={state.error} /> : <ErrorPanel message={state.error} onRetry={state.reload} />;
  if (state.data === null) return <Skeleton lines={5} />;

  const withMenu = grouped.length > 2;

  return (
    <>
      {state.data.data.length > 6 && (
        <div className="relative mb-5 max-w-md">
          <TextField label="Find a setting" type="search" value={search} onChange={setSearch} placeholder="For example: currency, returns, badge" className="pl-9" />
          <Icon name="search" size={16} className="pointer-events-none absolute bottom-3 left-3 text-slate-400" />
        </div>
      )}

      <div className={withMenu ? 'lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-8' : ''}>
        {withMenu && (
          <nav aria-label="Setting groups" className="hidden lg:block">
            <ul className="sticky top-24 space-y-0.5 text-sm">
              {grouped.map((group) => (
                <li key={group.key}>
                  <a href={`#${groupId(group.key)}`} className={`flex items-center justify-between gap-2 rounded-lg px-3 py-2 font-medium text-slate-600 transition-colors hover:bg-white hover:text-slate-900 hover:shadow-sm motion-reduce:transition-none ${FOCUS_RING}`}>
                    <span className="flex min-w-0 items-center gap-2.5">
                      <Icon name={group.meta.icon ?? 'sliders'} size={16} className="flex-none text-slate-400" />
                      <span className="leading-snug">{group.meta.title}</span>
                    </span>
                    <span className="flex-none rounded-full bg-slate-200/70 px-1.5 text-[11px] text-slate-600">{group.items.length}</span>
                  </a>
                </li>
              ))}
            </ul>
          </nav>
        )}

        <div className="space-y-6">
          {grouped.map((group) => (
            <section key={group.key} id={groupId(group.key)} aria-labelledby={`${groupId(group.key)}-title`} className="scroll-mt-24 overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
              <div className="flex items-start gap-3 border-b border-slate-100 bg-slate-50/60 px-5 py-4">
                <span aria-hidden="true" className="flex h-9 w-9 flex-none items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-brand-100">
                  <Icon name={group.meta.icon ?? 'sliders'} size={18} />
                </span>
                <div className="min-w-0">
                  <h2 id={`${groupId(group.key)}-title`} className="text-[15px] font-semibold text-slate-900">{group.meta.title}</h2>
                  {group.meta.description && <p className="mt-0.5 text-xs text-slate-500">{group.meta.description}</p>}
                </div>
              </div>
              <ul className="divide-y divide-slate-100">
                {group.items.map((setting) => {
                  const details = infoFor(setting);

                  return (
                    <li key={setting.key} className="flex flex-col gap-3 px-5 py-4 transition-colors hover:bg-slate-50/60 motion-reduce:transition-none sm:flex-row sm:items-center sm:justify-between">
                      <div className="min-w-0 max-w-2xl">
                        <p className="font-medium text-slate-900">
                          {details.label} {setting.type === 'secret' && <Badge tone="amber">Secret</Badge>}
                        </p>
                        <p className="mt-0.5 text-sm leading-relaxed text-slate-500">{details.description}</p>
                      </div>
                      <div className="flex flex-wrap items-center gap-2 sm:flex-none sm:justify-end">
                        <span className="sr-only">Now: </span>
                        <ValueChip setting={setting} />
                        {historyPath && (
                          <Button size="sm" variant="ghost" onClick={() => setHistory(setting)} title="History" className="!px-2">
                            <Icon name="clock" size={16} /><span className="sr-only">History of {details.label}</span>
                          </Button>
                        )}
                        {canManage && <Button size="sm" onClick={() => setEditing(setting)}>Change<span className="sr-only"> {details.label}</span></Button>}
                      </div>
                    </li>
                  );
                })}
              </ul>
            </section>
          ))}
          {settings.length === 0 && (
            <p className="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">No setting matches “{search}”.</p>
          )}
        </div>
      </div>

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
