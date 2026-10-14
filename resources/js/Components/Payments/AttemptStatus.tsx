import { formatMoney } from '@/lib/money';
import type { Attempt } from '@/lib/payments';
import { AlertIcon, CheckIcon, ClockIcon, CrossIcon, PhoneIcon } from './icons';

/**
 * Phase B48: where an online payment stands, in the admin's words. One
 * live region, so a screen reader hears each change without the page
 * moving focus. The provider is asked by the server; this only shows it.
 */
const COPY: Record<Attempt['state'], { title: string; tone: string; Icon: typeof PhoneIcon }> = {
  waiting: { title: 'Waiting for approval on the phone', tone: 'border-indigo-200 bg-indigo-50 text-indigo-900', Icon: PhoneIcon },
  confirming: { title: 'Confirming with the provider', tone: 'border-amber-200 bg-amber-50 text-amber-900', Icon: ClockIcon },
  paid: { title: 'Paid', tone: 'border-emerald-200 bg-emerald-50 text-emerald-900', Icon: CheckIcon },
  failed: { title: 'Not paid', tone: 'border-red-200 bg-red-50 text-red-900', Icon: CrossIcon },
  expired: { title: 'Ran out of time', tone: 'border-slate-200 bg-slate-50 text-slate-800', Icon: ClockIcon },
  review: { title: 'Being checked', tone: 'border-amber-200 bg-amber-50 text-amber-900', Icon: AlertIcon },
};

export default function AttemptStatus({ attempt, providerLabel }: { attempt: Attempt; providerLabel: string }) {
  const { title, tone, Icon } = COPY[attempt.state];
  const amount = formatMoney(attempt.amount_minor, attempt.currency);

  return (
    <div role="status" aria-live="polite" className={`flex gap-3 rounded-md border p-4 text-sm ${tone}`}>
      <Icon className={`h-6 w-6 shrink-0 ${attempt.state === 'waiting' ? 'motion-safe:animate-pulse' : ''}`} />
      <div className="min-w-0 space-y-1">
        <p className="font-semibold">{title}</p>
        {attempt.state === 'waiting' && (
          <p>
            {providerLabel} sent a request for {amount} to {attempt.payer_hint ?? 'the wallet'}. Approve it on that phone with the wallet PIN.
          </p>
        )}
        {attempt.state === 'confirming' && <p>No answer yet. Do not pay again — this updates by itself once {providerLabel} answers.</p>}
        {attempt.state === 'paid' && <p>{amount} was received through {providerLabel}.</p>}
        {(attempt.state === 'failed' || attempt.state === 'expired' || attempt.state === 'review') && attempt.message && <p>{attempt.message}</p>}
        <p className="text-xs opacity-80">Reference {attempt.reference}</p>
      </div>
    </div>
  );
}
