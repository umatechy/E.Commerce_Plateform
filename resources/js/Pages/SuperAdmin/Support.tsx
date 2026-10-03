import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { AdminCrumbs } from '@/Components/AdminPage';
import AgentInbox from '@/Components/Support/AgentInbox';
import { PLATFORM_CATEGORIES } from '@/lib/support';

/** Module 34 (Phase B26) — the platform team's inbox: every store's requests to the platform. */
export default function Support() {
  return (
    <AuthenticatedLayout>
      <AdminCrumbs />
      <AgentInbox apiBase="/super-admin/support" title="Platform support" categories={PLATFORM_CATEGORIES} showStore />
    </AuthenticatedLayout>
  );
}
