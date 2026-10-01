import { useState } from 'react';
import Button from '@/Components/ui/Button';
import DataTable, { Pagination, type Column } from '@/Components/ui/DataTable';
import Dialog from '@/Components/ui/Dialog';
import { SelectField, TextField } from '@/Components/ui/Form';
import { FilterBar, SearchField } from '@/Components/ui/Filters';
import Badge, { humanize } from '@/Components/ui/Badge';
import { Details, EmptyPanel } from '@/Components/ui/Page';
import { usePagedApi } from '@/lib/useApi';
import { useUrlState } from '@/lib/useUrlState';
import { useAction } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { displayTimezoneName, formatDateTime } from '@/lib/datetime';
import { options } from '@/lib/labels';

/**
 * Module 32 audit trail (Phase B22): who did what, read from the
 * tamper-evident log. The same view serves a store (its own entries,
 * /api/v1/audit-logs) and the platform (/api/v1/super-admin/audit-logs,
 * every store and the platform's own entries).
 *
 * The filters are the server's (AuditLogQuery); days are days in the
 * store's timezone. "Check integrity" asks the server to recompute the
 * hash chain; the page reports the answer and decides nothing.
 */
type Entry = {
  id: string;
  sequence: number;
  action: string;
  actor: { type: string; id: string | null; label: string | null };
  impersonated_by: string | null;
  surface: string;
  subject: { type: string; id: string | null } | null;
  context: Record<string, unknown> | null;
  ip_address: string | null;
  user_agent: string | null;
  request_id: string | null;
  store?: { id: string; name: string } | null;
  hash: string;
  created_at: string;
};

type ChainResult = { chain?: string; status: string; verified_entries?: number; first_broken_sequence?: number | null; reason?: string | null; chains?: ChainResult[] };

const ACTOR_TYPES = ['user', 'customer', 'api_key', 'system', 'anonymous'] as const;
const FILTER_DEFAULTS = { action: '', actor_type: '', from: '', to: '', request_id: '', store: '', page: '1' };

