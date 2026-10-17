import { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import Icon, { type IconName } from '@/Components/ui/Icon';
import { FOCUS_RING } from '@/Components/ui/Button';
import { useApi } from '@/lib/useApi';
import { useAdminT } from '@/lib/adminI18n';

/**
 * UI-17 — the bell in the admin header: what needs the store's attention
 * now (GET /api/v1/attention), each entry a link to the list that has it.
 * The server counts only what this user may open. Loaded with each page
 * and every two minutes while the page stays open.
 */
type Item = { key: string; count: number; tone: 'red' | 'amber' | 'blue'; href: string };

/** Each entry's words: one and many (with {count}), and a hint. Translated when shown. */
const WORDS: Record<string, { icon: IconName; one: string; many: string; hint: string }> = {
  invoice_overdue: { icon: 'receipt', one: 'Your Umar Techy invoice is overdue', many: 'Your Umar Techy invoices are overdue', hint: 'Pay it to keep your store open.' },
  orders_to_confirm: { icon: 'chat', one: '1 order to confirm', many: '{count} orders to confirm', hint: 'Check with the customer before you pack.' },
  orders_to_ship: { icon: 'truck', one: '1 order to pack and ship', many: '{count} orders to pack and ship', hint: 'Print the packing slip and create the shipment.' },
  transfers_to_check: { icon: 'wallet', one: '1 bank transfer to check', many: '{count} bank transfers to check', hint: 'Confirm when the money is in your account.' },
  returns_requested: { icon: 'undo', one: '1 return request', many: '{count} return requests', hint: 'Approve or decline each one.' },
  low_stock: { icon: 'box', one: '1 product low on stock', many: '{count} products low on stock', hint: 'At or under their reorder point.' },
  store_not_open: { icon: 'store', one: 'Your store is not open to customers yet', many: 'Your store is not open to customers yet', hint: 'Finish the setup steps and launch it.' },
};

/** The strings above, so the translation check sees them: t('Your Umar Techy invoice is overdue') t('Your Umar Techy invoices are overdue') t('1 order to confirm') t('{count} orders to confirm') t('1 order to pack and ship') t('{count} orders to pack and ship') t('1 bank transfer to check') t('{count} bank transfers to check') t('1 return request') t('{count} return requests') t('1 product low on stock') t('{count} products low on stock') t('Your store is not open to customers yet') t('Pay it to keep your store open.') t('Check with the customer before you pack.') t('Print the packing slip and create the shipment.') t('Confirm when the money is in your account.') t('Approve or decline each one.') t('At or under their reorder point.') t('Finish the setup steps and launch it.') */

const TONE: Record<Item['tone'], string> = {
  red: 'bg-red-50 text-red-600',
  amber: 'bg-amber-50 text-amber-700',
  blue: 'bg-brand-50 text-brand-600',
};

export default function AttentionBell() {
  const t = useAdminT();
  const state = useApi<{ data: Item[] }>('/attention');
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement>(null);
  const items = (state.data?.data ?? []).filter((item) => WORDS[item.key]);
  const urgent = items.some((item) => item.tone === 'red');

  useEffect(() => {
    const timer = window.setInterval(() => state.reload(), 120_000);

    return () => window.clearInterval(timer);
    // `reload` keeps its identity for the page's life.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!open) return;
    const close = (event: MouseEvent) => {
      if (box.current && !box.current.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', close);

    return () => document.removeEventListener('mousedown', close);
  }, [open]);

  if (state.errorStatus === 403) return null;

  return (
    <div ref={box} className="relative" onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-haspopup="true"
        aria-expanded={open}
        aria-label={items.length > 0 ? t(items.length === 1 ? 'Needs attention: 1 item' : 'Needs attention: {count} items', { count: items.length }) : t('Needs attention: nothing')}
        className={`relative rounded-lg p-2 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 motion-reduce:transition-none ${FOCUS_RING}`}
      >
        <Icon name="bell" size={20} />
        {items.length > 0 && (
          <span aria-hidden="true" className={`absolute -end-0.5 -top-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full px-1 text-[10px] font-bold text-white ring-2 ring-white ${urgent ? 'bg-red-600' : 'bg-brand-600'}`}>
            {items.length}
          </span>
        )}
      </button>
      {open && (
        <div className="absolute end-0 z-40 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] origin-top-right overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl shadow-slate-900/10 motion-safe:animate-scale-in">
          <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <p className="text-sm font-semibold text-slate-900">{t('Needs attention')}</p>
            {state.loading && <span className="text-xs text-slate-400">{t('Updating…')}</span>}
          </div>
          {items.length === 0 ? (
            <div className="px-4 py-8 text-center">
              <span className="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"><Icon name="checkCircle" size={22} /></span>
              <p className="mt-3 text-sm font-medium text-slate-900">{t('All caught up')}</p>
              <p className="mt-0.5 text-xs text-slate-500">{t('Nothing is waiting for you right now.')}</p>
            </div>
          ) : (
            <ul className="max-h-96 divide-y divide-slate-100 overflow-y-auto">
              {items.map((item) => {
                const words = WORDS[item.key];

                return (
                  <li key={item.key}>
                    <Link href={item.href} onClick={() => setOpen(false)} className={`group flex items-start gap-3 px-4 py-3 transition-colors hover:bg-slate-50 motion-reduce:transition-none ${FOCUS_RING}`}>
                      <span className={`flex h-9 w-9 flex-none items-center justify-center rounded-lg ${TONE[item.tone]}`}><Icon name={words.icon} size={18} /></span>
                      <span className="min-w-0 flex-1">
                        <span className="block text-sm font-medium text-slate-900">{t(item.count === 1 ? words.one : words.many, { count: item.count })}</span>
                        <span className="block text-xs text-slate-500">{t(words.hint)}</span>
                      </span>
                      <Icon name="arrowRight" size={16} className="mt-1 text-slate-300 transition-transform group-hover:translate-x-0.5 group-hover:text-slate-500 rtl:group-hover:-translate-x-0.5 motion-reduce:transform-none" />
                    </Link>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
