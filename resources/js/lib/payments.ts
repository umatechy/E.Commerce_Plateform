import { useEffect, useRef } from 'react';

/**
 * Phase B48 (gap G9): online payments in the admin — merchant accounts,
 * attempts and what reconciliation found. Shapes mirror the API; the
 * server decides every state, these only present it.
 */
export type AttemptState = 'waiting' | 'confirming' | 'paid' | 'failed' | 'expired' | 'review';

export type Attempt = {
  id: string;
  provider: string;
  reference: string;
  amount_minor: number;
  currency: string;
  payer_hint: string | null;
  status: string;
  state: AttemptState;
  message: string | null;
  expires_at: string;
  created_at: string;
  completed_at: string | null;
};

export type StaffAttempt = Attempt & {
  purpose: 'order' | 'invoice' | 'test';
  environment: 'sandbox' | 'production';
  failure_category: string | null;
  failure_label: string | null;
  provider_code: string | null;
  provider_message: string | null;
  provider_transaction_reference: string | null;
  confirmed_amount_minor: number | null;
  confirmed_currency: string | null;
  check_count: number;
  last_checked_at: string | null;
};

export type PayerFieldDef = { key: 'mobile' | 'cnic_last6'; label: string; hint: string };

export type CredentialFieldDef = { key: string; label: string; secret: boolean; max_length: number; help: string | null };

export type ProviderAccount = {
  id: string;
  environment: 'sandbox' | 'production';
  is_enabled: boolean;
  credential_hints: Record<string, string>;
  min_amount_minor: number | null;
  max_amount_minor: number | null;
  verified_at: string | null;
  last_success_at: string | null;
  last_failure_at: string | null;
  last_failure_category: string | null;
  updated_at: string | null;
};

export type ProviderInfo = {
  provider: string;
  label: string;
  capabilities: {
    wallet_payment: boolean; card_payment: boolean; status_inquiry: boolean; refund: boolean; partial_refund: boolean;
    notifications: boolean; signed_notifications: boolean; currencies: string[]; verified: boolean; note: string | null;
  };
  credential_fields: CredentialFieldDef[];
  live_allowed: boolean;
  account: ProviderAccount | null;
};

export const RECONCILIATION_LABELS: Record<string, { title: string; help: string }> = {
  amount_mismatch: { title: 'Amount does not match', help: 'The provider confirmed a different amount than the order asked for. It was not recorded as paid. Check the provider portal, refund or record the right amount, then resolve.' },
  currency_mismatch: { title: 'Currency does not match', help: 'The provider confirmed a different currency. It was not recorded as paid. Check the provider portal, then resolve.' },
  paid_after_expiry: { title: 'Paid after the order was given up', help: 'The money arrived after the order ran out of time or was already paid. Usually the shopper should get it back: refund it in the provider portal, then resolve.' },
  status_changed: { title: 'Provider changed the result', help: 'The provider now reports a recorded payment as failed or reversed. Check the provider portal before shipping.' },
  unresolved_unknown: { title: 'No answer for a day', help: 'The provider has not confirmed this payment either way. Look it up in the provider portal and record what happened.' },
};

const MOBILE = /^03\d{9}$/;

/** The same rule as the server: 03XXXXXXXXX, with +92 / 92 and spaces or dashes allowed. */
export function normalizeMobile(value: string): string | null {
  const digits = value.replace(/[\s-]/g, '').replace(/^(\+?92)(?=3)/, '0');

  return MOBILE.test(digits) ? digits : null;
}

export function validCnicLast6(value: string): boolean {
  return /^\d{6}$/.test(value.replace(/[\s-]/g, ''));
}

/** Waiting for an answer: the page keeps asking. */
export function isInFlight(state: AttemptState | null | undefined): boolean {
  return state === 'waiting' || state === 'confirming';
}

/**
 * Calls `tick` every `delayMs` while `active`, one call at a time (the next
 * waits for the previous answer), and stops when the page goes away.
 */
export function usePoll(tick: () => Promise<unknown>, active: boolean, delayMs = 3000): void {
  const latest = useRef(tick);
  latest.current = tick;

  useEffect(() => {
    if (!active) return;
    let stopped = false;
    let timer: number | undefined;
    const loop = () => {
      timer = window.setTimeout(async () => {
        try {
          await latest.current();
        } catch {
          // A failed check is tried again on the next tick.
        }
        if (!stopped) loop();
      }, delayMs);
    };
    loop();

    return () => {
      stopped = true;
      window.clearTimeout(timer);
    };
  }, [active, delayMs]);
}
