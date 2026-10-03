import { usePage } from '@inertiajs/react';
import { DEFAULT_CURRENCY, SUPPORTED_CURRENCIES } from './money';

/**
 * What the signed-in user may open, as the server shared it with the
 * page (HandleInertiaRequests + AdminShellProps).
 *
 * This shapes the screen only: which menu entries and buttons appear.
 * It is never the security boundary. A hidden button changes nothing
 * about what the API allows; every call is authorized again server-side.
 */
export type AuthProps = {
  user: { id: string; name: string; email: string; is_platform_staff?: boolean; mfa_enabled?: boolean } | null;
  activeStore: { id: number; name: string; slug: string } | null;
  timezone?: string | null;
  mfa_enrollment_required?: boolean;
  permissions?: string[];
  is_owner?: boolean;
  features?: Record<string, boolean>;
  package?: { code: string; name: string } | null;
  stores?: { id: number; name: string }[];
  currency?: string | null;
  /** The currencies the platform offers (owner decision 2026-10-03). */
  currencies?: string[];
};

export type Access = {
  /** True when the user holds the permission (or any of them). Store Owners hold all. */
  can: (permission: string | string[]) => boolean;
  /** true: included in the package. false: not included. undefined: the package does not mention it. */
  feature: (key: string) => boolean | undefined;
  isOwner: boolean;
  isPlatformStaff: boolean;
  hasStore: boolean;
  locked: boolean;
  packageName: string | null;
  currency: string;
  /** The currencies a store may choose, the store currency first. */
  currencies: string[];
  timezone: string;
};

export function accessFrom(auth: AuthProps): Access {
  const permissions = new Set(auth.permissions ?? []);
  const isOwner = auth.is_owner === true;

  return {
    can: (permission) => isOwner || (Array.isArray(permission) ? permission : [permission]).some((key) => permissions.has(key)),
    feature: (key) => auth.features?.[key],
    isOwner,
    isPlatformStaff: auth.user?.is_platform_staff === true,
    hasStore: auth.activeStore !== null && auth.activeStore !== undefined,
    locked: auth.mfa_enrollment_required === true,
    packageName: auth.package?.name ?? null,
    // Pakistan first (owner decision 2026-10-03).
    currency: auth.currency ?? DEFAULT_CURRENCY,
    currencies: (auth.currencies?.length ? auth.currencies : [...SUPPORTED_CURRENCIES]),
    timezone: auth.timezone ?? 'UTC',
  };
}

export function useAuth(): AuthProps {
  return usePage<{ auth: AuthProps }>().props.auth;
}

export function useAccess(): Access {
  return accessFrom(useAuth());
}
