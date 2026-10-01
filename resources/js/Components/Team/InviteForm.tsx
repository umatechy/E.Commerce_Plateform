import { FormEvent, useState } from 'react';
import { AdminApiError, adminErrorMessage, adminFetch } from '@/lib/adminApi';
import type { TeamRole } from '@/lib/team';

type Props = { roles: TeamRole[]; disabledReason: string | null; onInvited: () => void };

/** Invites someone by email with one of the roles the signed-in person may give. */
export default function InviteForm({ roles, disabledReason, onInvited }: Props) {
  const grantable = roles.filter((role) => role.grantable);
  const [email, setEmail] = useState('');
  const [role, setRole] = useState(grantable.find((r) => r.slug === 'staff')?.slug ?? grantable[0]?.slug ?? '');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState<string | null>(null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSending(true);
    setErrors({});
    setSent(null);
    try {
      await adminFetch('/team/invitations', { method: 'POST', body: { email, role } });
      setSent(email);
      setEmail('');
      onInvited();
    } catch (error) {
      const fields = error instanceof AdminApiError && error.status === 422 ? (error.body.errors as Record<string, string[]> | undefined) : undefined;
      setErrors(fields ? Object.fromEntries(Object.entries(fields).map(([key, messages]) => [key, messages[0]])) : { form: adminErrorMessage(error) });
    } finally {
      setSending(false);
    }
  }

  if (grantable.length === 0) return null;

  return (
    <form onSubmit={submit} className="rounded border bg-white p-4">
      <h2 className="font-semibold">Invite someone</h2>
      {disabledReason && <p className="mt-2 text-sm text-amber-800">{disabledReason}</p>}
      <div className="mt-3 flex flex-wrap items-start gap-3">
        <label className="flex-1">
          <span className="block text-sm text-gray-700">Email</span>
          <input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} className="mt-1 block w-full rounded border-gray-300" />
          {errors.email && <span className="mt-1 block text-sm text-red-600">{errors.email}</span>}
        </label>
        <label>
          <span className="block text-sm text-gray-700">Role</span>
          <select value={role} onChange={(e) => setRole(e.target.value)} className="mt-1 block rounded border-gray-300">
            {grantable.map((r) => (
              <option key={r.slug} value={r.slug}>
                {r.name}
              </option>
            ))}
          </select>
          {errors.role && <span className="mt-1 block text-sm text-red-600">{errors.role}</span>}
        </label>
        <button type="submit" disabled={sending || disabledReason !== null} className="mt-6 rounded bg-gray-900 px-4 py-2 text-sm text-white disabled:opacity-50">
          {sending ? 'Sending…' : 'Send invitation'}
        </button>
      </div>
      {errors.form && <p className="mt-2 text-sm text-red-600">{errors.form}</p>}
      {sent && <p className="mt-2 text-sm text-green-700">Invitation sent to {sent}.</p>}
    </form>
  );
}