export default function AuditLogView({ path, integrityPath, platform = false }: { path: string; integrityPath: string; platform?: boolean }) {
  const [filters, setFilters] = useUrlState(FILTER_DEFAULTS);
  const list = usePagedApi<Entry>(path, { action: filters.action, actor_type: filters.actor_type, from: filters.from, to: filters.to, request_id: filters.request_id, store: platform ? filters.store : undefined, page: filters.page });
  const [open, setOpen] = useState<Entry | null>(null);
  const [integrity, setIntegrity] = useState<ChainResult | null>(null);
  const { busy, run } = useAction();
  const filtered = Object.entries(filters).some(([key, value]) => key !== 'page' && value !== '');

  const columns: Column<Entry>[] = [
    { key: 'when', header: 'When', render: (entry) => formatDateTime(entry.created_at) },
    { key: 'action', header: 'Action', priority: true, render: (entry) => <span className="font-mono text-xs">{entry.action}</span> },
    {
      key: 'actor',
      header: 'By',
      render: (entry) => (
        <div>
          <span>{entry.actor.label ?? humanize(entry.actor.type)}</span>
          {entry.impersonated_by && <p className="text-xs text-amber-800">Platform staff acting: {entry.impersonated_by}</p>}
        </div>
      ),
    },
    ...(platform ? [{ key: 'store', header: 'Store', render: (entry: Entry) => entry.store?.name ?? 'Platform' }] : []),
    { key: 'subject', header: 'On', render: (entry) => (entry.subject ? humanize(entry.subject.type) : '—') },
    { key: 'open', header: 'Actions', srOnlyHeader: true, align: 'right', priority: true, render: (entry) => <Button size="sm" variant="ghost" onClick={() => setOpen(entry)}>Details</Button> },
  ];

  return (
    <>
      <FilterBar>
        <SearchField label="Action starts with" placeholder="e.g. backup. or role." value={filters.action} onChange={(action) => setFilters({ action, page: '1' })} />
        <div className="w-40">
          <SelectField label="By" value={filters.actor_type} onChange={(actor_type) => setFilters({ actor_type, page: '1' })} options={options(ACTOR_TYPES)} placeholder="Anyone" />
        </div>
        <div className="w-40"><TextField label="From" type="date" value={filters.from} onChange={(from) => setFilters({ from, page: '1' })} /></div>
        <div className="w-40"><TextField label="To" type="date" value={filters.to} onChange={(to) => setFilters({ to, page: '1' })} /></div>
        {filtered && <Button onClick={() => setFilters(FILTER_DEFAULTS)}>Clear filters</Button>}
        <Button busy={busy === 'integrity'} busyLabel="Checking…" onClick={() => run('integrity', () => adminFetch<{ data: ChainResult }>(integrityPath, { timeoutMs: 60000 })).then((result) => result && setIntegrity(result.data))}>
          Check integrity
        </Button>
      </FilterBar>
      <p className="mb-3 text-xs text-slate-600">Days are days in the timezone {displayTimezoneName()}.</p>

      {integrity && (
        <div role="status" className={`mb-4 rounded-md border p-3 text-sm ${integrity.status === 'ok' ? 'border-green-200 bg-green-50 text-green-900' : 'border-red-300 bg-red-50 text-red-900'}`}>
          <p className="font-medium">{integrity.status === 'ok' ? 'The audit trail is intact.' : 'The audit trail does not verify.'}</p>
          <p className="mt-1">
            {integrity.chains
              ? `${integrity.chains.length} chains checked${integrity.chains.some((chain) => chain.status !== 'ok') ? `; broken: ${integrity.chains.filter((chain) => chain.status !== 'ok').map((chain) => chain.chain).join(', ')}` : ''}.`
              : `${integrity.verified_entries ?? 0} entries verified${integrity.first_broken_sequence ? `; first problem at entry ${integrity.first_broken_sequence}` : ''}.`}
            {integrity.status !== 'ok' && ' Report this to the platform security lead.'}
          </p>
        </div>
      )}

      <DataTable
        caption="Audit log"
        columns={columns}
        rows={list.rows}
        rowKey={(entry) => entry.id}
        loading={list.loading}
        error={list.error}
        onRetry={list.reload}
        empty={filtered ? <EmptyPanel title="No entries match" action={<Button onClick={() => setFilters(FILTER_DEFAULTS)}>Clear filters</Button>} /> : <EmptyPanel title="No entries yet" />}
      />
      <Pagination meta={list.meta} disabled={list.loading} onPage={(page) => setFilters({ page: String(page) })} />

      <Dialog open={open !== null} side title="Audit entry" description={open ? `#${open.sequence} · ${open.action}` : undefined} onClose={() => setOpen(null)}>
        {open && (
          <div className="space-y-4">
            <Details
              items={[
                { label: 'When', value: formatDateTime(open.created_at) },
                { label: 'By', value: `${open.actor.label ?? '—'} (${humanize(open.actor.type)})` },
                { label: 'Through', value: <Badge>{humanize(open.surface)}</Badge> },
                { label: 'On', value: open.subject ? `${humanize(open.subject.type)}${open.subject.id ? ` ${open.subject.id}` : ''}` : '—' },
                { label: 'IP address', value: open.ip_address ?? '—' },
                { label: 'Request ID', value: <span className="break-all font-mono text-xs">{open.request_id ?? '—'}</span> },
              ]}
            />
            <div>
              <h3 className="text-xs font-medium uppercase tracking-wide text-slate-500">Details recorded</h3>
              <pre className="mt-1 max-h-72 overflow-auto rounded-md bg-slate-100 p-3 text-xs text-slate-900">{JSON.stringify(open.context ?? {}, null, 2)}</pre>
            </div>
            <div>
              <h3 className="text-xs font-medium uppercase tracking-wide text-slate-500">Browser</h3>
              <p className="mt-1 break-words text-xs text-slate-700">{open.user_agent ?? '—'}</p>
            </div>
            <div>
              <h3 className="text-xs font-medium uppercase tracking-wide text-slate-500">Entry hash</h3>
              <p className="mt-1 break-all font-mono text-xs text-slate-700">{open.hash}</p>
            </div>
            {open.request_id && (
              <Button size="sm" onClick={() => { setFilters({ ...FILTER_DEFAULTS, request_id: open.request_id ?? '' }); setOpen(null); }}>
                Show everything from this request
              </Button>
            )}
          </div>
        )}
      </Dialog>
    </>
  );
}
