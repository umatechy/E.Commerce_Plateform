import { FormEvent, ReactNode, useCallback, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import QRCode from 'qrcode';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { AdminCrumbs } from '@/Components/AdminPage';
import ErrorState from '@/Components/ErrorState';
import LoadingState from '@/Components/LoadingState';
import CodeField from '@/Components/Auth/CodeField';
import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import { TextField } from '@/Components/ui/Form';
import Icon, { type IconName } from '@/Components/ui/Icon';
import { AdminApiError, adminErrorMessage, adminFetch } from '@/lib/adminApi';
import { formatDate } from '@/lib/datetime';
import { PasswordCard, SessionsCard } from '@/Components/Security/AccountSecurity';

/**
 * The signed-in user's own two-step sign-in (Module 32 §8; SRS AUTH-006,
 * AUTH-007). The server holds every rule: this page shows its answers
 * and never keeps the secret or the recovery codes beyond the screen
 * that displays them.
 *
 * Phase B52: the password and the places the account is signed in, below.
 * UI-6: turning it on is a guided set of steps, and an owner who had to
 * turn it on goes on to the dashboard once the recovery codes are saved
 * (before, the page stayed here and its management form looked like a
 * second sign-in).
 */
type Status = { enabled: boolean; confirmed_at: string | null; recovery_codes_remaining: number; required: boolean };
type Setup = { secret: string; otpauth_uri: string };
type FieldErrors = Partial<Record<'password' | 'code' | 'form', string>>;
type Stage = 'password' | 'scan' | 'codes';

function fieldErrors(error: unknown): FieldErrors {
  const fields = error instanceof AdminApiError && error.status === 422 ? (error.body.errors as Record<string, string[]> | undefined) : undefined;

  return fields ? Object.fromEntries(Object.entries(fields).map(([key, messages]) => [key, messages[0]])) : { form: adminErrorMessage(error) };
}

function FormMessage({ message }: { message?: string }) {
  if (!message) return null;

  return <p role="alert" className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"><Icon name="alert" size={16} className="mt-0.5 flex-none" />{message}</p>;
}

/** The three steps of turning it on, with the one in progress marked. */
function Stepper({ stage }: { stage: Stage }) {
  const steps: { id: Stage; label: string }[] = [
    { id: 'password', label: 'Confirm it is you' },
    { id: 'scan', label: 'Connect your phone' },
    { id: 'codes', label: 'Save recovery codes' },
  ];
  const at = steps.findIndex((s) => s.id === stage);

  return (
    <ol className="flex items-center gap-2 text-xs font-medium sm:gap-3 sm:text-sm">
      {steps.map((s, i) => (
        <li key={s.id} className="flex min-w-0 flex-1 items-center gap-2" aria-current={i === at ? 'step' : undefined}>
          <span className={`flex h-7 w-7 flex-none items-center justify-center rounded-full text-xs font-bold transition-colors motion-reduce:transition-none ${i < at ? 'bg-emerald-500 text-white' : i === at ? 'bg-brand-600 text-white ring-4 ring-brand-100' : 'bg-slate-100 text-slate-500'}`}>
            {i < at ? <Icon name="check" size={14} strokeWidth={3} /> : i + 1}
          </span>
          <span className={`hidden truncate sm:block ${i === at ? 'text-slate-900' : 'text-slate-500'}`}>{s.label}</span>
          {i < steps.length - 1 && <span aria-hidden="true" className={`h-px flex-1 ${i < at ? 'bg-emerald-300' : 'bg-slate-200'}`} />}
        </li>
      ))}
    </ol>
  );
}

function Panel({ icon, tone = 'indigo', title, description, aside, children }: { icon: IconName; tone?: 'indigo' | 'emerald' | 'amber'; title: ReactNode; description?: ReactNode; aside?: ReactNode; children?: ReactNode }) {
  const tones = { indigo: 'bg-brand-50 text-brand-600', emerald: 'bg-emerald-50 text-emerald-600', amber: 'bg-amber-50 text-amber-600' };

  return (
    <section className="rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div className="flex items-start gap-4 p-5 sm:p-6">
        <span className={`flex h-11 w-11 flex-none items-center justify-center rounded-xl ${tones[tone]}`}><Icon name={icon} size={22} /></span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-base font-semibold text-slate-900">{title}</h2>
            {aside}
          </div>
          {description && <div className="mt-1 text-sm leading-relaxed text-slate-600">{description}</div>}
        </div>
      </div>
      {children && <div className="border-t border-slate-100 p-5 sm:p-6">{children}</div>}
    </section>
  );
}

function RecoveryCodes({ codes, onDone, doneLabel }: { codes: string[]; onDone: () => void; doneLabel: string }) {
  const [copied, setCopied] = useState(false);
  const copy = () => {
    void navigator.clipboard?.writeText(codes.join('\n')).then(() => setCopied(true), () => setCopied(false));
  };

  return (
    <div className="space-y-4">
      <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        <Icon name="key" size={18} className="mt-0.5 flex-none text-amber-600" />
        <p>Each code signs you in <strong>once</strong> if you lose your phone. They are shown only now — write them down or keep them in a safe place.</p>
      </div>
      <ul aria-label="Recovery codes" className="grid grid-cols-2 gap-2 rounded-xl bg-slate-950 p-4 font-mono text-sm text-emerald-300 sm:grid-cols-2">
        {codes.map((code) => <li key={code} className="rounded-md bg-white/5 px-3 py-1.5 text-center tracking-wider">{code}</li>)}
      </ul>
      <div className="flex flex-wrap gap-2">
        <Button variant="primary" onClick={onDone}>{doneLabel}<Icon name="arrowRight" size={16} /></Button>
        <Button onClick={copy}><Icon name={copied ? 'check' : 'file'} size={16} />{copied ? 'Copied' : 'Copy codes'}</Button>
      </div>
    </div>
  );
}

export default function Index() {
  const [status, setStatus] = useState<Status | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [setup, setSetup] = useState<Setup | null>(null);
  const [qr, setQr] = useState<string | null>(null);
  const [codes, setCodes] = useState<string[] | null>(null);
  // Whether this visit turned two-step sign-in on (rather than managing it).
  const [justEnabled, setJustEnabled] = useState(false);
  const [manage, setManage] = useState(false);
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

  const confirmCode = (value: string) => {
    void run(async () => {
      const body = await adminFetch<{ data: Status & { recovery_codes: string[] } }>('/auth/mfa/confirm', { method: 'POST', body: { code: value } });
      setCodes(body.data.recovery_codes);
      setStatus(body.data);
      setSetup(null);
      setJustEnabled(true);
      // The rest of the admin is open now: refresh what the layout knows.
      router.reload({ only: ['auth'] });
    });
  };

  const confirm = (e: FormEvent) => {
    e.preventDefault();
    confirmCode(code);
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
    void run(async () => {
      setStatus((await adminFetch<{ data: Status }>('/auth/mfa', { method: 'DELETE', body: { password, code } })).data);
      setManage(false);
    });
  };

  // Codes saved: an owner who had to turn it on goes on to the store; anyone else stays here.
  const codesSaved = () => {
    setCodes(null);
    if (justEnabled) window.location.assign('/');
  };

  const stage: Stage = codes && justEnabled ? 'codes' : setup ? 'scan' : 'password';
  const enrolling = status !== null && (!status.enabled || (justEnabled && codes !== null));

  return (
    <AuthenticatedLayout>
      <AdminCrumbs fallback={[{ label: 'Dashboard', href: '/' }, { label: 'Your account' }, { label: 'Security' }]} />
      <div className="mb-6">
        <h1 className="text-2xl font-semibold tracking-tight text-slate-900">Security</h1>
        <p className="mt-1 text-sm text-slate-600">Protect your account with two-step sign-in, and see where you are signed in.</p>
      </div>

      {loadError && !status && (
        <div className="max-w-2xl space-y-3">
          <ErrorState message={loadError} />
          <Button onClick={load}>Try again</Button>
        </div>
      )}
      {!status && !loadError && <LoadingState />}

      {status && enrolling && (
        <div className="max-w-2xl motion-safe:animate-fade-in-up">
          <Panel
            icon="shieldCheck"
            title={status.required && !justEnabled ? 'One step before you manage your store' : 'Turn on two-step sign-in'}
            description="After your password, you enter a 6-digit code from an authenticator app on your phone. Someone who learns your password still cannot sign in."
            aside={<Badge tone={status.enabled ? 'green' : 'amber'}>{status.enabled ? 'On' : 'Off'}</Badge>}
          >
            <Stepper stage={stage} />

            <div className="mt-6">
              {stage === 'password' && (
                <form onSubmit={begin} className="space-y-4" noValidate>
                  <div className="rounded-xl bg-slate-50 p-4 text-sm text-slate-700">
                    <p className="font-medium text-slate-900">You will need an authenticator app on your phone</p>
                    <p className="mt-1">Google Authenticator, Microsoft Authenticator or Authy — free on the Play Store and App Store.</p>
                  </div>
                  <TextField label="Your password" type="password" value={password} onChange={setPassword} error={errors.password} autoComplete="current-password" hint="To make sure it is you." className="max-w-sm" />
                  <FormMessage message={errors.form} />
                  <Button type="submit" variant="primary" busy={busy} busyLabel="Checking…">Continue<Icon name="arrowRight" size={16} /></Button>
                </form>
              )}

              {stage === 'scan' && setup && (
                <form onSubmit={confirm} className="space-y-5" noValidate>
                  <div className="grid gap-5 sm:grid-cols-[auto_1fr] sm:items-center">
                    <div className="mx-auto rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:mx-0">
                      {qr ? <img src={qr} alt="QR code for your authenticator app" width={176} height={176} /> : <div className="h-44 w-44 animate-pulse rounded-lg bg-slate-100" />}
                    </div>
                    <div className="space-y-2 text-sm text-slate-700">
                      <p><span className="font-semibold text-slate-900">1.</span> Open the app and tap <strong>+</strong> (add account).</p>
                      <p><span className="font-semibold text-slate-900">2.</span> Scan this QR code with the phone’s camera.</p>
                      <p className="text-slate-500">Cannot scan? Choose “enter a setup key” and type:</p>
                      <code className="block break-all rounded-lg bg-slate-100 px-3 py-2 font-mono text-xs text-slate-800">{setup.secret}</code>
                    </div>
                  </div>
                  <div>
                    <label htmlFor="enrol-code" className="block text-sm font-medium text-slate-900"><span className="font-semibold">3.</span> Enter the 6-digit code the app shows</label>
                    <p id="enrol-code-hint" className="mt-0.5 text-xs text-slate-500">It changes every 30 seconds — type the one showing now.</p>
                    <div className="mt-2 max-w-sm">
                      <CodeField id="enrol-code" value={code} onChange={setCode} onComplete={confirmCode} invalid={Boolean(errors.code)} describedBy="enrol-code-hint" />
                    </div>
                    {errors.code && <p className="mt-2 text-sm text-red-600">{errors.code}</p>}
                  </div>
                  <FormMessage message={errors.form} />
                  <div className="flex flex-wrap gap-2">
                    <Button type="submit" variant="primary" busy={busy} busyLabel="Checking…" disabled={code.length !== 6}>Turn on two-step sign-in</Button>
                    <Button variant="ghost" onClick={() => { setSetup(null); setErrors({}); }} disabled={busy}>Back</Button>
                  </div>
                </form>
              )}

              {stage === 'codes' && codes && (
                <div className="space-y-4">
                  <p className="flex items-center gap-2 text-sm font-medium text-emerald-700"><Icon name="checkCircle" size={18} />Two-step sign-in is on.</p>
                  <RecoveryCodes codes={codes} onDone={codesSaved} doneLabel="I have saved them — go to my dashboard" />
                </div>
              )}
            </div>
          </Panel>
        </div>
      )}

      {status && !enrolling && (
        <div className="max-w-2xl motion-safe:animate-fade-in-up">
          <Panel
            icon="shieldCheck"
            tone="emerald"
            title="Two-step sign-in"
            aside={<Badge tone="green">On</Badge>}
            description={<>Your account asks for a code from your authenticator app after the password.{status.confirmed_at && <> On since {formatDate(status.confirmed_at)}.</>} Recovery codes left: <strong>{status.recovery_codes_remaining}</strong>.</>}
          >
            {codes ? (
              <RecoveryCodes codes={codes} onDone={codesSaved} doneLabel="I have saved them" />
            ) : !manage ? (
              <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-slate-600">Nothing to do here — you are protected.</p>
                <Button onClick={() => setManage(true)}><Icon name="settings" size={16} />Manage</Button>
              </div>
            ) : (
              <form onSubmit={regenerate} className="space-y-4" noValidate>
                <p className="text-sm text-slate-600">To get new recovery codes or to turn two-step sign-in off, confirm with your password and a current code from the app. <strong>This is not a sign-in</strong> — you are already signed in.</p>
                <div className="grid gap-4 sm:grid-cols-2">
                  <TextField label="Your password" type="password" value={password} onChange={setPassword} error={errors.password} autoComplete="current-password" />
                  <TextField label="Current 6-digit code" inputMode="numeric" value={code} onChange={(v) => setCode(v.replace(/\D/g, '').slice(0, 6))} error={errors.code} autoComplete="one-time-code" />
                </div>
                <FormMessage message={errors.form} />
                <div className="flex flex-wrap gap-2">
                  <Button type="submit" variant="primary" busy={busy} busyLabel="Working…">New recovery codes</Button>
                  <Button variant="danger" disabled={busy} onClick={turnOff}>Turn off</Button>
                  <Button variant="ghost" disabled={busy} onClick={() => { setManage(false); setErrors({}); setPassword(''); setCode(''); }}>Cancel</Button>
                </div>
              </form>
            )}
          </Panel>
        </div>
      )}

      {/* Phase B52 (SRS AUTH-005; Module 32 §7, §9). Hidden while turning two-step sign-in on, so the steps stand alone. */}
      {!enrolling && (
        <div className="mt-6 max-w-2xl space-y-6">
          <SessionsCard />
          <PasswordCard />
        </div>
      )}
    </AuthenticatedLayout>
  );
}
