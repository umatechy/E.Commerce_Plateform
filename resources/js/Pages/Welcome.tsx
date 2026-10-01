import { Link } from '@inertiajs/react';

// Landing page of the platform's own host for signed-out visitors.
// Signed-in users get Pages/Dashboard instead (see routes/web.php).
export default function Welcome() {
  return (
    <main className="flex min-h-screen items-center justify-center bg-white text-gray-900">
      <div className="text-center">
        <h1 className="text-2xl font-semibold">Umar Techy E-Commerce Platform</h1>
        <p className="mt-2 text-sm text-gray-500">Create and run your online store.</p>
        <div className="mt-6 flex items-center justify-center gap-3 text-sm">
          <Link href="/login" className="rounded border border-gray-300 px-4 py-2 hover:bg-gray-50">
            Sign in
          </Link>
          <Link href="/register" className="rounded bg-gray-900 px-4 py-2 text-white">
            Create your store
          </Link>
        </div>
      </div>
    </main>
  );
}
