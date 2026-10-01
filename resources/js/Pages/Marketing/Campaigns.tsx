import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Dialog, { ConfirmDialog } from '@/Components/ui/Dialog';
import { FormError, SelectField, TextAreaField, TextField } from '@/Components/ui/Form';
import { StatusBadge, humanize } from '@/Components/ui/Badge';
import { EmptyPanel } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi, usePagedApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { dateTimeOrDash, displayTimezoneName } from '@/lib/datetime';
import { options } from '@/lib/labels';

/**
 * Module 15 "Marketing & Customer Engagement": email campaigns
 * (/api/v1/campaigns). The server's campaign state machine decides what
 * a campaign may do next (activate, pause, resume, cancel); an action it
 * refuses comes back as its message. Sending goes through the
 * notification pipeline and honours each customer's marketing consent.
 */
type Campaign = {
  id: string;
  name: string;
  objective: string;
  channel: string;
  status: string;
  audience_type: string;
  marketing_segment_id: number | null;
  subject: string;
  scheduled_at: string | null;
  activated_at: string | null;
  completed_at: string | null;
  recipient_count?: number;
};

type Segment = { id: number; name: string };
type PromotionOption = { id: string; internal_id: number; name: string };
type Recipient = { customer_id: string | null; status: string; queued_at: string | null };

const OBJECTIVES = ['awareness', 'new_customer_acquisition', 'first_purchase', 'repeat_purchase', 'abandoned_cart_recovery', 'product_promotion', 'category_promotion', 'seasonal_sale', 'customer_reactivation'] as const;

/** What the server's state machine may allow from each status. It decides; this only chooses which buttons to show. */
const ACTIONS: Record<string, ('activate' | 'pause' | 'resume' | 'cancel')[]> = {
  draft: ['activate', 'cancel'],
  scheduled: ['pause', 'cancel'],
  active: ['pause', 'cancel'],
  paused: ['resume', 'cancel'],
};

function CreateDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const segments = useApi<{ data: Segment[] }>('/marketing/segments');
  const promotions = usePagedApi<PromotionOption>('/promotions');
  const form = useForm({ name: '', objective: 'awareness', audience_type: 'all_customers', marketing_segment_id: '', promotion_id: '', subject: '', body: '' });
  const { values, set } = form;

  async function save(event: FormEvent) {
    event.preventDefault();
    const body = {
      name: values.name,
      objective: values.objective,
      audience_type: values.audience_type,
      marketing_segment_id: values.audience_type === 'segment' && values.marketing_segment_id !== '' ? Number(values.marketing_segment_id) : null,
      promotion_id: values.promotion_id === '' ? null : Number(values.promotion_id),
      subject: values.subject,
      body: values.body,
    };
    if ((await form.submit(() => adminFetch('/campaigns', { method: 'POST', body }), 'Campaign saved as a draft.')) !== undefined) onDone();
  }

  return (
    <Dialog open wide title="Add campaign" description="Saved as a draft. Nothing is sent until you activate it." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label="Name" value={values.name} onChange={(v) => set('name', v)} error={form.errors.name} required maxLength={255} hint="For your own reference." data-autofocus />
          <SelectField label="Goal" value={values.objective} onChange={(v) => set('objective', v)} error={form.errors.objective} options={options(OBJECTIVES)} />
          <SelectField label="Send to" value={values.audience_type} onChange={(v) => set('audience_type', v)} error={form.errors.audience_type} options={[{ value: 'all_customers', label: 'All customers' }, { value: 'segment', label: 'A segment' }]} />
          {values.audience_type === 'segment' && (
            <SelectField
              label="Segment"
              value={values.marketing_segment_id}
              onChange={(v) => set('marketing_segment_id', v)}
              error={form.errors.marketing_segment_id ?? (segments.error ? 'Segments could not be loaded.' : undefined)}
              placeholder="Choose a segment"
              options={(segments.data?.data ?? []).map((segment) => ({ value: String(segment.id), label: segment.name }))}
              required
            />
          )}
          <SelectField
            label="Promotion to mention"
            optional
            value={values.promotion_id}
            onChange={(v) => set('promotion_id', v)}
            error={form.errors.promotion_id}
            placeholder="None"
            hint="Your most recent promotions."
            options={(promotions.rows ?? []).map((promotion) => ({ value: String(promotion.internal_id), label: promotion.name }))}
          />
        </div>
        <TextField label="Email subject" value={values.subject} onChange={(v) => set('subject', v)} error={form.errors.subject} required maxLength={255} />
        <TextAreaField label="Email text" rows={8} value={values.body} onChange={(v) => set('body', v)} error={form.errors.body} required maxLength={5000} hint="Plain text, up to 5000 characters." />
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Save draft</Button>
        </div>
      </form>
    </Dialog>
  );
}

