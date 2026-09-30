import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { forgetCart, StorefrontApiError, storefrontFetch } from './api';
import type { Shell } from './types';

export type Customer = {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  email_verified: boolean;
  marketing_email_opt_in?: boolean;
  member_since?: string | null;
};

export type Address = {
  id: string;
  label: string | null;
  is_default: boolean;
  name: string;
  phone: string | null;
  line1: string;
  line2: string | null;
  city: string;
  province: string | null;
  postal_code: string | null;
  country: string;
};

export function loginHref(shell: Shell, redirect?: string): string {
  const target = redirect ?? (typeof window !== 'undefined' ? window.location.pathname : undefined);

  return `${shell.base_path}/account/login${target ? `?redirect=${encodeURIComponent(target)}` : ''}`;
}

/**
 * The signed-in customer, loaded from the API with the session cookie.
 * With `required`, a visitor who is not signed in is sent to the login
 * page and brought back afterwards.
 */
export function useCustomer(shell: Shell, { required = false } = {}) {
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [status, setStatus] = useState<'loading' | 'guest' | 'signed_in'>('loading');

  useEffect(() => {
    storefrontFetch<{ data: Customer }>(shell, '/customer/profile')
      .then((res) => {
        setCustomer(res.data);
        setStatus('signed_in');
      })
      .catch((error) => {
        setStatus('guest');
        if (required && error instanceof StorefrontApiError && (error.status === 401 || error.status === 403)) {
          router.visit(loginHref(shell));
        }
      });
  }, [shell, required]);

  return { customer, setCustomer, status };
}

export async function signOut(shell: Shell): Promise<void> {
  try {
    await storefrontFetch(shell, '/storefront/session', { method: 'DELETE' });
  } finally {
    forgetCart(shell);
    router.visit(shell.base_path || '/');
  }
}

/** One line for an address, e.g. in lists and order summaries. */
export function formatAddress(address: Partial<Omit<Address, 'id' | 'label' | 'is_default'>> | null | undefined): string {
  if (!address) return '';

  return [address.line1, address.line2, address.city, address.province, address.postal_code, address.country]
    .filter((part) => typeof part === 'string' && part.trim() !== '')
    .join(', ');
}
