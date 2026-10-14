import { FormEvent, useState } from 'react';
import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import { FormError, TextField } from '@/Components/ui/Form';
import { Card, QueryState } from '@/Components/ui/Page';
import { useApi } from '@/lib/useApi';
import { useAction, useForm } from '@/lib/useForm';
import { adminFetch } from '@/lib/adminApi';
import { formatDateTime } from '@/lib/datetime';

/**
 * Phase B52 (SRS AUTH-005; Module 32 §7, §9): on the Security page, below
 * two-step sign-in — change the password, and see and end the places the
 * account is signed in. Ending a session signs that device out on its next
 * click; changing the password signs out every other device.
 */
type Session = { id: string; current: boolean; device: string; ip_address: string | null; with_mfa: boolean; privileged: boolean; signed_in_at: string; last_seen_at: string };

export function PasswordCard() {
  const form = useForm({ current_password: '', password: '', password_confirmation: '' });
  const [done, setDone] = useState(false);

  async function save(event: FormEvent) {
    event.preventDefault();
    setDone(false);
    if ((await form.submit(() => adminFetch('/auth/password', { method: 'PUT', body: form.values }), 'Password changed. Your other devices were signed out.')) !== undefined) {
      form.reset({ current_password: '', password: '', password_confirmation: '' });
      setDone(true);
    }
  }

  return (
    <Card title="Password" description="At least 10 characters, with letters. Changing it signs you out on your other devices.">
      <form onSubmit={save} className="max-w-md space-y-3" noValidate>
        <FormError message={form.formError} />
        <TextField label="Current password" type="password" autoComplete="current-password" value={form.values.current_password} onChange={(v) => form.set('current_password', v)} error={form.errors.current_password} required />
        <TextField label="New password" type="password" autoComplete="new-password" value={form.values.password} onChange={(v) => form.set('password', v)} error={form.errors.password} required />
        <TextField label="New password again" type="password" autoComplete="new-password" value={form.values.password_confirmation} onChange={(v) => form.set('password_confirmation', v)} required />
        {done && <p role="status" className="text-sm text-emerald-800">Your password was changed.</p>}
        <Button type="submit" variant="primary" busy={form.busy} busyLabel="Saving…" disabled={form.values.current_password === '' || form.values.password === ''}>Change password</Button>
      </form>
    </Card>
  );
}

export function SessionsCard() {
  const state = useApi<{ data: Session[] }>('/auth/sessions');
  const { busy, run } = useAction();
  const end = (path: string, key: string, body?: unknown) =>
    run(key, () => adminFetch<{ data: Session[] }>(path, { method: path.endsWith('revoke-others') ? 'POST' : 'DELETE', body }), { success: 'Signed out.' }).then((r) => r !== undefined && state.reload());

  return (
    <QueryState state={state} lines={3}>
      {({ data }) => {
        const others = data.filter((s) => !s.current);

        return (
          <Card
            title="Where you’re signed in"
            description="Each browser or device signed in to your account. If you do not recognise one, sign it out and change your password."
            actions={others.length > 0 && <Button size="sm" busy={busy === 'others'} busyLabel="Signing out…" onClick={() => end('/auth/sessions/revoke-others', 'others')}>Sign out all other devices</Button>}
          >
            <ul className="divide-y divide-slate-100">
              {data.map((s) => (
                <li key={s.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-slate-900">
                      {s.device} {s.current && <Badge tone="green">This device</Badge>}
                    </p>
                    <p className="text-xs text-slate-600">
                      {s.ip_address ?? 'Unknown address'} · signed in {formatDateTime(s.signed_in_at)} · last used {formatDateTime(s.last_seen_at)}
                      {s.with_mfa && ' · with two-step code'}
                    </p>
                  </div>
                  {!s.current && (
                    <Button size="sm" variant="ghost" busy={busy === s.id} busyLabel="Signing out…" onClick={() => end(`/auth/sessions/${s.id}`, s.id)}>
                      Sign out<span className="sr-only"> {s.device}</span>
                    </Button>
                  )}
                </li>
              ))}
            </ul>
            {data.some((s) => s.privileged) && <p className="mt-2 text-xs text-slate-500">As Umar Techy staff, you are signed out sooner: after a short time without activity, and after a fixed number of hours at the latest.</p>}
          </Card>
        );
      }}
    </QueryState>
  );
}