function ActivateDialog({ campaign, onClose, onDone }: { campaign: Campaign; onClose: () => void; onDone: () => void }) {
  const form = useForm({ when: 'now', scheduled_at: '' });

  async function save(event: FormEvent) {
    event.preventDefault();
    if (form.values.when === 'later' && form.values.scheduled_at === '') {
      form.setFormError('Choose the date and time to send.');

      return;
    }
    const body = form.values.when === 'later' ? { scheduled_at: form.values.scheduled_at } : {};
    if ((await form.submit(() => adminFetch(`/campaigns/${campaign.id}/activate`, { method: 'POST', body }), form.values.when === 'later' ? 'Campaign scheduled.' : 'Campaign activated.')) !== undefined) onDone();
  }

  return (
    <Dialog open title={`Send "${campaign.name}"?`} description="Emails go to the customers in the audience who agreed to marketing email. Sent emails cannot be recalled." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} />
        <SelectField label="When" value={form.values.when} onChange={(v) => form.set('when', v)} options={[{ value: 'now', label: 'Now' }, { value: 'later', label: 'At a date and time' }]} />
        {form.values.when === 'later' && (
          <TextField label="Send at" type="datetime-local" value={form.values.scheduled_at} onChange={(v) => form.set('scheduled_at', v)} error={form.errors.scheduled_at} hint={`In your store's timezone (${displayTimezoneName()}).`} required />
        )}
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy} data-autofocus>Not yet</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Working…">{form.values.when === 'later' ? 'Schedule campaign' : 'Send campaign'}</Button>
        </div>
      </form>
    </Dialog>
  );
}

function RecipientsDrawer({ campaign, onClose }: { campaign: Campaign; onClose: () => void }) {
  const [page, setPage] = useState(1);
  const list = usePagedApi<Recipient>(`/campaigns/${campaign.id}/recipients`, { page });
  const columns: Column<Recipient>[] = [
    { key: 'customer', header: 'Customer reference', render: (recipient) => <span className="font-mono text-xs">{recipient.customer_id ?? '—'}</span> },
    { key: 'status', header: 'Status', priority: true, render: (recipient) => <StatusBadge status={recipient.status} /> },
    { key: 'queued', header: 'Queued', render: (recipient) => dateTimeOrDash(recipient.queued_at) },
  ];

  return (
    <Dialog open side title="Recipients" description={campaign.name} onClose={onClose}>
      <DataTable caption="Campaign recipients" columns={columns} rows={list.rows} rowKey={(recipient) => `${recipient.customer_id}-${recipient.queued_at}`} loading={list.loading} error={list.error} onRetry={list.reload} empty={<p className="text-sm text-slate-600">No recipients yet. They are listed once the campaign starts sending.</p>} />
      <Pagination meta={list.meta} disabled={list.loading} onPage={setPage} />
    </Dialog>
  );
}

