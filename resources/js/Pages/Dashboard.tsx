import { Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { adminNav, platformNav } from '@/lib/adminNav';

/**
 * Admin home for a signed-in user: links to the admin pages that exist.
 * It shows no figures of its own. Analytics belongs to its own module.
 */
type PageProps = {
  auth: {
    user: { name: string; is_platform_staff?: boolean } | null;
    activeStore: { id: string; name: string; slug: string } | null;
  };
};

export default function Dashboard() {
  const { auth } = usePage<PageProps>().props;

  return (
    <AuthenticatedLayout>
      <h1 className="text-xl font-semibold">
        {auth.activeStore ? auth.activeStore.name : 'Dashboard'}
      </h1>
      {auth.user && <p className="mt-1 text-sm text-gray-500">Signed in as {auth.user.name}.</p>}

      <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {[...adminNav, ...(auth.user?.is_platform_staff ? platformNav : [])].map((item) => (
          <Link
            key={item.href}
            href={item.href}
            className="rounded border bg-white p-4 hover:border-gray-400"
          >
            <div className="font-medium">{item.label}</div>
            <p className="mt-1 text-sm text-gray-500">{item.description}</p>
          </Link>
        ))}

        {auth.activeStore && (
          <a
            href={`/shop/${auth.activeStore.slug}`}
            target="_blank"
            rel="noreferrer"
            className="rounded border bg-white p-4 hover:border-gray-400"
          >
            <div className="font-medium">View storefront</div>
            <p className="mt-1 text-sm text-gray-500">Open your store as customers see it.</p>
          </a>
        )}
      </div>
    </AuthenticatedLayout>
  );
}
