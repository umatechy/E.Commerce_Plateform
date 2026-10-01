import AdminPage from '@/Components/AdminPage';
import AuditLogView from '@/Components/AuditLogView';

/** Module 32 (Phase B22): the store's own audit trail. Entries of other stores are never returned by this API. */
export default function AuditLog() {
  return (
    <AdminPage title="Audit log" description="Who did what in your store. Entries cannot be changed or deleted.">
      <AuditLogView path="/audit-logs" integrityPath="/audit-logs/integrity" />
    </AdminPage>
  );
}
