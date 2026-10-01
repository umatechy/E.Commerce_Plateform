import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Dialog from '@/Components/ui/Dialog';
import { CheckboxField, FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import Badge, { StatusBadge, humanize } from '@/Components/ui/Badge';
import { EmptyPanel, Tabs } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useUrlState } from '@/lib/useUrlState';
import { useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { dateTimeOrDash, formatDateTime } from '@/lib/datetime';
import { options } from '@/lib/labels';

/**
 * Module 21 §51 "Admin Communication Center": the messages the store
 * sent and their delivery, and the store's message templates
 * (/api/v1/notification-messages, /notification-templates).
 *
 * The list shows a message's subject and status, not its text: message
 * bodies can hold personal details. Delivery is shown as attempts and
 * their result; queues and retries stay the platform's business. A
 * channel without a provider (the backend has email only) records its
 * messages as failed; nothing here pretends otherwise.
 */
type Message = { id: string; message_type: string; channel: string; subject: string | null; status: string; sent_at: string | null; created_at: string };
type Attempt = { attempt_number: number; provider: string | null; result: string; failure_code: string | null; occurred_at: string };
type Template = { id: number; key: string; channel: string; locale: string; subject: string | null; body: string; is_published: boolean };

const CHANNELS = ['email', 'sms', 'whatsapp', 'push', 'in_app'] as const;

function AttemptsDrawer({ message, onClose }: { message: Message; onClose: () => void }) {
  const state = useApi<{ data: Attempt[] }>(`/notification-messages/${message.id}/attempts`);
  const columns: Column<Attempt>[] = [
    { key: 'number', header: 'Attempt', render: (attempt) => `#${attempt.attempt_number}` },
    { key: 'result', header: 'Result', priority: true, render: (attempt) => <StatusBadge status={attempt.result} /> },
    { key: 'when', header: 'When', render: (attempt) => formatDateTime(attempt.occurred_at) },
    { key: 'code', header: 'Reason', render: (attempt) => (attempt.failure_code ? humanize(attempt.failure_code) : '—') },
  ];

  return (
    <Dialog open side title="Delivery attempts" description={message.subject ?? humanize(message.message_type)} onClose={onClose}>
      <DataTable caption="Delivery attempts" columns={columns} rows={state.data?.data ?? null} rowKey={(attempt) => attempt.attempt_number} loading={state.loading} error={state.error} onRetry={state.reload} empty={<p className="text-sm text-slate-600">No delivery has been attempted yet.</p>} />
    </Dialog>
  );
}

function Messages() {
  const [page, setPage] = useState(1);
  const list = usePagedApi<Message>('/notification-messages', { page });
  const [open, setOpen] = useState<Message | null>(null);
  const columns: Column<Message>[] = [
    {
      key: 'subject',
      header: 'Message',
      render: (message) => (
        <div>
          <span className="font-medium">{message.subject ?? '(no subject)'}</span>
          <p className="text-xs text-slate-500">{humanize(message.message_type)} · {humanize(message.channel)}</p>
        </div>
      ),
    },
    { key: 'status', header: 'Status', priority: true, render: (message) => <StatusBadge status={message.status} /> },
    { key: 'created', header: 'Created', render: (message) => formatDateTime(message.created_at) },
    { key: 'sent', header: 'Sent', render: (message) => dateTimeOrDash(message.sent_at) },
    { key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true, render: (message) => <Button size="sm" variant="ghost" onClick={() => setOpen(message)}>Delivery</Button> },
  ];

  return (
    <>
      <DataTable caption="Messages" columns={columns} rows={list.rows} rowKey={(message) => message.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<EmptyPanel title="No messages yet" description="Order confirmations, campaign emails and other messages appear here once they are sent." />} />
      <Pagination meta={list.meta} disabled={list.loading} onPage={setPage} />
      {open && <AttemptsDrawer message={open} onClose={() => setOpen(null)} />}
    </>
  );
}

const BLANK = { key: '', channel: 'email', locale: 'en', subject: '', body: '', is_published: false };

function Templates({ canManage }: { canManage: boolean }) {
  const list = useApi<{ data: Template[] }>('/notification-templates');
  const [editing, setEditing] = useState<Template | 'new' | null>(null);
  const form = useForm(BLANK);

  function open(target: Template | 'new') {
    form.reset(target === 'new' ? BLANK : { key: target.key, channel: target.channel, locale: target.locale, subject: target.subject ?? '', body: target.body, is_published: target.is_published });
    setEditing(target);
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    const target = editing;
    const v = form.values;
    const body = { key: v.key, channel: v.channel, locale: v.locale, subject: v.subject === '' ? null : v.subject, body: v.body, is_published: v.is_published };
    const saved = await form.submit(
      () => adminFetch(target === 'new' ? '/notification-templates' : `/notification-templates/${(target as Template).id}`, { method: target === 'new' ? 'POST' : 'PUT', body }),
      'Template saved.',
    );
    if (saved !== undefined) {
      setEditing(null);
      list.reload();
    }
  }

  const readOnly = editing !== null && editing !== 'new' && editing.is_published;
  const columns: Column<Template>[] = [
    {
      key: 'key',
      header: 'Template',
      render: (template) => (
        <div>
          <span className="font-mono text-sm font-medium">{template.key}</span>
          <p className="text-xs text-slate-600">{template.subject ?? '(no subject)'}</p>
        </div>
      ),
    },
    { key: 'channel', header: 'Channel', render: (template) => `${humanize(template.channel)} · ${template.locale}` },
    { key: 'state', header: 'Status', priority: true, render: (template) => <Badge tone={template.is_published ? 'green' : 'amber'}>{template.is_published ? 'Published' : 'Draft'}</Badge> },
    { key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true, render: (template) => <Button size="sm" variant="ghost" onClick={() => open(template)}>{canManage && !template.is_published ? 'Edit' : 'View'}<span className="sr-only"> {template.key}</span></Button> },
  ];

  return (
    <>
      {canManage && (
        <div className="mb-3 flex justify-end">
          <Button variant="primary" onClick={() => open('new')}>Add template</Button>
        </div>
      )}
      <DataTable caption="Message templates" columns={columns} rows={list.data?.data ?? null} rowKey={(template) => template.id} loading={list.loading} error={list.error} onRetry={list.reload} empty={<EmptyPanel title="No templates of your own" description="Without one, the platform's standard wording is used." />} />

      <Dialog
        open={editing !== null}
        wide
        title={editing === 'new' ? 'Add template' : readOnly ? 'Template (published)' : 'Edit template'}
        description={readOnly ? 'A published template cannot be changed. To change the wording, add a new template.' : undefined}
        onClose={() => setEditing(null)}
        busy={form.busy}
      >
        <form onSubmit={save} className="space-y-4" noValidate>
          <FormError message={form.formError} />
          <div className="grid gap-4 sm:grid-cols-3">
            <TextField label="Key" value={form.values.key} onChange={(v) => form.set('key', v)} error={form.errors.key} required maxLength={255} disabled={readOnly} hint="Which message this is, e.g. order.confirmed" data-autofocus />
            <SelectField label="Channel" value={form.values.channel} onChange={(v) => form.set('channel', v)} error={form.errors.channel} options={options(CHANNELS)} disabled={readOnly} />
            <TextField label="Language" value={form.values.locale} onChange={(v) => form.set('locale', v)} error={form.errors.locale} maxLength={8} disabled={readOnly} />
          </div>
          <TextField label="Subject" optional value={form.values.subject} onChange={(v) => form.set('subject', v)} error={form.errors.subject} maxLength={255} disabled={readOnly} />
          <TextAreaField label="Text" rows={10} value={form.values.body} onChange={(v) => form.set('body', v)} error={form.errors.body} required maxLength={10000} disabled={readOnly} />
          {!readOnly && <CheckboxField label="Publish this template" hint="Once published, a template is used for sending and can no longer be edited." checked={form.values.is_published} onChange={(v) => form.set('is_published', v)} />}
          <div className="flex justify-end gap-2">
            <Button onClick={() => setEditing(null)} disabled={form.busy}>{readOnly || !canManage ? 'Close' : 'Cancel'}</Button>
            {canManage && !readOnly && <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save template</Button>}
          </div>
        </form>
      </Dialog>
    </>
  );
}

export default function Index() {
  const access = useAccess();
  const [state, setState] = useUrlState({ tab: 'messages' });

  return (
    <AdminPage title="Messages" description="What your store sent to customers, and the wording it uses.">
      <Tabs label="Messages sections" tabs={[{ id: 'messages', label: 'Sent messages' }, { id: 'templates', label: 'Templates' }]} active={state.tab === 'templates' ? 'templates' : 'messages'} onChange={(tab) => setState({ tab })} />
      <div role="tabpanel">{state.tab === 'templates' ? <Templates canManage={access.can('notifications.manage')} /> : <Messages />}</div>
    </AdminPage>
  );
}
