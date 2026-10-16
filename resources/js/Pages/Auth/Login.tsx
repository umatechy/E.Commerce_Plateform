import { FormEvent, useState } from 'react';
import { AdminApiError, adminErrorMessage, adminFetch } from '@/lib/adminApi';
import AuthShell, { FieldError, FormAlert, Spinner, authButton, authInput, authLink } from '@/Components/Auth/AuthShell';
import PasswordField from '@/Components/Auth/PasswordField';
import CodeField from '@/Components/Auth/CodeField';
import Icon from '@/Components/ui/Icon';

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
 *
 * UI-1: the sign-in frame, show/hide password, the code as six boxes (a
 * pasted code is sent at once) and a recovery code as a fallback.
 */
type Errors = Partial<Record<'email' | 'password' | 'code' | 'form', string>>;

/** Phase B52: why a session ended, when the server signed it out (TrackUserSession). */
const ENDED: Record<string, string> = {
  idle_timeout: 'You were signed out after a period without activity.',
  expired: 'Your sign-in reached its time limit.',
  password_changed: 'Your password was changed. Sign in with the new one.',
  reset: 'Your password was reset. Sign in with the new one.',
  revoked: 'This sign-in was ended.',
};

export default function Login({ intended }: { intended?: string | null }) {
  const ended = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('ended');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [needsCode, setNeedsCode] = useState(false);
  const [recovery, setRecovery] = useState(false);
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

  async function sendCode(value: string) {
    if (processing) return;
    setProcessing(true);
    setErrors({});
    try {
      await adminFetch('/auth/login/mfa', { method: 'POST', body: { code: value } });
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
        setCode('');
        fail(error);
      }
    }
  }

  if (needsCode) {
    return (
      <AuthShell
        title="Two-step sign-in"
        subtitle={recovery ? 'Enter one of the recovery codes you saved when you turned on two-step sign-in. Each works once.' : 'Open your authenticator app and enter the 6-digit code for this account. The code changes every 30 seconds.'}
        footer={
          <button type="button" className={authLink} onClick={() => { setNeedsCode(false); setCode(''); setErrors({}); setRecovery(false); }}>
            <span className="inline-flex items-center gap-1"><Icon name="arrowLeft" size={16} />Use another account</span>
          </button>
        }
      >
        <form onSubmit={(e) => { e.preventDefault(); void sendCode(code); }} className="space-y-5" noValidate>
          <div>
            <label htmlFor="code" className={recovery ? 'block text-sm font-medium text-slate-700' : 'sr-only'}>{recovery ? 'Recovery code' : 'Code'}</label>
            {recovery ? (
              <input id="code" type="text" value={code} onChange={(e) => setCode(e.target.value)} autoComplete="off" autoFocus spellCheck={false}
                aria-invalid={errors.code ? true : undefined} aria-describedby={errors.code ? 'code-error' : undefined} className={`${authInput} font-mono tracking-wider`} />
            ) : (
              <CodeField id="code" value={code} onChange={setCode} onComplete={(v) => void sendCode(v)} invalid={!!errors.code} describedBy={errors.code ? 'code-error' : undefined} />
            )}
            <FieldError id="code-error" message={errors.code} />
          </div>

          {errors.form && <FormAlert>{errors.form}</FormAlert>}

          <button type="submit" disabled={processing || code.trim() === ''} className={authButton}>
            {processing ? <><Spinner />Checking…</> : 'Continue'}
          </button>
          <p className="text-center text-sm">
            <button type="button" className={authLink} onClick={() => { setRecovery((r) => !r); setCode(''); setErrors({}); }}>
              {recovery ? 'Use the code from my app' : 'Lost your phone? Use a recovery code'}
            </button>
          </p>
        </form>
      </AuthShell>
    );
  }

  return (
    <AuthShell
      title="Welcome back"
      subtitle="Sign in to manage your store."
      footer={<>New here? <a href="/register" className={authLink}>Create your store</a></>}
    >
      <form onSubmit={submitPassword} className="space-y-5" noValidate>
        {ended && <FormAlert tone="info">{ENDED[ended] ?? ENDED.revoked}</FormAlert>}
        <div>
          <label htmlFor="email" className="block text-sm font-medium text-slate-700">Email</label>
          <input id="email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="username" autoFocus
            aria-invalid={errors.email ? true : undefined} aria-describedby={errors.email ? 'email-error' : undefined} className={authInput} placeholder="you@example.com" />
          <FieldError id="email-error" message={errors.email} />
        </div>

        <div>
          <PasswordField id="password" label="Password" value={password} onChange={setPassword} error={errors.password} autoComplete="current-password" />
          <p className="mt-2 text-right text-sm"><a href="/forgot-password" className={authLink}>Forgot your password?</a></p>
        </div>

        {errors.form && <FormAlert>{errors.form}</FormAlert>}

        <button type="submit" disabled={processing} className={authButton}>
          {processing ? <><Spinner />Signing in…</> : 'Sign in'}
        </button>
        <p className="flex items-center justify-center gap-1.5 text-xs text-slate-500">
          <Icon name="lock" size={14} />Protected with two-step sign-in for store owners
        </p>
      </form>
    </AuthShell>
  );
}
