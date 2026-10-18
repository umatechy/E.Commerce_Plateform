import { Link } from '@inertiajs/react';
import { Card } from '@/Components/ui/Page';
import Icon, { type IconName } from '@/Components/ui/Icon';
import { FOCUS_RING } from '@/Components/ui/Button';
import { useApi } from '@/lib/useApi';
import { useAdminT, type AdminTranslate } from '@/lib/adminI18n';

/**
 * UI-23 — suggestions from the store's own figures (GET /api/v1/insights):
 * best sellers running low, cash-on-delivery cancellations, orders waiting
 * too long, this week's best seller, products that stopped selling. The
 * server sends the numbers; this card words them. Hidden when there is
 * nothing to say or the user may not see reports.
 */
type Insight = { key: string; tone: 'amber' | 'red' | 'green' | 'blue'; href: string; values: Record<string, unknown> };
type SellingOut = { id: string; name: string; sold: number; left: number };

const TONE: Record<Insight['tone'], string> = {
  amber: 'bg-amber-50 text-amber-700',
  red: 'bg-red-50 text-red-700',
  green: 'bg-emerald-50 text-emerald-700',
  blue: 'bg-brand-50 text-brand-600',
};

const ICON: Record<string, IconName> = { selling_out: 'box', cod_cancellations: 'alert', waiting_long: 'clock', top_product: 'trendUp', not_selling: 'tag' };

function words(t: AdminTranslate, insight: Insight): { title: string; lines: string[]; hint?: string } {
  const v = insight.values;
  switch (insight.key) {
    case 'selling_out':
      return {
        title: t('Selling fast, running low'),
        lines: (v.products as SellingOut[]).map((p) => t('{name}: {sold} sold this week, {left} left', { name: p.name, sold: p.sold, left: p.left })),
        hint: t('Restock them before they sell out.'),
      };
    case 'cod_cancellations':
      return {
        title: t('{percent}% of cash-on-delivery orders were cancelled in the last 30 days', { percent: String(v.percent) }),
        lines: v.previous_percent === null || v.previous_percent === undefined ? [] : [t('The 30 days before: {percent}%', { percent: String(v.previous_percent) })],
        hint: t('A WhatsApp confirmation before you pack cuts cancellations.'),
      };
    case 'waiting_long':
      return { title: t(Number(v.count) === 1 ? '1 order has waited more than a day for confirmation' : '{count} orders have waited more than a day for confirmation', { count: Number(v.count) }), lines: [], hint: t('Customers lose interest when they hear nothing.') };
    case 'top_product':
      return { title: t('{name} is your best seller this week', { name: String(v.name) }), lines: [t('{sold} sold in the last 7 days', { sold: Number(v.sold) })] };
    case 'not_selling':
      return { title: t(Number(v.count) === 1 ? '1 product with stock has not sold in 30 days' : '{count} products with stock have not sold in 30 days', { count: Number(v.count) }), lines: [], hint: t('A promotion or better pictures can help them move.') };
    default:
      return { title: insight.key, lines: [] };
  }
}

export default function Insights() {
  const t = useAdminT();
  const state = useApi<{ data: Insight[] }>('/insights');
  const insights = state.data?.data ?? [];
  if (state.error || insights.length === 0) return null;

  return (
    <Card title={t('Suggestions')} description={t('From your own figures, to help you decide what to do next.')}>
      <ul className="grid gap-3 md:grid-cols-2">
        {insights.map((insight) => {
          const w = words(t, insight);

          return (
            <li key={insight.key}>
              <Link href={insight.href} className={`group flex h-full gap-3 rounded-xl border border-slate-200 p-4 transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md motion-reduce:transform-none motion-reduce:transition-none ${FOCUS_RING}`}>
                <span className={`flex h-10 w-10 flex-none items-center justify-center rounded-xl ${TONE[insight.tone]}`}><Icon name={ICON[insight.key] ?? 'sparkles'} size={20} /></span>
                <span className="min-w-0 flex-1">
                  <span className="block text-sm font-semibold text-slate-900">{w.title}</span>
                  {w.lines.map((line) => <span key={line} className="mt-0.5 block text-sm text-slate-600">{line}</span>)}
                  {w.hint && <span className="mt-1 block text-xs text-slate-500">{w.hint}</span>}
                </span>
                <Icon name="arrowRight" size={16} className="mt-1 text-slate-300 transition-transform group-hover:translate-x-0.5 group-hover:text-slate-500 rtl:group-hover:-translate-x-0.5 motion-reduce:transform-none" />
              </Link>
            </li>
          );
        })}
      </ul>
    </Card>
  );
}
