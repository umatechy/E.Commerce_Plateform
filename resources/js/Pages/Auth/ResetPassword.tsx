import { FormEvent, useState } from 'react';
import { AdminApiError, adminErrorMessage, adminFetch } from '@/lib/adminApi';

/**
 * Phase B52 (Module 32 §7.5): choose a new password from the emailed link.
 * The token and email arrive in the #fragment, so they never reach a
 * server log; a successful reset signs the account out everywhere.
 */
type Errors = Partial<Record<'password' | 'password_confirmation' | 'form', string>>;

function fromFragment(): { token: string; email: string } {
  const params = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.hash.replace(/^#/, ''));

  return { token: params.get('token') ?? '', email: params.get('email') ?? '' };
}

export default function ResetPassword() {
  const [{ token, email }] = useState(fromFragment);
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [errors, setErrors] = useState<Errors>({});
  const [done, setDone] = useState(false);
  const [processing, setProcessing] = useState(false);
  const broken = token.length !== 64 || email === '';

  async function submit(e: FormEvent) {
    e.preventDefault();
    setProcessing(true);
    setErrors({});
    try {
      await adminFetch('/auth/password/reset', { method: 'POST', body: { token, email, password, password_confirmation: confirmation } });
      window.history.replaceState(null, '', '/reset-password'); // the token is spent; keep it out of the history
      setDone(true);
    } catch (err) {
      const fields = err instanceof AdminApiError && err.status === 422 ? (err.body.errors as Record<string, string[]> | undefined) : undefined;
      setErrors(fields ? Object.fromEntries(Object.entries(fields).map(([k, v]) => [k, v[0]])) : { form: adminErrorMessage(err) });
    } finally {
      setProcessing(false);
    }
  }

  const input = 'mt-1 block w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600';

  return (
    <div className="mx-auto mt-24 max-w-sm px-4">
      <h1 className="text-xl font-semibold">Choose a new password</h1>
      {done ? (
        <div role="status" className="mt-4 space-y-3 text-sm text-gray-700">
          <p>Your password was changed and you were signed out everywhere.</p>
          <p><a href="/login?ended=reset" className="font-medium text-indigo-700 underline">Sign in</a></p>
        </div>
      ) : broken ? (
        <div role="alert" className="mt-4 space-y-3 text-sm text-gray-700">
          <p>This link is not complete. Open the link from the email again, or ask for a new one.</p>
          <p><a href="/forgot-password" className="text-indigo-700 underline">Ask for a new link</a></p>
        </div>
      ) : (
        <form onSubmit={submit} className="mt-6 space-y-4" noValidate>
          <p className="text-sm text-gray-600">For <strong className="break-all">{email}</strong>. At least 10 characters, with letters.</p>
          <div>
            <label htmlFor="password" className="block text-sm font-medium text-gray-700">New password</label>
            <input id="password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password" autoFocus className={input} />
            {errors.password && <p role="alert" className="mt-1 text-sm text-red-600">{errors.password}</p>}
          </div>
          <div>
            <label htmlFor="password_confirmation" className="block text-sm font-medium text-gray-700">New password again</label>
            <input id="password_confirmation" type="password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} autoComplete="new-password" className={input} />
          </div>
          {errors.form && (
            <p role="alert" className="text-sm text-red-600">
              {errors.form} <a href="/forgot-password" className="underline">Ask for a new link</a>
            </p>
          )}
          <button type="submit" disabled={processing || password === '' || confirmation === ''} className="w-full rounded bg-gray-900 py-2 text-white disabled:opacity-50">
            {processing ? 'Saving…' : 'Save new password'}
          </button>
        </form>
      )}
    </div>
  );
}
