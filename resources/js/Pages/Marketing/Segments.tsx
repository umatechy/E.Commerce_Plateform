import { FormEvent, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import Button from '@/Components/ui/Button';
import DataTable, { type Column } from '@/Components/ui/DataTable';
import Dialog from '@/Components/ui/Dialog';
import { FormError, SelectField, TextField } from '@/Components/ui/Form';
import { EmptyPanel } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { money, toMinor } from '@/lib/money';
import { formatDate, displayTimezoneName } from '@/lib/datetime';

/**
 * Module 15 segments: groups of customers defined by rules
 * (/api/v1/marketing/segments). The fields and comparisons are the ones
 * the server accepts (MarketingSegmentService); the server works out who
 * matches. The API creates segments; it has no edit or delete.
 */
type Rule = { field: string; operator: string; value: string | number };
type Segment = { id: number; name: string; rules: Rule[] };
type Customer = { id: string; name: string; email: string };

const FIELDS: Record<string, { label: string; kind: 'count' | 'money' | 'date' }> = {
  total_orders_count: { label: 'Number of orders', kind: 'count' },
  total_spent_minor: { label: 'Total spent', kind: 'money' },
  last_order_at: { label: 'Last order date', kind: 'date' },
  registered_at: { label: 'Registration date', kind: 'date' },
};

const OPERATORS: Record<string, string> = { '>=': 'at least', '<=': 'at most', '=': 'exactly', '>': 'more than', '<': 'less than' };
const DATE_OPERATORS: Record<string, string> = { '>=': 'on or after', '<=': 'on or before', '=': 'exactly at', '>': 'after', '<': 'before' };

/** A rule as a sentence: "Total spent at least $50.00". */
function ruleText(rule: Rule, currency: string): string {
  const field = FIELDS[rule.field];
  if (!field) return `${rule.field} ${rule.operator} ${rule.value}`;
  const operator = (field.kind === 'date' ? DATE_OPERATORS : OPERATORS)[rule.operator] ?? rule.operator;
  const value = field.kind === 'money' ? money(Number(rule.value), currency) : field.kind === 'date' ? formatDate(String(rule.value)) : String(rule.value);

  return `${field.label} ${operator} ${value}`;
}

type DraftRule = { field: string; operator: string; value: string };

function CreateDialog({ currency, onClose, onDone }: { currency: string; onClose: () => void; onDone: () => void }) {
  const form = useForm({ name: '' });
  const [rules, setRules] = useState<DraftRule[]>([{ field: 'total_orders_count', operator: '>=', value: '' }]);

  function patch(index: number, change: Partial<DraftRule>) {
    setRules((current) => current.map((rule, i) => (i === index ? { ...rule, ...change } : rule)));
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    const sent: Rule[] = [];
    for (const rule of rules) {
      const kind = FIELDS[rule.field].kind;
      if (rule.value.trim() === '') {
        form.setFormError('Give every rule a value.');

        return;
      }
      if (kind === 'money') {
        const minor = toMinor(rule.value, currency);
        if (minor === null) {
          form.setFormError(`Enter amounts such as 50.00 (${currency}).`);

          return;
        }
        sent.push({ field: rule.field, operator: rule.operator, value: minor });
      } else if (kind === 'count') {
        if (!Number.isInteger(Number(rule.value)) || Number(rule.value) < 0) {
          form.setFormError('The number of orders must be a whole number.');

          return;
        }
        sent.push({ field: rule.field, operator: rule.operator, value: Number(rule.value) });
      } else {
        sent.push({ field: rule.field, operator: rule.operator, value: rule.value });
      }
    }
    if ((await form.submit(() => adminFetch('/marketing/segments', { method: 'POST', body: { name: form.values.name, rules: sent } }), 'Segment created.')) !== undefined) onDone();
  }

  return (
    <Dialog open wide title="Add segment" description="A customer is in the segment when every rule is true. A segment cannot be edited afterwards." onClose={onClose} busy={form.busy}>
      <form onSubmit={save} className="space-y-4" noValidate>
        <FormError message={form.formError} errors={form.errors} />
        <TextField label="Name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} required maxLength={255} data-autofocus />
        <fieldset className="space-y-3">
          <legend className="text-sm font-medium text-slate-700">Rules</legend>
          {rules.map((rule, index) => {
            const kind = FIELDS[rule.field].kind;

            return (
              <div key={index} className="grid gap-3 rounded-md border border-slate-200 p-3 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end">
                <SelectField label="Customer's" value={rule.field} onChange={(field) => patch(index, { field, value: '' })} options={Object.entries(FIELDS).map(([value, field]) => ({ value, label: field.label }))} />
                <SelectField label="Is" value={rule.operator} onChange={(operator) => patch(index, { operator })} options={Object.entries(kind === 'date' ? DATE_OPERATORS : OPERATORS).map(([value, label]) => ({ value, label }))} />
                <TextField
                  label={kind === 'money' ? `Amount (${currency})` : kind === 'date' ? 'Date' : 'Number'}
                  type={kind === 'date' ? 'date' : 'text'}
                  inputMode={kind === 'date' ? undefined : 'decimal'}
                  value={rule.value}
                  onChange={(value) => patch(index, { value })}
                />
                <Button variant="ghost" size="sm" disabled={rules.length === 1} onClick={() => setRules((current) => current.filter((_, i) => i !== index))}>
                  Remove<span className="sr-only"> rule {index + 1}</span>
                </Button>
              </div>
            );
          })}
          <Button size="sm" onClick={() => setRules((current) => [...current, { field: 'total_orders_count', operator: '>=', value: '' }])}>Add another rule</Button>
          <p className="text-xs text-slate-600">Dates are days in your store's timezone ({displayTimezoneName()}).</p>
        </fieldset>
        <div className="flex justify-end gap-2">
          <Button onClick={onClose} disabled={form.busy}>Cancel</Button>
          <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…">Create segment</Button>
        </div>
      </form>
    </Dialog>
  );
}

function PreviewDrawer({ segment, onClose }: { segment: Segment; onClose: () => void }) {
  // Working out who matches reads every customer's orders; give it longer.
  const state = useApi<{ data: Customer[] }>(`/marketing/segments/${segment.id}/preview`);
  const columns: Column<Customer>[] = [
    { key: 'name', header: 'Customer', render: (customer) => <span className="font-medium">{customer.name}</span> },
    { key: 'email', header: 'Email', priority: true, render: (customer) => customer.email },
  ];

  return (
    <Dialog open side title="Who is in this segment now" description={segment.name} onClose={onClose}>
      {state.data && <p className="mb-3 text-sm text-slate-700">{state.data.data.length} customer{state.data.data.length === 1 ? '' : 's'} match today.</p>}
      <DataTable caption="Customers in the segment" columns={columns} rows={state.data?.data ?? null} rowKey={(customer) => customer.id} loading={state.loading} error={state.error} onRetry={state.reload} empty={<p className="text-sm text-slate-600">No customer matches these rules today.</p>} />
    </Dialog>
  );
}

export default function Segments() {
  const access = useAccess();
  const canManage = access.can('marketing.manage');
  const list = useApi<{ data: Segment[] }>('/marketing/segments');
  const [creating, setCreating] = useState(false);
  const [preview, setPreview] = useState<Segment | null>(null);

  const columns: Column<Segment>[] = [
    { key: 'name', header: 'Segment', render: (segment) => <span className="font-medium">{segment.name}</span> },
    {
      key: 'rules',
      header: 'Rules',
      render: (segment) => (
        <ul className="text-slate-700">
          {segment.rules.map((rule, index) => (
            <li key={index}>{ruleText(rule, access.currency)}</li>
          ))}
        </ul>
      ),
    },
    { key: 'actions', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true, render: (segment) => <Button size="sm" variant="ghost" onClick={() => setPreview(segment)}>See who matches<span className="sr-only"> {segment.name}</span></Button> },
  ];

  return (
    <AdminPage title="Segments" description="Groups of customers you can send a campaign to." actions={canManage && <Button variant="primary" onClick={() => setCreating(true)}>Add segment</Button>}>
      <DataTable
        caption="Segments"
        columns={columns}
        rows={list.data?.data ?? null}
        rowKey={(segment) => segment.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={<EmptyPanel title="No segments yet" description="For example: customers with at least 3 orders." action={canManage ? <Button variant="primary" onClick={() => setCreating(true)}>Add segment</Button> : undefined} />}
      />
      {creating && <CreateDialog currency={access.currency} onClose={() => setCreating(false)} onDone={() => { setCreating(false); list.reload(); }} />}
      {preview && <PreviewDrawer segment={preview} onClose={() => setPreview(null)} />}
    </AdminPage>
  );
}
