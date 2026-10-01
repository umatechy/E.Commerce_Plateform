import AdminPage from '@/Components/AdminPage';
import AuditLogView from '@/Components/AuditLogView';

/** Module 32 (Phase B22): the platform-wide audit trail, every store's entries and the platform's own. Platform staff only. */
export default function AuditLog() {
  return (
    <AdminPage title="Platform audit log" description="Every recorded action, across all stores and the platform itself. Entries cannot be changed or deleted.">
      <AuditLogView path="/super-admin/audit-logs" integrityPath="/super-admin/audit-logs/integrity" platform />
    </AdminPage>
  );
}
