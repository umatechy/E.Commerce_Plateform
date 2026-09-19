import { PropsWithChildren } from 'react';
import { Link, router, usePage } from '@inertiajs/react';

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
  const { auth } = usePage<PageProps>().props;

  function logout() {
    router.post('/api/v1/auth/logout');
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
      </header>

      <main className="mx-auto max-w-6xl px-4 py-6">{children}</main>
    </div>
  );
}
