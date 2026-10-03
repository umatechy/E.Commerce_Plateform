import { Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { AdminCrumbs } from '@/Components/AdminPage';
import AgentInbox from '@/Components/Support/AgentInbox';
import { STORE_CATEGORIES } from '@/lib/support';

/** Module 34 (Phase B26) — the store's inbox for its shoppers' requests. */
export default function Index() {
  return (
    <AuthenticatedLayout>
      <AdminCrumbs />
      <div className="mb-4 flex justify-end">
        <Link href="/support/platform" className="text-sm text-blue-700 hover:underline">
          Need help from us? Your requests to the platform →
        </Link>
      </div>
      <AgentInbox apiBase="/support" title="Customer support" categories={STORE_CATEGORIES} />
    </AuthenticatedLayout>
  );
}
