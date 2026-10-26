import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { formatMoney } from '@/lib/money';
import { errorMessage, storefrontFetch, validationErrors } from '@/Storefront/api';
import { useT } from '@/Storefront/i18n';
import { pointsFor, usablePoints, valueOf, type CustomerLoyalty } from '@/Storefront/loyalty';
import type { StorefrontPageProps } from '@/Storefront/types';

/**
 * Phase P2: loyalty at checkout — what this order is expected to earn, and,
 * for a customer with enough points, turning them into store credit in one
 * step so the order uses it. The server counts the real points once the
 * order is delivered and paid.
 */
export default function LoyaltyCheckout({ storefront, signedIn, baseMinor, onCredited }: { storefront: StorefrontPageProps['storefront']; signedIn: boolean; baseMinor: number; onCredited: () => void }) {
  const t = useT();
  const program = storefront.loyalty ?? null;
  const [mine, setMine] = useState<CustomerLoyalty | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ ok: boolean; text: string } | null>(null);
  // One key per attempt: a double click turns the points once; a new key after it worked.
  const newKey = () => `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  const [key, setKey] = useState(newKey);

  useEffect(() => {
    if (!program || !signedIn) return;
    storefrontFetch<{ data: CustomerLoyalty }>(storefront, '/customer/loyalty')
      .then((res) => setMine(res.data))
      .catch(() => setMine(null));
  }, [program, signedIn, storefront]);

  if (!program) return null;
  const earns = pointsFor(program, baseMinor, mine?.tier?.bonus_percent ?? 0);
  const usable = mine ? usablePoints(program, mine.balance) : 0;
  const money = (minor: number) => formatMoney(minor, program.currency);

  async function useNow() {
    setBusy(true);
    setNotice(null);
    try {
      const res = await storefrontFetch<{ data: CustomerLoyalty & { credited_minor: number } }>(storefront, '/customer/loyalty/redeem', { method: 'POST', body: { points: usable, idempotency_key: key } });
      setMine(res.data);
      setKey(newKey());
      setNotice({ ok: true, text: t('{amount} was added to your store credit and will be used for this order.', { amount: money(res.data.credited_minor) }) });
      onCredited();
    } catch (e) {
      setNotice({ ok: false, text: Object.values(validationErrors(e))[0]?.[0] ?? errorMessage(e) });
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-2 rounded-sf border border-dashed border-sf-accent p-3 text-sm" aria-live="polite">
      {earns > 0 && (
        signedIn ? (
          <p className="flex items-center gap-2 font-medium"><span aria-hidden="true" className="text-sf-accent">★</span>{t('You will earn about {points} points with this order.', { points: earns.toLocaleString() })}</p>
        ) : (
          <p>
            <span aria-hidden="true" className="me-2 text-sf-accent">★</span>
            {t('Earn {points} points with this order:', { points: earns.toLocaleString() })}{' '}
            <Link href={`${storefront.base_path}/account/login`} className="font-medium text-sf-accent underline">{t('sign in or create an account')}</Link>
          </p>
        )
      )}
      {usable > 0 && (
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span>{t('You have {points} points.', { points: mine!.balance.toLocaleString() })}</span>
          <button type="button" onClick={useNow} disabled={busy} className="sf-btn rounded-sf border border-sf-primary px-3 py-1.5 font-semibold text-sf-primary disabled:opacity-60">
            {busy ? t('Please wait…') : t('Use {points} points for {amount}', { points: usable.toLocaleString(), amount: money(valueOf(program, usable)) })}
          </button>
        </div>
      )}
      {notice && <p className={notice.ok ? 'text-sf-success' : 'text-sf-error'} role={notice.ok ? 'status' : 'alert'}>{notice.text}</p>}
    </div>
  );
}
