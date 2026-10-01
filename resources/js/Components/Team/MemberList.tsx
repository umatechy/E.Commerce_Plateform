import { MEMBER_STATUS_LABELS, type TeamMember, type TeamRole } from '@/lib/team';

type Props = {
  members: TeamMember[];
  roles: TeamRole[];
  canManage: boolean;
  busy: string | null;
  onRoleChange: (member: TeamMember, role: string) => void;
  onAction: (member: TeamMember, action: 'suspend' | 'reactivate' | 'remove') => void;
};

const STATUS_TONES: Record<TeamMember['status'], string> = {
  active: 'bg-green-50 text-green-700',
  suspended: 'bg-amber-50 text-amber-800',
  revoked: 'bg-gray-100 text-gray-500',
};

/**
 * The store's team. Controls appear only where the server said the
 * signed-in person may act (can_manage); the server checks every call
 * again, including the "no roles beyond your own" rule.
 */
export default function MemberList({ members, roles, canManage, busy, onRoleChange, onAction }: Props) {
  const grantable = roles.filter((role) => role.grantable);

  return (
    <table className="w-full text-left text-sm">
      <thead className="border-b text-xs uppercase text-gray-500">
        <tr>
          <th className="py-2 pr-4">Person</th>
          <th className="py-2 pr-4">Role</th>
          <th className="py-2 pr-4">Status</th>
          <th className="py-2 text-right">Actions</th>
        </tr>
      </thead>
      <tbody>
        {members.map((member) => {
          const editable = canManage && member.can_manage;
          const working = busy === member.id;

          return (
            <tr key={member.id} className="border-b last:border-0">
              <td className="py-3 pr-4">
                <div className="font-medium text-gray-900">
                  {member.name}
                  {member.is_you && <span className="ml-2 text-xs text-gray-500">(you)</span>}
                </div>
                <div className="text-gray-500">{member.email}</div>
              </td>
              <td className="py-3 pr-4">
                {editable && member.status !== 'revoked' && member.role ? (
                  <label>
                    <span className="sr-only">Role for {member.name}</span>
                    <select
                      value={member.role.slug}
                      disabled={working}
                      onChange={(e) => onRoleChange(member, e.target.value)}
                      className="rounded border border-gray-300 bg-white px-2 py-1 text-sm"
                    >
                      {!grantable.some((role) => role.slug === member.role?.slug) && <option value={member.role.slug}>{member.role.name}</option>}
                      {grantable.map((role) => (
                        <option key={role.slug} value={role.slug}>
                          {role.name}
                        </option>
                      ))}
                    </select>
                  </label>
                ) : (
                  <span>{member.is_owner ? 'Owner' : (member.role?.name ?? '—')}</span>
                )}
              </td>
              <td className="py-3 pr-4">
                <span className={`rounded px-2 py-0.5 text-xs ${STATUS_TONES[member.status]}`}>{MEMBER_STATUS_LABELS[member.status]}</span>
              </td>
              <td className="py-3 text-right">
                {editable && member.status !== 'revoked' && (
                  <div className="flex justify-end gap-3">
                    {member.status === 'active' && (
                      <button type="button" disabled={working} onClick={() => onAction(member, 'suspend')} className="text-amber-700 hover:underline disabled:opacity-50">
                        Suspend
                      </button>
                    )}
                    {member.status === 'suspended' && (
                      <button type="button" disabled={working} onClick={() => onAction(member, 'reactivate')} className="text-green-700 hover:underline disabled:opacity-50">
                        Reactivate
                      </button>
                    )}
                    <button type="button" disabled={working} onClick={() => onAction(member, 'remove')} className="text-red-700 hover:underline disabled:opacity-50">
                      Remove
                    </button>
                  </div>
                )}
              </td>
            </tr>
          );
        })}
      </tbody>
    </table>
  );
}
