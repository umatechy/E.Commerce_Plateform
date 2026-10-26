import { useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import { FormError, SelectField, SwitchField, TextAreaField, TextField } from '@/Components/ui/Form';
import { Card, ErrorPanel, PackageNotice, Skeleton } from '@/Components/ui/Page';
import type { IconName } from '@/Components/ui/Icon';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { adminFetch, fieldErrors } from '@/lib/adminApi';
import { toast } from '@/Components/ui/toast';

/**
 * Phase P4b — Marketing → Automations (Business and Premium; Module 15
 * §29–44). Emails that go out by themselves at the right moment. The server
 * sends only to customers who agreed to marketing email, once per occasion,
 * never within a day of another marketing email.
 */
export type Automation = { kind: 'welcome' | 'post_purchase' | 'win_back' | 'birthday'; is_enabled: boolean; delay_days: number; subject: string; body: string; coupon_id: number | null; enabled_at: string | null; sent_30d: number; limits: { min: number; max: number } };
type Data = { included: boolean; can_manage: boolean; automations: Automation[]; coupons: { id: number; code: string }[] };

const ABOUT: Record<Automation['kind'], { title: string; icon: IconName; text: string; delay: (days: number) => string; delayLabel: string | null }> = {
  welcome: { title: 'Welcome', icon: 'sparkles', text: 'When someone creates an account.', delayLabel: 'Days after the account is created', delay: (d) => (d === 0 ? 'Right away' : `${d} ${d === 1 ? 'day' : 'days'} after sign-up`) },
  post_purchase: { title: 'Thank you after delivery', icon: 'heart', text: 'A thank-you and a review request once the order has arrived.', delayLabel: 'Days after delivery', delay: (d) => `${d} ${d === 1 ? 'day' : 'days'} after delivery` },
  win_back: { title: 'We miss you', icon: 'refresh', text: 'When a customer has not ordered for a while.', delayLabel: 'Days since the last order', delay: (d) => `${d} days after the last order` },
  birthday: { title: 'Birthday', icon: 'star', text: 'On the customer’s birthday, if they gave it in their account.', delayLabel: null, delay: () => 'On the birthday' },
};

function AutomationCard({ a, coupons, canManage, onSaved }: { a: Automation; coupons: Data['coupons']; canManage: boolean; onSaved: () => void }) {
  const about = ABOUT[a.kind];
  const [f, setF] = useState({ is_enabled: a.is_enabled, delay_days: String(a.delay_days), subject: a.subject, body: a.body, coupon_id: a.coupon_id ? String(a.coupon_id) : '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const dirty = f.is_enabled !== a.is_enabled || f.delay_days !== String(a.delay_days) || f.subject !== a.subject || f.body !== a.body || f.coupon_id !== (a.coupon_id ? String(a.coupon_id) : '');

  function save() {
    setBusy(true);
    setErrors({});
    setError(null);
    adminFetch<{ message: string }>(`/marketing/automations/${a.kind}`, { method: 'PUT', body: { ...f, delay_days: Number(f.delay_days || 0), coupon_id: f.coupon_id === '' ? null : Number(f.coupon_id) } })
      .then((r) => { toast.success(r.message); onSaved(); })
      .catch((e) => { setErrors(fieldErrors(e)); setError(e instanceof Error ? e.message : 'Could not save.'); })
      .finally(() => setBusy(false));
  }

  return (
    <Card
      title={about.title}
      icon={about.icon}
      description={about.text}
      actions={<Badge tone={a.is_enabled ? 'green' : 'neutral'}>{a.is_enabled ? 'On' : 'Off'}</Badge>}
    >
      <div className="space-y-4">
        <FormError message={Object.keys(errors).length ? null : error} />
        <div className="flex flex-wrap items-center justify-between gap-3">
          <SwitchField label="Send automatically" checked={f.is_enabled} onChange={(v) => setF({ ...f, is_enabled: v })} disabled={!canManage} />
          <p className="text-sm text-slate-600">{about.delay(Number(f.delay_days || 0))} · {a.sent_30d.toLocaleString()} sent in 30 days</p>
        </div>
        {about.delayLabel && (
          <TextField label={about.delayLabel} type="number" min={a.limits.min} max={a.limits.max} value={f.delay_days} onChange={(v) => setF({ ...f, delay_days: v })} error={errors.delay_days} hint={`${a.limits.min} to ${a.limits.max}.`} disabled={!canManage} />
        )}
        <TextField label="Subject" value={f.subject} onChange={(v) => setF({ ...f, subject: v })} error={errors.subject} maxLength={200} disabled={!canManage} />
        <TextAreaField label="Message" value={f.body} onChange={(v) => setF({ ...f, body: v })} error={errors.body} rows={6} maxLength={5000} disabled={!canManage}
          hint={`You can use {{customer.name}}, {{store.name}}, {{shop.link}}${a.kind === 'post_purchase' ? ', {{order.number}}' : ''}`} />
        <SelectField label="Add a coupon code" optional value={f.coupon_id} onChange={(v) => setF({ ...f, coupon_id: v })} error={errors.coupon_id} disabled={!canManage}
          options={[{ value: '', label: 'No coupon' }, ...coupons.map((c) => ({ value: String(c.id), label: c.code }))]} hint="Added at the end of the message. Make the code in Discounts." />
        {canManage && (
          <div className="flex justify-end">
            <Button variant="primary" busy={busy} busyLabel="Saving…" disabled={!dirty} onClick={save}>Save</Button>
          </div>
        )}
      </div>
    </Card>
  );
}

export default function Automations() {
  const access = useAccess();
  const included = access.feature('marketing.advanced') === true;
  const state = useApi<{ data: Data }>(included ? '/marketing/automations' : null);
  const data = state.data?.data;

  return (
    <AdminPage title="Automations" description="Emails that send themselves at the right moment: a welcome, a thank-you after delivery, a nudge to come back, a birthday wish.">
      {!included ? (
        <PackageNotice title="Automations are part of the Business and Premium packages" packageName={access.packageName} canSeeBilling={access.can('billing.view')}>
          Welcome new customers, thank them after delivery, bring back those who stopped ordering and wish them a happy birthday — without sending anything by hand.
        </PackageNotice>
      ) : state.error ? (
        <ErrorPanel message={state.error} onRetry={state.reload} />
      ) : !data ? (
        <Skeleton lines={8} />
      ) : (
        <div className="space-y-6">
          <p className="rounded-2xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900">
            Only customers who agreed to marketing email receive these, each once per occasion, and never within a day of another marketing email. Every email has an unsubscribe link. An automation starts from when you turn it on.
          </p>
          <div className="grid gap-6 xl:grid-cols-2">
            {data.automations.map((a) => <AutomationCard key={`${a.kind}-${a.enabled_at}-${a.is_enabled}`} a={a} coupons={data.coupons} canManage={data.can_manage} onSaved={state.reload} />)}
          </div>
        </div>
      )}
    </AdminPage>
  );
}
