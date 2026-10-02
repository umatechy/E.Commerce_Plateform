import { FormEvent, useEffect, useState } from 'react';
import { AdminApiError, adminErrorMessage, adminFetch } from '@/lib/adminApi';
import { tokenFromHash } from '@/lib/team';

type Invitation = { store: string; role: string; email: string; has_account: boolean; signed_in_as: string | null; expires_at: string };

const input = 'mt-1 block w-full rounded border border-gray-300 px-3 py-2';

/**
 * Phase G1 (Module 02 §18) — opened from the invitation email. The token
 * is read from the #fragment (browsers never send it to the server) and
 * removed from the address bar straight away, then sent only in POST
 * bodies. An existing account signs in here first; a new person creates
 * their account.
 */
export default function AcceptInvitation({ invitationId }: { invitationId: string }) {
  const [token] = useState(() => (typeof window === 'undefined' ? null : tokenFromHash(window.location.hash)));
  const [invitation, setInvitation] = useState<Invitation | null>(null);
  const [fatal, setFatal] = useState<string | null>(null);
  const [fields, setFields] = useState({ name: '', password: '', password_confirmation: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [working, setWorking] = useState(false);

  useEffect(() => {
    if (typeof window !== 'undefined' && window.location.hash) {
      window.history.replaceState(null, '', window.location.pathname);
    }
    if (!token) {
      setFatal('This invitation link is incomplete. Open it again from your email.');
      return;
    }
    adminFetch<{ data: Invitation }>(`/public/invitations/${invitationId}/lookup`, { method: 'POST', body: { token } })
      .then((r) => setInvitation(r.data))
      .catch((e) => setFatal(adminErrorMessage(e, 'This invitation link is invalid or has expired.')));
  }, [invitationId, token]);

  function fail(error: unknown) {
    const body = error instanceof AdminApiError ? (error.body.errors as Record<string, string[]> | undefined) : undefined;
    setErrors(body ? Object.fromEntries(Object.entries(body).map(([key, messages]) => [key, messages[0]])) : { form: adminErrorMessage(error) });
  }

  async function accept(extra: Record<string, string> = {}) {
    await adminFetch(`/public/invitations/${invitationId}/accept`, { method: 'POST', body: { token, ...extra } });
    window.location.assign('/');
  }

  async function submitNew(e: FormEvent) {
    e.preventDefault();
    setWorking(true);
    setErrors({});
    try {
      await accept(fields);
    } catch (error) {
      fail(error);
      setWorking(false);
    }
  }

  async function signInAndAccept(e: FormEvent) {
    e.preventDefault();
    if (!invitation) return;
    setWorking(true);
    setErrors({});
    try {
      await adminFetch('/auth/login', { method: 'POST', body: { email: invitation.email, password: fields.password } });
      await accept();
    } catch (error) {
      fail(error);
      setWorking(false);
    }
  }

  async function acceptSignedIn() {
    setWorking(true);
    setErrors({});
    try {
      await accept();
    } catch (error) {
      fail(error);
      setWorking(false);
    }
  }

  return (
    <div className="mx-auto mt-24 max-w-md px-4">
      <h1 className="text-xl font-semibold">Join a team</h1>

      {fatal && <p className="mt-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{fatal}</p>}
      {!fatal && !invitation && <p className="mt-4 text-gray-500">Checking your invitation…</p>}

      {invitation && (
        <>
          <p className="mt-4 text-gray-700">
            You are invited to join <strong>{invitation.store}</strong> as <strong>{invitation.role}</strong> ({invitation.email}).
          </p>

          {invitation.signed_in_as && invitation.signed_in_as.toLowerCase() !== invitation.email ? (
            <p className="mt-4 text-sm text-amber-800">
              You are signed in as {invitation.signed_in_as}. This invitation is for {invitation.email}. Sign out, then open the link again.
            </p>
          ) : invitation.signed_in_as ? (
            <button type="button" disabled={working} onClick={() => void acceptSignedIn()} className="mt-6 w-full rounded bg-gray-900 py-2 text-white disabled:opacity-50">
              {working ? 'Joining…' : `Join ${invitation.store}`}
            </button>
          ) : invitation.has_account ? (
            <form onSubmit={signInAndAccept} className="mt-6 space-y-4">
              <p className="text-sm text-gray-600">You already have an account. Sign in to accept.</p>
              <label className="block">
                <span className="text-sm text-gray-700">Password</span>
                <input type="password" required autoComplete="current-password" value={fields.password} onChange={(e) => setFields({ ...fields, password: e.target.value })} className={input} />
                {errors.email && <span className="mt-1 block text-sm text-red-600">{errors.email}</span>}
              </label>
              <button type="submit" disabled={working} className="w-full rounded bg-gray-900 py-2 text-white disabled:opacity-50">
                {working ? 'Signing in…' : 'Sign in and join'}
              </button>
            </form>
          ) : (
            <form onSubmit={submitNew} className="mt-6 space-y-4">
              <label className="block">
                <span className="text-sm text-gray-700">Your name</span>
                <input required autoComplete="name" value={fields.name} onChange={(e) => setFields({ ...fields, name: e.target.value })} className={input} />
                {errors.name && <span className="mt-1 block text-sm text-red-600">{errors.name}</span>}
              </label>
              <label className="block">
                <span className="text-sm text-gray-700">Choose a password</span>
                <input type="password" required autoComplete="new-password" value={fields.password} onChange={(e) => setFields({ ...fields, password: e.target.value })} className={input} />
                {errors.password && <span className="mt-1 block text-sm text-red-600">{errors.password}</span>}
              </label>
              <label className="block">
                <span className="text-sm text-gray-700">Repeat the password</span>
                <input type="password" required autoComplete="new-password" value={fields.password_confirmation} onChange={(e) => setFields({ ...fields, password_confirmation: e.target.value })} className={input} />
              </label>
              <button type="submit" disabled={working} className="w-full rounded bg-gray-900 py-2 text-white disabled:opacity-50">
                {working ? 'Creating your account…' : 'Create account and join'}
              </button>
            </form>
          )}

          {errors.form && <p className="mt-4 text-sm text-red-600" role="alert">{errors.form}</p>}
        </>
      )}
    </div>
  );
}
