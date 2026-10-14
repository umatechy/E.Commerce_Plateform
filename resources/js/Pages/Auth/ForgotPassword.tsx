import { FormEvent, useState } from 'react';
import { AdminApiError, adminErrorMessage, adminFetch } from '@/lib/adminApi';

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

  return (
    <div className="mx-auto mt-24 max-w-sm px-4">
      <h1 className="text-xl font-semibold">Forgot your password?</h1>
      {sent ? (
        <div role="status" className="mt-4 space-y-3 text-sm text-gray-700">
          <p>If an account exists for <strong className="break-all">{email}</strong>, we have sent it a link to choose a new password. The link works for 60 minutes.</p>
          <p>No email? Check your spam folder, or ask again in a minute.</p>
          <p><a href="/login" className="text-indigo-700 underline">Back to sign in</a></p>
        </div>
      ) : (
        <form onSubmit={submit} className="mt-6 space-y-4" noValidate>
          <p className="text-sm text-gray-600">Enter the email you sign in with. We will email you a link to choose a new password. Two-step sign-in stays on.</p>
          <div>
            <label htmlFor="email" className="block text-sm font-medium text-gray-700">Email</label>
            <input id="email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="username" autoFocus
              aria-invalid={error !== null} aria-describedby={error ? 'email-error' : undefined}
              className="mt-1 block w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600" />
            {error && <p id="email-error" role="alert" className="mt-1 text-sm text-red-600">{error}</p>}
          </div>
          <button type="submit" disabled={processing || email.trim() === ''} className="w-full rounded bg-gray-900 py-2 text-white disabled:opacity-50">
            {processing ? 'Sending…' : 'Send the link'}
          </button>
          <p className="text-center text-sm"><a href="/login" className="text-indigo-700 underline">Back to sign in</a></p>
        </form>
      )}
    </div>
  );
}