export default function Campaigns() {
  const access = useAccess();
  const canManage = access.can('marketing.manage');
  const [page, setPage] = useState(1);
  const list = usePagedApi<Campaign>('/campaigns', { page });
  const [creating, setCreating] = useState(false);
  const [activating, setActivating] = useState<Campaign | null>(null);
  const [cancelling, setCancelling] = useState<Campaign | null>(null);
  const [recipients, setRecipients] = useState<Campaign | null>(null);
  const { busy, run } = useAction();

  function act(campaign: Campaign, action: 'pause' | 'resume' | 'cancel') {
    return run(`${action}:${campaign.id}`, () => adminFetch(`/campaigns/${campaign.id}/${action}`, { method: 'POST' }), { success: `Campaign ${action === 'pause' ? 'paused' : action === 'resume' ? 'resumed' : 'cancelled'}.` }).then((result) => {
      if (result !== undefined) list.reload();

      return result;
    });
  }

  const columns: Column<Campaign>[] = [
    {
      key: 'name',
      header: 'Campaign',
      render: (campaign) => (
        <div>
          <span className="font-medium">{campaign.name}</span>
          <p className="text-xs text-slate-600">{campaign.subject}</p>
        </div>
      ),
    },
    { key: 'status', header: 'Status', priority: true, render: (campaign) => <StatusBadge status={campaign.status} /> },
    { key: 'goal', header: 'Goal', render: (campaign) => humanize(campaign.objective) },
    { key: 'recipients', header: 'Recipients', align: 'right', render: (campaign) => campaign.recipient_count ?? '—' },
    { key: 'scheduled', header: 'Scheduled', render: (campaign) => dateTimeOrDash(campaign.scheduled_at) },
    {
      key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true,
      render: (campaign) => {
        const actions = canManage ? (ACTIONS[campaign.status] ?? []) : [];

        return (
          <span className="flex flex-wrap justify-end gap-1">
            <Button size="sm" variant="ghost" onClick={() => setRecipients(campaign)}>Recipients</Button>
            {actions.includes('activate') && <Button size="sm" variant="ghost" onClick={() => setActivating(campaign)}>Send…</Button>}
            {actions.includes('pause') && <Button size="sm" variant="ghost" busy={busy === `pause:${campaign.id}`} onClick={() => act(campaign, 'pause')}>Pause</Button>}
            {actions.includes('resume') && <Button size="sm" variant="ghost" busy={busy === `resume:${campaign.id}`} onClick={() => act(campaign, 'resume')}>Resume</Button>}
            {actions.includes('cancel') && <Button size="sm" variant="ghost" onClick={() => setCancelling(campaign)}>Cancel</Button>}
          </span>
        );
      },
    },
  ];

  return (
    <AdminPage title="Campaigns" description="Email campaigns to your customers." actions={canManage && <Button variant="primary" onClick={() => setCreating(true)}>Add campaign</Button>}>
      <DataTable
        caption="Campaigns"
        columns={columns}
        rows={list.rows}
        rowKey={(campaign) => campaign.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={<EmptyPanel title="No campaigns yet" description="Write an email to all customers or to a segment." action={canManage ? <Button variant="primary" onClick={() => setCreating(true)}>Add campaign</Button> : undefined} />}
      />
      <Pagination meta={list.meta} disabled={list.loading} onPage={setPage} />

      {creating && <CreateDialog onClose={() => setCreating(false)} onDone={() => { setCreating(false); list.reload(); }} />}
      {activating && <ActivateDialog campaign={activating} onClose={() => setActivating(null)} onDone={() => { setActivating(null); list.reload(); }} />}
      {recipients && <RecipientsDrawer campaign={recipients} onClose={() => setRecipients(null)} />}
      <ConfirmDialog
        open={cancelling !== null}
        title="Cancel this campaign?"
        confirmLabel="Cancel campaign"
        busy={busy !== null && busy.startsWith('cancel:')}
        onClose={() => setCancelling(null)}
        onConfirm={() => cancelling && act(cancelling, 'cancel').then((result) => result !== undefined && setCancelling(null))}
      >
        <p>
          <strong>{cancelling?.name}</strong> stops. Emails already sent stay sent. A cancelled campaign cannot be started again.
        </p>
      </ConfirmDialog>
    </AdminPage>
  );
}
