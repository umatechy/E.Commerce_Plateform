import { FormEvent, useCallback, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import QRCode from 'qrcode';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { AdminCrumbs } from '@/Components/AdminPage';
import ErrorState from '@/Components/ErrorState';
import LoadingState from '@/Components/LoadingState';
import { AdminApiError, adminErrorMessage, adminFetch } from '@/lib/adminApi';
import { formatDate } from '@/lib/datetime';

/**
 * The signed-in user's own two-step sign-in (Module 32 §8; SRS AUTH-006,
 * AUTH-007). The server holds every rule: this page shows its answers
 * and never keeps the secret or the recovery codes beyond the screen
 * that displays them.
 */
type Status = { enabled: boolean; confirmed_at: string | null; recovery_codes_remaining: number; required: boolean };
type Setup = { secret: string; otpauth_uri: string };
type FieldErrors = Partial<Record<'password' | 'code' | 'form', string>>;

function fieldErrors(error: unknown): FieldErrors {
  const fields = error instanceof AdminApiError && error.status === 422 ? (error.body.errors as Record<string, string[]> | undefined) : undefined;

  return fields ? Object.fromEntries(Object.entries(fields).map(([key, messages]) => [key, messages[0]])) : { form: adminErrorMessage(error) };
}

function Field({ id, label, type = 'text', value, onChange, error, autoComplete }: {
  id: string; label: string; type?: string; value: string; onChange: (value: string) => void; error?: string; autoComplete?: string;
}) {
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-medium text-gray-700">{label}</label>
      <input id={id} type={type} value={value} onChange={(e) => onChange(e.target.value)} autoComplete={autoComplete} className="mt-1 block w-full max-w-xs rounded border border-gray-300 px-3 py-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600" />
      {error && <p className="mt-1 text-sm text-red-600">{error}</p>}
    </div>
  );
}

function RecoveryCodes({ codes, onDone }: { codes: string[]; onDone: () => void }) {
  return (
    <div className="rounded border border-amber-300 bg-amber-50 p-4">
      <h3 className="font-medium">Save your recovery codes</h3>
      <p className="mt-1 text-sm text-gray-700">
        Each code signs you in once if you lose your phone. They are shown only now. Keep them somewhere safe.
      </p>
      <ul className="mt-3 grid max-w-sm grid-cols-2 gap-1 font-mono text-sm">
        {codes.map((code) => <li key={code}>{code}</li>)}
      </ul>
      <button onClick={onDone} className="mt-4 rounded bg-gray-900 px-4 py-2 text-sm text-white">I have saved them</button>
    </div>
  );
}

