import type { PropsWithChildren } from 'react';
import { Link } from '@inertiajs/react';
import StoreLayout from './StoreLayout';
import LoadingState from '@/Components/LoadingState';
import { signOut, type Customer } from '@/Storefront/account';
import type { Seo, Shell } from '@/Storefront/types';

const LINKS = [
  { path: '/account', label: 'Overview' },
  { path: '/account/orders', label: 'Orders' },
  { path: '/account/addresses', label: 'Addresses' },
  { path: '/account/wishlist', label: 'Wishlist' },
  { path: '/account/support', label: 'Support' },
  { path: '/account/profile', label: 'Profile & security' },
];

/** The signed-in area: a side menu next to the page. Renders nothing personal until the customer has loaded. */
export default function AccountLayout({
  shell,
  seo,
  customer,
  active,
  title,
  children,
}: PropsWithChildren<{ shell: Shell; seo: Seo; customer: Customer | null; active: string; title: string }>) {
  return (
    <StoreLayout shell={shell} seo={seo}>
      {!customer ? (
        <LoadingState />
      ) : (
        <div className="grid gap-8 md:grid-cols-[200px_1fr]">
          <aside>
            <p className="mb-3 text-sm text-sf-muted">Signed in as</p>
            <p className="mb-6 font-semibold">{customer.name}</p>
            <nav aria-label="Account">
              <ul className="space-y-1 text-sm">
                {LINKS.map((link) => (
                  <li key={link.path}>
                    <Link
                      href={`${shell.base_path}${link.path}`}
                      className={`block rounded-sf px-3 py-2 ${active === link.path ? 'bg-sf-surface font-semibold' : 'hover:bg-sf-surface'}`}
                    >
                      {link.label}
                    </Link>
                  </li>
                ))}
                <li>
                  <button type="button" onClick={() => signOut(shell)} className="w-full rounded-sf px-3 py-2 text-left text-sf-muted hover:bg-sf-surface">
                    Sign out
                  </button>
                </li>
              </ul>
            </nav>
          </aside>
          <section>
            <h1 className="mb-6 text-3xl font-bold">{title}</h1>
            {children}
          </section>
        </div>
      )}
    </StoreLayout>
  );
}
