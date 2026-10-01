import { FormEvent, useEffect, useRef, useState } from 'react';
import Dialog from '@/Components/ui/Dialog';
import Button from '@/Components/ui/Button';
import { FormError, TextField } from '@/Components/ui/Form';
import { AdminApiError, adminErrorMessage, adminFetch, fieldErrors, registerStepUpHandler } from '@/lib/adminApi';

/**
 * Step-up (Module 30 §6, Phase B29): a sensitive action asks for the
 * password again, and the two-step code when the account has one.
 *
 * This is the screen side of the existing server mechanism, nothing
 * more: it posts to /auth/step-up, and the server decides. The API layer
 * (adminFetch) opens this dialog when a call answers `step_up_required`
 * and repeats that call once the server accepted the password. Closing
 * the dialog cancels the action; nothing is changed.
 */
export default function StepUpDialog({ mfaEnabled }: { mfaEnabled: boolean }) {
  const [open, setOpen] = useState(false);
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const settle = useRef<((passed: boolean) => void) | null>(null);

  useEffect(() => {
    registerStepUpHandler(
      () =>
        new Promise<boolean>((resolve) => {
          // A second sensitive call while the dialog is open waits for the same answer.
          const previous = settle.current;
          settle.current = (passed) => {
            previous?.(passed);
            resolve(passed);
          };
          setPassword('');
          setCode('');
          setErrors({});
          setFormError(null);
          setOpen(true);
        }),
    );

    return () => {
      registerStepUpHandler(null);
      settle.current?.(false);
      settle.current = null;
    };
  }, []);

  function finish(passed: boolean) {
    setOpen(false);
    setPassword('');
    setCode('');
    settle.current?.(passed);
    settle.current = null;
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setFormError(null);
    try {
      await adminFetch('/auth/step-up', { method: 'POST', body: mfaEnabled ? { password, code } : { password } });
      finish(true);
    } catch (e) {
      const fields = fieldErrors(e);
      setErrors(fields);
      if (Object.keys(fields).length === 0) {
        setFormError(e instanceof AdminApiError && e.status === 0 ? e.message : adminErrorMessage(e, 'Could not confirm your password. Try again.'));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog
      open={open}
      title="Confirm it is you"
      description="This action changes something sensitive, so we ask for your password again. After you confirm, the action continues. You will not be asked again for a few minutes."
      onClose={() => finish(false)}
      busy={busy}
    >
      <form onSubmit={submit} className="space-y-4" noValidate>
        <FormError message={formError} />
        <TextField label="Password" type="password" value={password} onChange={setPassword} error={errors.password} autoComplete="current-password" required data-autofocus />
        {mfaEnabled && (
          <TextField
            label="Two-step code"
            value={code}
            onChange={setCode}
            error={errors.code}
            hint="The 6-digit code from your authenticator app, or a recovery code."
            inputMode="numeric"
            autoComplete="one-time-code"
            required
          />
        )}
        <div className="flex justify-end gap-2">
          <Button onClick={() => finish(false)} disabled={busy}>
            Cancel
          </Button>
          <Button type="submit" variant="primary" busy={busy} busyLabel="Checking…" disabled={password === '' || (mfaEnabled && code === '')}>
            Confirm and continue
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
