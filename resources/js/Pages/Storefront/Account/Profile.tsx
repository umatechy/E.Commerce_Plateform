import { useState } from 'react';
import AccountLayout from '@/Components/Storefront/AccountLayout';
import { errorMessage, storefrontFetch, validationErrors } from '@/Storefront/api';
import { useCustomer, type Customer } from '@/Storefront/account';
import type { StorefrontPageProps } from '@/Storefront/types';

type Notice = { tone: 'ok' | 'error'; text: string } | null;

export default function Profile({ storefront, seo }: StorefrontPageProps) {
  const { customer, setCustomer } = useCustomer(storefront, { required: true });

  return (
    <AccountLayout shell={storefront} seo={seo} customer={customer} active="/account/profile" title="Profile & security">
      {customer && (
        <div className="max-w-xl space-y-10">
          <DetailsForm storefront={storefront} customer={customer} onSaved={setCustomer} />
          <EmailForm storefront={storefront} customer={customer} onSaved={setCustomer} />
          <PasswordForm storefront={storefront} />
          <section>
            <h2 className="mb-2 text-lg font-semibold">Your data</h2>
            <p className="mb-3 text-sm text-sf-muted">Download a copy of the personal data this store holds about you.</p>
            <DownloadData storefront={storefront} />
          </section>
        </div>
      )}
    </AccountLayout>
  );
}

type Shared = { storefront: StorefrontPageProps['storefront'] };
const input = 'w-full rounded-sf border border-sf-border px-3 py-2';

function Message({ notice }: { notice: Notice }) {
  if (!notice) return null;

  return (
    <p className={`text-sm ${notice.tone === 'ok' ? 'text-sf-success' : 'text-sf-error'}`} role={notice.tone === 'ok' ? 'status' : 'alert'}>
      {notice.text}
    </p>
  );
}

function DetailsForm({ storefront, customer, onSaved }: Shared & { customer: Customer; onSaved: (c: Customer) => void }) {
  const [form, setForm] = useState({ name: customer.name, phone: customer.phone ?? '', marketing_email_opt_in: Boolean(customer.marketing_email_opt_in) });
  const [notice, setNotice] = useState<Notice>(null);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    try {
      const res = await storefrontFetch<{ data: Customer }>(storefront, '/customer/profile', { method: 'PATCH', body: { ...form, phone: form.phone || null } });
      onSaved(res.data);
      setNotice({ tone: 'ok', text: 'Saved.' });
    } catch (e) {
      setNotice({ tone: 'error', text: Object.values(validationErrors(e))[0]?.[0] ?? errorMessage(e) });
    }
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      <h2 className="text-lg font-semibold">Details</h2>
      <label className="block text-sm">
        <span className="mb-1 block text-sf-muted">Name</span>
        <input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={input} autoComplete="name" />
      </label>
      <label className="block text-sm">
        <span className="mb-1 block text-sf-muted">Phone</span>
        <input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className={input} autoComplete="tel" />
      </label>
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={form.marketing_email_opt_in} onChange={(e) => setForm({ ...form, marketing_email_opt_in: e.target.checked })} />
        Email me news and offers
      </label>
      <button type="submit" className="rounded-sf bg-sf-primary px-4 py-2 font-medium text-white">
        Save details
      </button>
      <Message notice={notice} />
    </form>
  );
}

function EmailForm({ storefront, customer, onSaved }: Shared & { customer: Customer; onSaved: (c: Customer) => void }) {
  const [email, setEmail] = useState(customer.email);
  const [password, setPassword] = useState('');
  const [notice, setNotice] = useState<Notice>(null);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    try {
      const res = await storefrontFetch<{ data: Customer }>(storefront, '/customer/email', { method: 'PUT', body: { email, current_password: password } });
      onSaved(res.data);
      setPassword('');
      setNotice({ tone: 'ok', text: 'Your email address has been changed.' });
    } catch (e) {
      setNotice({ tone: 'error', text: Object.values(validationErrors(e))[0]?.[0] ?? errorMessage(e) });
    }
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      <h2 className="text-lg font-semibold">Email address</h2>
      <input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} className={input} aria-label="Email" autoComplete="email" />
      <input type="password" required value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Current password" aria-label="Current password" className={input} autoComplete="current-password" />
      <button type="submit" className="rounded-sf border border-sf-border px-4 py-2 font-medium">
        Change email
      </button>
      <Message notice={notice} />
    </form>
  );
}

function PasswordForm({ storefront }: Shared) {
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [notice, setNotice] = useState<Notice>(null);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    try {
      await storefrontFetch(storefront, '/customer/password', { method: 'PUT', body: form });
      setForm({ current_password: '', password: '', password_confirmation: '' });
      setNotice({ tone: 'ok', text: 'Password changed. Other devices have been signed out.' });
    } catch (e) {
      setNotice({ tone: 'error', text: Object.values(validationErrors(e))[0]?.[0] ?? errorMessage(e) });
    }
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      <h2 className="text-lg font-semibold">Password</h2>
      <input type="password" required value={form.current_password} onChange={(e) => setForm({ ...form, current_password: e.target.value })} placeholder="Current password" aria-label="Current password" className={input} autoComplete="current-password" />
      <input type="password" required value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} placeholder="New password (at least 10 characters)" aria-label="New password" className={input} autoComplete="new-password" />
      <input type="password" required value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} placeholder="Confirm new password" aria-label="Confirm new password" className={input} autoComplete="new-password" />
      <button type="submit" className="rounded-sf border border-sf-border px-4 py-2 font-medium">
        Change password
      </button>
      <Message notice={notice} />
    </form>
  );
}

function DownloadData({ storefront }: Shared) {
  const [error, setError] = useState<string | null>(null);

  async function download() {
    try {
      const res = await storefrontFetch<{ data: unknown }>(storefront, '/customer/personal-data');
      const url = URL.createObjectURL(new Blob([JSON.stringify(res.data, null, 2)], { type: 'application/json' }));
      const link = document.createElement('a');
      link.href = url;
      link.download = `${storefront.store.slug}-my-data.json`;
      link.click();
      URL.revokeObjectURL(url);
    } catch (e) {
      setError(errorMessage(e));
    }
  }

  return (
    <>
      <button type="button" onClick={download} className="rounded-sf border border-sf-border px-4 py-2 text-sm font-medium">
        Download my data
      </button>
      {error && <p className="mt-2 text-sm text-sf-error">{error}</p>}
    </>
  );
}
