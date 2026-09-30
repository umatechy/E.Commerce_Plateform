import { FormEvent } from 'react';
import { useApiForm } from '@/lib/useApiForm';

/**
 * ADR-002 Surface A: submits to the Sanctum SPA session-login endpoint.
 * No token is ever stored client-side — the session cookie carries auth.
 * Validation errors come from the server (Laravel's standard 422
 * envelope, surfaced by Inertia's useForm automatically) — this
 * component never invents its own validation rules as a security
 * control (this milestone: "frontend checks are UI helpers only").
 */
export default function Login({ intended }: { intended?: string | null }) {
  const { data, setData, post, processing, errors } = useApiForm({
    email: '',
    password: '',
  });

  function submit(e: FormEvent) {
    e.preventDefault();
    // Back to the page that asked for a sign-in (a same-site path the
    // server checked), else the home page. A full load picks up the new
    // session in the shared Inertia props.
    post('/auth/login', () => window.location.assign(intended ?? '/'));
  }

  return (
    <div className="mx-auto mt-24 max-w-sm">
      <h1 className="text-xl font-semibold">Sign in</h1>

      <form onSubmit={submit} className="mt-6 space-y-4">
        <div>
          <label htmlFor="email" className="block text-sm font-medium text-gray-700">
            Email
          </label>
          <input
            id="email"
            type="email"
            value={data.email}
            onChange={(e) => setData('email', e.target.value)}
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
            value={data.password}
            onChange={(e) => setData('password', e.target.value)}
            className="mt-1 block w-full rounded border-gray-300"
            autoComplete="current-password"
          />
          {errors.password && <p className="mt-1 text-sm text-red-600">{errors.password}</p>}
        </div>

        {errors.form && <p className="text-sm text-red-600">{errors.form}</p>}

        <button
          type="submit"
          disabled={processing}
          className="w-full rounded bg-gray-900 py-2 text-white disabled:opacity-50"
        >
          {processing ? 'Signing in…' : 'Sign in'}
        </button>
      </form>
    </div>
  );
}
