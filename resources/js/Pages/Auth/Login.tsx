import { FormEvent, useState } from 'react';
import { AdminApiError, adminErrorMessage, adminFetch } from '@/lib/adminApi';

/**
 * ADR-002 Surface A: submits to the Sanctum SPA session-login endpoint.
 * No token is ever stored client-side — the session cookie carries auth.
 * Validation errors come from the server (Laravel's standard 422
 * envelope) — this component never invents its own validation rules as
 * a security control (this milestone: "frontend checks are UI helpers
 * only").
 *
 * An account with two-step sign-in (Module 32 §8) answers the password
 * with "mfa_required"; the form then asks for the code. The server
 * remembers who is half-way through — nothing about it is kept here.
 */
type Errors = Partial<Record<'email' | 'password' | 'code' | 'form', string>>;

export default function Login({ intended }: { intended?: string | null }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [needsCode, setNeedsCode] = useState(false);
  const [processing, setProcessing] = useState(false);
  const [errors, setErrors] = useState<Errors>({});

  // Back to the page that asked for a sign-in (a same-site path the
  // server checked), else the home page. A full load picks up the new
  // session in the shared Inertia props.
  const enter = () => window.location.assign(intended ?? '/');

  function fail(error: unknown) {
    const fields = error instanceof AdminApiError && error.status === 422 ? (error.body.errors as Record<string, string[]> | undefined) : undefined;
    setErrors(fields ? Object.fromEntries(Object.entries(fields).map(([key, messages]) => [key, messages[0]])) : { form: adminErrorMessage(error) });
    setProcessing(false);
  }

  async function submitPassword(e: FormEvent) {
    e.preventDefault();
    setProcessing(true);
    setErrors({});
    try {
      const body = await adminFetch<{ data: { mfa_required?: boolean } }>('/auth/login', { method: 'POST', body: { email, password } });
      if (body.data.mfa_required) {
        setNeedsCode(true);
        setProcessing(false);
      } else {
        enter(); // stays "processing" while the next page loads
      }
    } catch (error) {
      fail(error);
    }
  }

  async function submitCode(e: FormEvent) {
    e.preventDefault();
    setProcessing(true);
    setErrors({});
    try {
      await adminFetch('/auth/login/mfa', { method: 'POST', body: { code } });
      enter();
    } catch (error) {
      // The challenge timed out: start again from the password.
      if (error instanceof AdminApiError && error.status === 409) {
        setNeedsCode(false);
        setCode('');
        setPassword('');
        setErrors({ form: 'That took too long. Sign in again.' });
        setProcessing(false);
      } else {
        fail(error);
      }
    }
  }

  if (needsCode) {
    return (
      <div className="mx-auto mt-24 max-w-sm">
        <h1 className="text-xl font-semibold">Enter your code</h1>
        <p className="mt-2 text-sm text-gray-600">Open your authenticator app and enter the 6-digit code. You can also use a recovery code.</p>

        <form onSubmit={submitCode} className="mt-6 space-y-4">
          <div>
            <label htmlFor="code" className="block text-sm font-medium text-gray-700">
              Code
            </label>
            <input
              id="code"
              type="text"
              inputMode="numeric"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              className="mt-1 block w-full rounded border-gray-300"
              autoComplete="one-time-code"
              autoFocus
            />
            {errors.code && <p className="mt-1 text-sm text-red-600">{errors.code}</p>}
          </div>

          {errors.form && <p className="text-sm text-red-600">{errors.form}</p>}

          <button type="submit" disabled={processing} className="w-full rounded bg-gray-900 py-2 text-white disabled:opacity-50">
            {processing ? 'Checking…' : 'Continue'}
          </button>
        </form>
      </div>
    );
  }

  return (
    <div className="mx-auto mt-24 max-w-sm">
      <h1 className="text-xl font-semibold">Sign in</h1>

      <form onSubmit={submitPassword} className="mt-6 space-y-4">
        <div>
          <label htmlFor="email" className="block text-sm font-medium text-gray-700">
            Email
          </label>
          <input
            id="email"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className="mt-1 block w-full rounded border-gray-300"
            autoComplete="username"
          />
          {errors.email && <p className="mt-1 text-sm text-red-600">{errors.email}</p>}
        </div>

        <div>
          <label htmlFor="password" className="block text-sm font-medium text-gray-700">
            Password
          </label>
          <input
            id="password"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className="mt-1 block w-full rounded border-gray-300"
            autoComplete="current-password"
          />
          {errors.password && <p className="mt-1 text-sm text-red-600">{errors.password}</p>}
        </div>

        {errors.form && <p className="text-sm text-red-600">{errors.form}</p>}

        <button type="submit" disabled={processing} className="w-full rounded bg-gray-900 py-2 text-white disabled:opacity-50">
          {processing ? 'Signing in…' : 'Sign in'}
        </button>
      </form>
    </div>
  );
}
