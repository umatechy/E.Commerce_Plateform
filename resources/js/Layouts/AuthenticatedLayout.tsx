import { PropsWithChildren } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { adminFetch } from '@/lib/adminApi';
import { adminNav } from '@/lib/adminNav';

/**
 * Reusable authenticated shell for every future admin module (this
 * milestone: "the goal is a reusable shell for future modules", "do not
 * build every admin screen"). Reads the authenticated user + current
 * store from Inertia shared props (populated server-side via
 * HandleInertiaRequests::share() — see docs/architecture/b1-identity-auth.md
 * for the exact shared-prop contract this layout depends on).
 *
 * IMPORTANT: any permission-based UI here (e.g. hiding a nav link) is a
 * convenience only — the actual enforcement already happened server-side
 * (RolePolicy) before any data reached this page. Hiding a link never
 * substitutes for that.
 */
type PageProps = {
  auth: {
    user: { id: string; name: string; email: string } | null;
    activeStore: { id: string; name: string } | null;
  };
};

export default function AuthenticatedLayout({ children }: PropsWithChildren) {
  const page = usePage<PageProps>();
  const { auth } = page.props;
  const path = page.url.split('?')[0];

  // The logout endpoint is a JSON API, not an Inertia one. A full load
  // afterwards drops the old session from the shared props.
  function logout() {
    adminFetch('/auth/logout', { method: 'POST' })
      .catch(() => undefined)
      .finally(() => window.location.assign('/login'));
  }

  return (
    <div className="min-h-screen bg-gray-50">
      <header className="border-b bg-white">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
          <div className="flex items-center gap-4">
            <Link href="/" className="font-semibold">
              Umar Techy
            </Link>
            {auth.activeStore && (
              <span className="rounded bg-gray-100 px-2 py-1 text-sm text-gray-600">
                {auth.activeStore.name}
              </span>
            )}
          </div>

          <div className="flex items-center gap-4 text-sm">
            {auth.user ? (
              <>
                <span className="text-gray-600">{auth.user.name}</span>
                <button onClick={logout} className="text-gray-500 hover:text-gray-900">
                  Log out
                </button>
              </>
            ) : (
              <Link href="/login">Sign in</Link>
            )}
          </div>
        </div>

        {auth.user && (
          <nav className="mx-auto flex max-w-6xl gap-1 overflow-x-auto px-4 text-sm">
            {[{ href: '/', label: 'Dashboard' }, ...adminNav].map((item) => {
              const active = item.href === '/' ? path === '/' : path.startsWith(item.href);

              return (
                <Link
                  key={item.href}
                  href={item.href}
                  className={`whitespace-nowrap border-b-2 px-3 py-2 ${
                    active
                      ? 'border-gray-900 text-gray-900'
                      : 'border-transparent text-gray-500 hover:text-gray-900'
                  }`}
                >
                  {item.label}
                </Link>
              );
            })}
          </nav>
        )}
      </header>

      <main className="mx-auto max-w-6xl px-4 py-6">{children}</main>
    </div>
  );
}
