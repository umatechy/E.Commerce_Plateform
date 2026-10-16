import { FormEvent, useEffect, useState } from 'react';
import { AdminApiError, adminErrorMessage, adminFetch } from '@/lib/adminApi';
import { forgetHash } from '@/lib/team';

type Invitation = { business_name: string; email: string; tier: string | null; account_exists: boolean; signed_in_as: string | null; signed_in_matches: boolean };

/** The invitation token from the link's #fragment (48 letters and digits), or null. */
export function invitationToken(hash: string): string | null {
  const match = /(?:^#|&)token=([A-Za-z0-9]{40,128})(?:&|$)/.exec(hash);

  return match ? match[1] : null;
}

const input = 'mt-1 block w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600';
const button = 'w-full rounded bg-indigo-700 py-2.5 font-medium text-white hover:bg-indigo-800 disabled:opacity-50';

/**
 * Phase B58 (Module 28 §92) — opened from the partner invitation email.
 * The token is read from the #fragment (never sent to the server in a URL)
 * and removed from the address bar at once, then sent only in POST
 * bodies. The invited address accepts: signed in, by signing in, or by
 * creating its account. The portal then needs two-step sign-in.
 */
export default function Join() {
  const [token] = useState(() => (typeof window === 'undefined' ? null : invitationToken(window.location.hash)));
  const [invitation, setInvitation] = useState<Invitation | null>(null);
  const [fatal, setFatal] = useState<string | null>(null);
  const [fields, setFields] = useState({ name: '', password: '', password_confirmation: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [working, setWorking] = useState(false);

  useEffect(() => {
    const stop = forgetHash();
    if (!token) {
      setFatal('This invitation link is incomplete. Open it again from your email.');
      return stop;
    }
    adminFetch<{ data: Invitation }>('/partner/invitations/lookup', { method: 'POST', body: { token } })
      .then((r) => setInvitation(r.data))
      .catch((e) => setFatal(e instanceof AdminApiError && e.status === 404 ? 'This invitation does not work any more: it was used, replaced or is over 14 days old. Ask Umar Techy for a new one.' : adminErrorMessage(e)));

    return stop;
  }, [token]);

  function fail(error: unknown) {
    const body = error instanceof AdminApiError ? (error.body.errors as Record<string, string[]> | undefined) : undefined;
    setErrors(body ? Object.fromEntries(Object.entries(body).map(([key, messages]) => [key, messages[0]])) : { form: adminErrorMessage(error) });
    setWorking(false);
  }

  function next(mfaRequired: boolean) {
    // The portal opens only with two-step sign-in on.
    window.location.assign(mfaRequired ? '/security' : '/partner');
  }

  async function accept() {
    const r = await adminFetch<{ data: { mfa_required: boolean } }>('/partner/invitations/accept', { method: 'POST', body: { token } });
    next(r.data.mfa_required);
  }

  async function run(action: () => Promise<void>) {
    setWorking(true);
    setErrors({});
    try {
      await action();
    } catch (error) {
      fail(error);
    }
  }

  function createAccount(e: FormEvent) {
    e.preventDefault();
    void run(async () => {
      await adminFetch('/partner/invitations/register', { method: 'POST', body: { token, ...fields } });
      next(true);
    });
  }

  function signInAndAccept(e: FormEvent) {
    e.preventDefault();
    if (!invitation) return;
    void run(async () => {
      await adminFetch('/auth/login', { method: 'POST', body: { email: invitation.email, password: fields.password } });
      await accept();
    });
  }

  return (
    <div className="min-h-screen bg-slate-50">
      <div className="mx-auto max-w-md px-4 pt-20 pb-12">
        <p className="text-sm font-semibold uppercase tracking-wider text-indigo-700">Umar Techy partner program</p>
        <h1 className="mt-2 text-2xl font-semibold text-slate-900">Join as a partner</h1>

        {fatal && <p className="mt-6 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{fatal}</p>}
        {!fatal && !invitation && <p className="mt-6 text-slate-500" role="status">Checking your invitation…</p>}

        {invitation && (
          <div className="mt-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <p className="text-slate-700">
              <strong>{invitation.business_name}</strong> is invited to sell Umar Techy{invitation.tier ? <> as a <strong>{invitation.tier}</strong> partner</> : null}. Accept with the account of <strong>{invitation.email}</strong>.
            </p>
            <ul className="mt-4 list-disc space-y-1 pl-5 text-sm text-slate-600">
              <li>Your partner portal shows your clients’ stores, your commissions and your payouts.</li>
              <li>You never get access to your clients’ customers, orders or settings; each client owns their store.</li>
              <li>Two-step sign-in is required for the portal.</li>
            </ul>

            {invitation.signed_in_as && !invitation.signed_in_matches ? (
              <p className="mt-6 text-sm text-amber-800">
                You are signed in as {invitation.signed_in_as}. This invitation is for {invitation.email}. Sign out, then open the link again.
              </p>
            ) : invitation.signed_in_matches ? (
              <button type="button" disabled={working} onClick={() => void run(accept)} className={`mt-6 ${button}`}>
                {working ? 'Joining…' : 'Accept and open the portal'}
              </button>
            ) : invitation.account_exists ? (
              <form onSubmit={signInAndAccept} className="mt-6 space-y-4">
                <p className="text-sm text-slate-600">You already have an account. Sign in to accept.</p>
                <label className="block">
                  <span className="text-sm text-slate-700">Password</span>
                  <input type="password" required autoComplete="current-password" value={fields.password} onChange={(e) => setFields({ ...fields, password: e.target.value })} className={input} />
                  {errors.email && <span className="mt-1 block text-sm text-red-600">{errors.email}</span>}
                </label>
                <button type="submit" disabled={working} className={button}>{working ? 'Signing in…' : 'Sign in and accept'}</button>
              </form>
            ) : (
              <form onSubmit={createAccount} className="mt-6 space-y-4">
                <label className="block">
                  <span className="text-sm text-slate-700">Your name</span>
                  <input required autoComplete="name" value={fields.name} onChange={(e) => setFields({ ...fields, name: e.target.value })} className={input} />
                  {errors.name && <span className="mt-1 block text-sm text-red-600">{errors.name}</span>}
                </label>
                <label className="block">
                  <span className="text-sm text-slate-700">Choose a password</span>
                  <input type="password" required autoComplete="new-password" value={fields.password} onChange={(e) => setFields({ ...fields, password: e.target.value })} className={input} />
                  {errors.password && <span className="mt-1 block text-sm text-red-600">{errors.password}</span>}
                </label>
                <label className="block">
                  <span className="text-sm text-slate-700">Repeat the password</span>
                  <input type="password" required autoComplete="new-password" value={fields.password_confirmation} onChange={(e) => setFields({ ...fields, password_confirmation: e.target.value })} className={input} />
                </label>
                <button type="submit" disabled={working} className={button}>{working ? 'Creating your account…' : 'Create account and accept'}</button>
              </form>
            )}

            {errors.form && <p className="mt-4 text-sm text-red-600" role="alert">{errors.form}</p>}
          </div>
        )}
      </div>
    </div>
  );
}
