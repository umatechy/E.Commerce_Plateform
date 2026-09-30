import { slaState, type AgentTicketSummary } from '@/lib/support';

const TONES = {
  breached: 'bg-red-100 text-red-800',
  due_soon: 'bg-amber-100 text-amber-900',
  on_track: 'bg-gray-100 text-gray-700',
} as const;

/** How a ticket stands against its service levels ("Reply due in 3h", "Reply overdue by 20m"). */
export default function SlaBadge({ ticket, now }: { ticket: Pick<AgentTicketSummary, 'status' | 'sla'>; now: Date }) {
  const sla = slaState(ticket, now);
  if (sla.state === 'none') return null;

  return (
    <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${TONES[sla.state]}`} data-sla={sla.state}>
      {sla.label}
    </span>
  );
}
