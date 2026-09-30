import { Head } from '@inertiajs/react';

/** Shown instead of a storefront that does not exist or is closed (503 / 404). */
export default function Unavailable({ status, message, store }: { status: number; code: string; message: string; store: { name: string } | null }) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4">
      <Head>
        <title>{store ? store.name : 'Store not found'}</title>
        <meta head-key="robots" name="robots" content="noindex, nofollow" />
      </Head>
      <div className="max-w-md text-center">
        {store && <p className="text-lg font-semibold">{store.name}</p>}
        <h1 className="mt-2 text-2xl font-bold">{status === 404 ? 'Store not found' : 'Temporarily unavailable'}</h1>
        <p className="mt-3 text-gray-600">{message}</p>
      </div>
    </div>
  );
}
