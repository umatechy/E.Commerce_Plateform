import type { Translate } from './i18n';
import type { LoyaltyProgram } from './types';

/**
 * Phase P2: the customer's loyalty points as the server returns them, and
 * the small sums the shop shows before the server has the last word (an
 * estimate at checkout, the credit a number of points turns into).
 */
export type LoyaltyTier = { name: string; min_points: number; bonus_percent: number };
export type CustomerLoyalty = {
  program: LoyaltyProgram | null;
  balance: number;
  value_minor: number;
  currency: string | null;
  lifetime_points: number;
  tier: LoyaltyTier | null;
  next_tier: (LoyaltyTier & { points_to_go: number }) | null;
  pending_points: number;
  expires_at: string | null;
  entries: { id: string; type: string; points: number; balance_after: number; note: string | null; created_at: string }[];
};

export const LOYALTY_LABELS: Record<string, string> = {
  earned: 'Earned on an order',
  repeat_bonus: 'Repeat-purchase bonus',
  birthday: 'Birthday gift',
  reversed: 'Taken back after a refund',
  redeemed: 'Turned into store credit',
  adjustment: 'Added or changed by the store',
  expired: 'Expired',
};

/** Points for an amount spent on products, with a tier's bonus — as the server counts them. */
export function pointsFor(program: Pick<LoyaltyProgram, 'earn_points' | 'earn_spend_minor'>, amountMinor: number, bonusPercent = 0): number {
  const base = Math.floor(Math.max(0, amountMinor) / Math.max(1, program.earn_spend_minor)) * program.earn_points;

  return base + Math.floor((base * Math.max(0, bonusPercent)) / 100);
}

/** Store credit that points turn into: whole steps only. */
export function valueOf(program: Pick<LoyaltyProgram, 'redeem_points' | 'redeem_value_minor'>, points: number): number {
  return Math.floor(Math.max(0, points) / Math.max(1, program.redeem_points)) * program.redeem_value_minor;
}

/** The most points the customer can use now: whole steps of their balance, or 0 below the minimum. */
export function usablePoints(program: Pick<LoyaltyProgram, 'redeem_points' | 'min_redeem_points'>, balance: number): number {
  const steps = Math.floor(balance / Math.max(1, program.redeem_points)) * program.redeem_points;

  return steps >= program.min_redeem_points ? steps : 0;
}

/** How points are earned and used, in the shopper's language. */
export function howItWorks(t: Translate, p: LoyaltyProgram, money: (minor: number) => string): string[] {
  return [
    p.earn_points === 1
      ? t('Earn 1 point for every {amount} you spend on products.', { amount: money(p.earn_spend_minor) })
      : t('Earn {points} points for every {amount} you spend on products.', { points: p.earn_points, amount: money(p.earn_spend_minor) }),
    t('{points} points = {amount} of store credit, from {min} points.', { points: p.redeem_points, amount: money(p.redeem_value_minor), min: p.min_redeem_points }),
    p.hold_days > 0 ? t('Points can be used {days} days after your order is delivered.', { days: p.hold_days }) : t('Points can be used once your order is delivered and paid.'),
    ...(p.repeat_every && p.repeat_points ? [t('Every {count} orders bring {points} bonus points.', { count: p.repeat_every, points: p.repeat_points })] : []),
    ...(p.birthday_points ? [t('{points} points as a birthday gift.', { points: p.birthday_points })] : []),
    ...(p.expiry_days ? [t('Points expire after {days} days without earning or using any.', { days: p.expiry_days })] : []),
  ];
}
