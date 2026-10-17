import { FormEvent, useState } from 'react';
import { AdminApiError, adminErrorMessage, adminFetch } from '@/lib/adminApi';
import AuthShell, { FieldError, Spinner, authButton, authInput, authLink } from '@/Components/Auth/AuthShell';
import Icon from '@/Components/ui/Icon';

/**
 * Phase B52 (Module 32 §7.5–7.6): a staff account asks for a reset link.
 * The answer is the same whether or not the account exists, so the page
 * never tells anyone which emails have an account.
 */
export default function ForgotPassword() {
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [processing, setProcessing] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setProcessing(true);
    setError(null);
    try {
      await adminFetch('/auth/password/forgot', { method: 'POST', body: { email } });
      setSent(true);
    } catch (err) {
      const fields = err instanceof AdminApiError && err.status === 422 ? (err.body.errors as Record<string, string[]> | undefined) : undefined;
      setError(fields?.email?.[0] ?? adminErrorMessage(err));
    } finally {
      setProcessing(false);
    }
  }

  const back = <a href="/login" className={authLink}><span className="inline-flex items-center gap-1"><Icon name="arrowLeft" size={16} />Back to sign in</span></a>;

  return (
    <AuthShell title={sent ? 'Check your email' : 'Forgot your password?'} subtitle={sent ? undefined : 'Enter the email you sign in with. We will email you a link to choose a new password. Two-step sign-in stays on.'} footer={back}>
      {sent ? (
        <div role="status" className="space-y-3 text-sm text-slate-700">
          <span className="flex h-12 w-12 items-center justify-center rounded-full bg-brand-50 text-brand-600"><Icon name="mail" size={24} /></span>
          <p>If an account exists for <strong className="break-all">{email}</strong>, we have sent it a link to choose a new password. The link works for 60 minutes.</p>
          <p className="text-slate-500">No email? Check your spam folder, or ask again in a minute.</p>
        </div>
      ) : (
        <form onSubmit={submit} className="space-y-5" noValidate>
          <div>
            <label htmlFor="email" className="block text-sm font-medium text-slate-700">Email</label>
            <input id="email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="username" autoFocus
              aria-invalid={error !== null} aria-describedby={error ? 'email-error' : undefined} className={authInput} placeholder="you@example.com" />
            <FieldError id="email-error" message={error} />
          </div>
          <button type="submit" disabled={processing || email.trim() === ''} className={authButton}>
            {processing ? <><Spinner />Sending…</> : 'Send the link'}
          </button>
        </form>
      )}
    </AuthShell>
  );
}
