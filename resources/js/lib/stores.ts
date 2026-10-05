import type { Tone } from '@/Components/ui/Badge';

/**
 * Phase B44 (Module 03 §7–8): a store's stage as StoreLifecycle derives it
 * from the store and its subscription — labels and badge tones shared by the
 * Super Admin pages.
 */
export const STAGE_LABEL: Record<string, string> = {
  awaiting_owner: 'Waiting for the owner',
  onboarding: 'Setting up',
  trial: 'Live — trial',
  active: 'Live',
  payment_due: 'Payment due',
  grace_period: 'Grace period',
  suspended: 'Suspended',
  cancelled: 'Cancelled',
  archived: 'Archived',
};

export const STAGE_TONE: Record<string, Tone> = {
  awaiting_owner: 'amber',
  onboarding: 'blue',
  trial: 'green',
  active: 'green',
  payment_due: 'amber',
  grace_period: 'amber',
  suspended: 'red',
  cancelled: 'neutral',
  archived: 'neutral',
};
