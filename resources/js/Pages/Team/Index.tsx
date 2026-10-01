import { formatDate } from '@/lib/datetime';
import { useCallback, useEffect, useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import { ButtonLink } from '@/Components/ui/Button';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { useAccess } from '@/lib/access';
import ErrorState from '@/Components/ErrorState';
import LoadingState from '@/Components/LoadingState';
import InviteForm from '@/Components/Team/InviteForm';
import MemberList from '@/Components/Team/MemberList';
import { adminErrorMessage, adminFetch } from '@/lib/adminApi';
import { seatsFull, seatText, type TeamInvitation, type TeamMember, type TeamSummary } from '@/lib/team';

const CONFIRM: Record<'suspend' | 'remove', string> = {
  suspend: 'Suspend this person? They lose access to the store until reactivated.',
  remove: 'Remove this person from the team? They lose access; their past actions stay on record.',
};

/** Phase G1 (Module 02 §18–19) — the store's team: members, roles and invitations. */
export default function Index() {
  const [summary, setSummary] = useState<TeamSummary | null>(null);
  const [members, setMembers] = useState<TeamMember[]>([]);
  const [invitations, setInvitations] = useState<TeamInvitation[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [confirming, setConfirming] = useState<{ member: TeamMember; action: 'suspend' | 'remove' } | null>(null);
  const access = useAccess();

  const load = useCallback(async () => {
    try {
      const [s, m, i] = await Promise.all([
        adminFetch<{ data: TeamSummary }>('/team/summary'),
        adminFetch<{ data: TeamMember[] }>('/team/members'),
        adminFetch<{ data: TeamInvitation[] }>('/team/invitations'),
      ]);
      setSummary(s.data);
      setMembers(m.data);
      setInvitations(i.data);
      setError(null);
    } catch (e) {
      setError(adminErrorMessage(e, 'The team could not be loaded.'));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function run(key: string, request: () => Promise<unknown>) {
    setBusy(key);
    setNotice(null);
    try {
      await request();
      await load();
    } catch (e) {
      setNotice(adminErrorMessage(e));
    } finally {
      setBusy(null);
    }
  }

  if (error) {
    return (
      <AdminPage title="Team">
        <ErrorState message={error} onRetry={() => void load()} />
      </AdminPage>
    );
  }
  if (!summary) return <AdminPage title="Team"><LoadingState /></AdminPage>;

  const full = seatsFull(summary.seats);

  return (
    <AdminPage
      title="Team"
      description={seatText(summary.seats)}
      actions={access.can('roles.view') && <ButtonLink href="/team/roles">Roles and permissions</ButtonLink>}
    >

      {notice && <div className="mt-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{notice}</div>}

      {summary.abilities.invite && (
        <div className="mt-6">
          <InviteForm
            roles={summary.roles}
            disabledReason={full ? 'All seats in your package are in use. Remove someone or upgrade to invite more people.' : null}
            onInvited={() => void load()}
          />
        </div>
      )}

      <section className="mt-6 rounded border bg-white p-4">
        <h2 className="mb-2 font-semibold">Members</h2>
        <MemberList
          members={members}
          roles={summary.roles}
          canManage={summary.abilities.manage}
          busy={busy}
          onRoleChange={(member, role) => void run(member.id, () => adminFetch(`/team/members/${member.id}`, { method: 'PATCH', body: { role } }))}
          onAction={(member, action) => {
            if (action !== 'reactivate') {
              setConfirming({ member, action });

              return;
            }
            void run(member.id, () => adminFetch(`/team/members/${member.id}/reactivate`, { method: 'POST' }));
          }}
        />
      </section>

      {invitations.length > 0 && (
        <section className="mt-6 rounded border bg-white p-4">
          <h2 className="mb-2 font-semibold">Open invitations</h2>
          <ul className="divide-y text-sm">
            {invitations.map((invitation) => (
              <li key={invitation.id} className="flex items-center justify-between py-2">
                <div>
                  <span className="font-medium">{invitation.email}</span>
                  <span className="ml-2 text-gray-500">
                    {invitation.role.name} · {invitation.status === 'expired' ? 'expired' : `expires ${formatDate(invitation.expires_at)}`}
                  </span>
                </div>
                {summary.abilities.invite && (
                  <div className="flex gap-3">
                    <button type="button" disabled={busy === invitation.id} onClick={() => void run(invitation.id, () => adminFetch(`/team/invitations/${invitation.id}/resend`, { method: 'POST' }))} className="text-blue-700 hover:underline disabled:opacity-50">
                      Resend
                    </button>
                    <button type="button" disabled={busy === invitation.id} onClick={() => void run(invitation.id, () => adminFetch(`/team/invitations/${invitation.id}`, { method: 'DELETE' }))} className="text-red-700 hover:underline disabled:opacity-50">
                      Revoke
                    </button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        </section>
      )}

      <ConfirmDialog
        open={confirming !== null}
        title={confirming?.action === 'remove' ? 'Remove this person?' : 'Suspend this person?'}
        confirmLabel={confirming?.action === 'remove' ? 'Remove from team' : 'Suspend'}
        busy={busy !== null}
        onClose={() => setConfirming(null)}
        onConfirm={() => {
          if (confirming === null) return;
          const { member, action } = confirming;
          const path = action === 'remove' ? `/team/members/${member.id}` : `/team/members/${member.id}/${action}`;
          void run(member.id, () => adminFetch(path, { method: action === 'remove' ? 'DELETE' : 'POST' })).then(() => setConfirming(null));
        }}
      >
        <p>
          <strong>{confirming?.member.name}</strong> ({confirming?.member.email}): {confirming ? CONFIRM[confirming.action] : ''}
        </p>
      </ConfirmDialog>
    </AdminPage>
  );
}
