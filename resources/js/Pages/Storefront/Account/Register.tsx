import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import StoreLayout from '@/Components/Storefront/StoreLayout';
import { errorMessage, forgetCart, storefrontFetch, validationErrors } from '@/Storefront/api';
import type { StorefrontPageProps } from '@/Storefront/types';

export default function Register({ storefront, seo }: StorefrontPageProps) {
  const [form, setForm] = useState({ name: '', email: '', phone: '', password: '', password_confirmation: '' });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const base = storefront.base_path;

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setError(null);
    try {
      await storefrontFetch(storefront, '/storefront/session/register', { method: 'POST', body: { ...form, phone: form.phone || null } });
      forgetCart(storefront);
      router.visit(`${base}/account`);
    } catch (e) {
      setErrors(validationErrors(e));
      setError(Object.keys(validationErrors(e)).length === 0 ? errorMessage(e) : null);
      setBusy(false);
    }
  }

  const field = (key: keyof typeof form, label: string, props: Record<string, unknown>) => (
    <label className="block text-sm">
      <span className="mb-1 block text-sf-muted">{label}</span>
      <input value={form[key]} onChange={(e) => setForm({ ...form, [key]: e.target.value })} className="w-full rounded-sf border border-sf-border px-3 py-2" {...props} />
      {errors[key] && <span className="mt-1 block text-xs text-sf-error">{errors[key][0]}</span>}
    </label>
  );

  return (
    <StoreLayout shell={storefront} seo={seo}>
      <div className="mx-auto max-w-sm">
        <h1 className="mb-6 text-3xl font-bold">Create an account</h1>
        <form onSubmit={submit} className="space-y-4">
          {error && (
            <p className="rounded-sf bg-red-50 p-3 text-sm text-sf-error" role="alert">
              {error}
            </p>
          )}
          {field('name', 'Full name', { required: true, autoComplete: 'name' })}
          {field('email', 'Email', { type: 'email', required: true, autoComplete: 'email' })}
          {field('phone', 'Phone (optional)', { autoComplete: 'tel' })}
          {field('password', 'Password (at least 10 characters)', { type: 'password', required: true, autoComplete: 'new-password' })}
          {field('password_confirmation', 'Confirm password', { type: 'password', required: true, autoComplete: 'new-password' })}
          <button type="submit" disabled={busy} className="w-full rounded-sf bg-sf-primary px-4 py-3 font-semibold text-white disabled:opacity-50">
            {busy ? 'Creating your account…' : 'Create account'}
          </button>
        </form>
        <p className="mt-6 text-center text-sm">
          Already have an account?{' '}
          <Link href={`${base}/account/login`} className="text-sf-accent">
            Sign in
          </Link>
        </p>
      </div>
    </StoreLayout>
  );
}
