import { FormEvent, useState } from 'react';
import { AdminApiError, adminErrorMessage, adminFetch } from '@/lib/adminApi';
import AuthShell, { FormAlert, Spinner, authButton, authLink } from '@/Components/Auth/AuthShell';
import PasswordField from '@/Components/Auth/PasswordField';
import Icon from '@/Components/ui/Icon';

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

  return (
    <AuthShell
      title={done ? 'Password changed' : 'Choose a new password'}
      subtitle={!done && !broken ? <>For <strong className="break-all text-slate-800">{email}</strong>. At least 10 characters, with letters.</> : undefined}
      footer={<a href="/login" className={authLink}>Back to sign in</a>}
    >
      {done ? (
        <div role="status" className="space-y-4 text-sm text-slate-700">
          <span className="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"><Icon name="checkCircle" size={26} /></span>
          <p>Your password was changed and you were signed out everywhere.</p>
          <a href="/login?ended=reset" className={authButton}>Sign in</a>
        </div>
      ) : broken ? (
        <div role="alert" className="space-y-3 text-sm text-slate-700">
          <p>This link is not complete. Open the link from the email again, or ask for a new one.</p>
          <p><a href="/forgot-password" className={authLink}>Ask for a new link</a></p>
        </div>
      ) : (
        <form onSubmit={submit} className="space-y-5" noValidate>
          <PasswordField id="password" label="New password" value={password} onChange={setPassword} error={errors.password} autoComplete="new-password" strength autoFocus />
          <PasswordField id="password_confirmation" label="New password again" value={confirmation} onChange={setConfirmation} error={errors.password_confirmation} autoComplete="new-password" />
          {errors.form && (
            <FormAlert>
              {errors.form} <a href="/forgot-password" className="underline">Ask for a new link</a>
            </FormAlert>
          )}
          <button type="submit" disabled={processing || password === '' || confirmation === ''} className={authButton}>
            {processing ? <><Spinner />Saving…</> : 'Save new password'}
          </button>
        </form>
      )}
    </AuthShell>
  );
}