export default function Index() {
  const [status, setStatus] = useState<Status | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [setup, setSetup] = useState<Setup | null>(null);
  const [qr, setQr] = useState<string | null>(null);
  const [codes, setCodes] = useState<string[] | null>(null);
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [errors, setErrors] = useState<FieldErrors>({});
  const [busy, setBusy] = useState(false);

  // Never an endless "Loading…": no answer within 15 seconds, or an answer
  // without the status in it, is shown as a failure with a way to try again.
  const load = useCallback(() => {
    setLoadError(null);
    const timeout = new Promise<never>((_, reject) => {
      window.setTimeout(() => reject(new Error('The server did not answer. Check that it is running, then try again.')), 15000);
    });

    Promise.race([adminFetch<{ data?: Status }>('/auth/mfa'), timeout])
      .then((body) => {
        if (!body.data) throw new Error('The server answered without your security settings. Try again, or sign in again.');
        setStatus(body.data);
      })
      .catch((error) => setLoadError(adminErrorMessage(error, error instanceof Error ? error.message : undefined)));
  }, []);

  useEffect(load, [load]);

  // The QR code is drawn in the browser: the secret goes to no other service.
  useEffect(() => {
    if (setup) QRCode.toDataURL(setup.otpauth_uri, { margin: 1, width: 200 }).then(setQr).catch(() => setQr(null));
  }, [setup]);

  async function run(action: () => Promise<void>) {
    setBusy(true);
    setErrors({});
    try {
      await action();
      setPassword('');
      setCode('');
    } catch (error) {
      setErrors(fieldErrors(error));
    } finally {
      setBusy(false);
    }
  }

  const begin = (e: FormEvent) => {
    e.preventDefault();
    void run(async () => setSetup((await adminFetch<{ data: Setup }>('/auth/mfa/setup', { method: 'POST', body: { password } })).data));
  };

  const confirm = (e: FormEvent) => {
    e.preventDefault();
    void run(async () => {
      const body = await adminFetch<{ data: Status & { recovery_codes: string[] } }>('/auth/mfa/confirm', { method: 'POST', body: { code } });
      setCodes(body.data.recovery_codes);
      setStatus(body.data);
      setSetup(null);
      // The rest of the admin is open now: refresh what the layout knows.
      router.reload({ only: ['auth'] });
    });
  };

  const regenerate = (e: FormEvent) => {
    e.preventDefault();
    void run(async () => {
      const body = await adminFetch<{ data: Status & { recovery_codes: string[] } }>('/auth/mfa/recovery-codes', { method: 'POST', body: { password, code } });
      setCodes(body.data.recovery_codes);
      setStatus(body.data);
    });
  };

  const turnOff = () => {
    if (!window.confirm('Turn off two-step sign-in? Your account will be protected by the password alone.')) return;
    void run(async () => setStatus((await adminFetch<{ data: Status }>('/auth/mfa', { method: 'DELETE', body: { password, code } })).data));
  };

  return (
    <AuthenticatedLayout>
      <AdminCrumbs fallback={[{ label: 'Dashboard', href: '/' }, { label: 'Your account' }, { label: 'Security' }]} />
      <h1 className="text-xl font-semibold">Security</h1>

      {loadError && !status && (
        <div className="mt-4 space-y-3">
          <ErrorState message={loadError} />
          <button onClick={load} className="rounded border px-4 py-2 text-sm">Try again</button>
        </div>
      )}
      {!status && !loadError && <LoadingState />}

      {status && (
        <section className="mt-6 max-w-2xl rounded border bg-white p-5">
          <div className="flex items-center justify-between">
            <h2 className="font-medium">Two-step sign-in</h2>
            <span className={`rounded px-2 py-1 text-xs ${status.enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'}`}>
              {status.enabled ? 'On' : 'Off'}
            </span>
          </div>
          <p className="mt-2 text-sm text-gray-600">
            After your password, you enter a code from an authenticator app on your phone. Someone who learns your password still cannot sign in.
          </p>
          {status.required && !status.enabled && (
            <div className="mt-2 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
              <p>Your account must have two-step sign-in. The rest of the admin stays closed until it is on. It takes about a minute:</p>
              <ol className="mt-2 list-decimal space-y-1 pl-5">
                <li>Install an authenticator app on your phone (Google Authenticator, Microsoft Authenticator or Authy).</li>
                <li>Enter your password below and press “Turn on two-step sign-in”.</li>
                <li>Scan the QR code with the app and enter the 6-digit code it shows.</li>
                <li>Save the recovery codes you are given.</li>
              </ol>
            </div>
          )}

          <div className="mt-5 space-y-4">
            {codes && <RecoveryCodes codes={codes} onDone={() => setCodes(null)} />}

            {!status.enabled && !setup && (
              <form onSubmit={begin} className="space-y-3">
                <Field id="password" label="Your password" type="password" value={password} onChange={setPassword} error={errors.password} autoComplete="current-password" />
                {errors.form && <p className="text-sm text-red-600">{errors.form}</p>}
                <button disabled={busy} className="rounded bg-gray-900 px-4 py-2 text-sm text-white disabled:opacity-50">Turn on two-step sign-in</button>
              </form>
            )}

            {!status.enabled && setup && (
              <form onSubmit={confirm} className="space-y-3">
                <p className="text-sm text-gray-700">1. Scan this code with your authenticator app (Google Authenticator, Microsoft Authenticator, Authy…).</p>
                {qr && <img src={qr} alt="QR code for your authenticator app" width={200} height={200} />}
                <p className="text-sm text-gray-700">
                  Cannot scan? Enter this key by hand: <code className="break-all rounded bg-gray-100 px-1">{setup.secret}</code>
                </p>
                <p className="text-sm text-gray-700">2. Enter the 6-digit code the app shows.</p>
                <Field id="code" label="Code" value={code} onChange={setCode} error={errors.code} autoComplete="one-time-code" />
                {errors.form && <p className="text-sm text-red-600">{errors.form}</p>}
                <button disabled={busy} className="rounded bg-gray-900 px-4 py-2 text-sm text-white disabled:opacity-50">Confirm</button>
              </form>
            )}

            {status.enabled && !codes && (
              <form onSubmit={regenerate} className="space-y-3">
                <p className="text-sm text-gray-700">
                  {status.confirmed_at && <>On since {formatDate(status.confirmed_at)}. </>}
                  Recovery codes left: <strong>{status.recovery_codes_remaining}</strong>.
                </p>
                <p className="text-sm text-gray-600">To get new recovery codes or to turn two-step sign-in off, enter your password and a current code.</p>
                <Field id="password" label="Your password" type="password" value={password} onChange={setPassword} error={errors.password} autoComplete="current-password" />
                <Field id="code" label="Code" value={code} onChange={setCode} error={errors.code} autoComplete="one-time-code" />
                {errors.form && <p className="text-sm text-red-600">{errors.form}</p>}
                <div className="flex gap-3">
                  <button disabled={busy} className="rounded bg-gray-900 px-4 py-2 text-sm text-white disabled:opacity-50">New recovery codes</button>
                  <button type="button" disabled={busy} onClick={turnOff} className="rounded border border-red-300 px-4 py-2 text-sm text-red-700 disabled:opacity-50">Turn off</button>
                </div>
              </form>
            )}
          </div>
        </section>
      )}
    </AuthenticatedLayout>
  );
}
