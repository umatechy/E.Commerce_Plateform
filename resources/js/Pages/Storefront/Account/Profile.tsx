import { useState } from 'react';
import { useT } from '@/Storefront/i18n';
import AccountLayout from '@/Components/Storefront/AccountLayout';
import { errorMessage, storefrontFetch, validationErrors } from '@/Storefront/api';
import { useCustomer, type Customer } from '@/Storefront/account';
import type { StorefrontPageProps } from '@/Storefront/types';

type Notice = { tone: 'ok' | 'error'; text: string } | null;

export default function Profile({ storefront, seo }: StorefrontPageProps) {
  const t = useT();
  const { customer, setCustomer } = useCustomer(storefront, { required: true });

  return (
    <AccountLayout shell={storefront} seo={seo} customer={customer} active="/account/profile" title={t('Profile & security')}>
      {customer && (
        <div className="max-w-xl space-y-10">
          <DetailsForm storefront={storefront} customer={customer} onSaved={setCustomer} />
          <EmailForm storefront={storefront} customer={customer} onSaved={setCustomer} />
          <PasswordForm storefront={storefront} />
          <section>
            <h2 className="mb-2 text-lg font-semibold">{t('Your data')}</h2>
            <p className="mb-3 text-sm text-sf-muted">{t('Download a copy of the personal data this store holds about you.')}</p>
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
  const t = useT();
  const [form, setForm] = useState({ name: customer.name, phone: customer.phone ?? '', marketing_email_opt_in: Boolean(customer.marketing_email_opt_in), whatsapp_opt_in: Boolean(customer.whatsapp_opt_in) });
  const [notice, setNotice] = useState<Notice>(null);
  // Phase P2: the birthday is asked only where the store gives birthday points (or to remove one given before).
  const askBirthday = (storefront.loyalty?.birthday_points ?? 0) > 0 || Boolean(customer.birthday);
  const [birthday, setBirthday] = useState({ month: customer.birthday ? String(customer.birthday.month) : '', day: customer.birthday ? String(customer.birthday.day) : '' });

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    try {
      const body = { ...form, phone: form.phone || null, ...(askBirthday ? { birthday: birthday.month && birthday.day ? { month: Number(birthday.month), day: Number(birthday.day) } : null } : {}) };
      const res = await storefrontFetch<{ data: Customer }>(storefront, '/customer/profile', { method: 'PATCH', body });
      onSaved(res.data);
      setNotice({ tone: 'ok', text: t('Saved.') });
    } catch (e) {
      setNotice({ tone: 'error', text: Object.values(validationErrors(e))[0]?.[0] ?? errorMessage(e) });
    }
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      <h2 className="text-lg font-semibold">{t('Details')}</h2>
      <label className="block text-sm">
        <span className="mb-1 block text-sf-muted">{t('Name')}</span>
        <input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={input} autoComplete="name" />
      </label>
      <label className="block text-sm">
        <span className="mb-1 block text-sf-muted">{t('Phone')}</span>
        <input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className={input} autoComplete="tel" />
      </label>
      {askBirthday && (
        <fieldset className="text-sm">
          <legend className="mb-1 text-sf-muted">{t('Birthday (for a birthday gift of points)')}</legend>
          <div className="flex gap-2">
            <select value={birthday.day} onChange={(e) => setBirthday({ ...birthday, day: e.target.value })} className={input} aria-label={t('Day')}>
              <option value="">{t('Day')}</option>
              {Array.from({ length: 31 }, (_, i) => <option key={i + 1} value={i + 1}>{i + 1}</option>)}
            </select>
            <select value={birthday.month} onChange={(e) => setBirthday({ ...birthday, month: e.target.value })} className={input} aria-label={t('Month')}>
              <option value="">{t('Month')}</option>
              {Array.from({ length: 12 }, (_, i) => <option key={i + 1} value={i + 1}>{new Date(2000, i, 1).toLocaleString(undefined, { month: 'long' })}</option>)}
            </select>
          </div>
          <p className="mt-1 text-xs text-sf-muted">{t('Only the day and month. Leave empty if you prefer not to say.')}</p>
        </fieldset>
      )}
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={form.marketing_email_opt_in} onChange={(e) => setForm({ ...form, marketing_email_opt_in: e.target.checked })} />
        {t('Email me news and offers')}
      </label>
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={form.whatsapp_opt_in} onChange={(e) => setForm({ ...form, whatsapp_opt_in: e.target.checked })} />
        {t('Send my order updates on WhatsApp')}
      </label>
      <button type="submit" className="sf-btn rounded-sf bg-sf-primary px-4 py-2 font-medium text-white">
        {t('Save details')}
      </button>
      <Message notice={notice} />
    </form>
  );
}

function EmailForm({ storefront, customer, onSaved }: Shared & { customer: Customer; onSaved: (c: Customer) => void }) {
  const t = useT();
  const [email, setEmail] = useState(customer.email);
  const [password, setPassword] = useState('');
  const [notice, setNotice] = useState<Notice>(null);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    try {
      const res = await storefrontFetch<{ data: Customer }>(storefront, '/customer/email', { method: 'PUT', body: { email, current_password: password } });
      onSaved(res.data);
      setPassword('');
      setNotice({ tone: 'ok', text: t('Your email address has been changed.') });
    } catch (e) {
      setNotice({ tone: 'error', text: Object.values(validationErrors(e))[0]?.[0] ?? errorMessage(e) });
    }
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      <h2 className="text-lg font-semibold">{t('Email address')}</h2>
      <input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} className={input} aria-label={t('Email')} autoComplete="email" />
      <input type="password" required value={password} onChange={(e) => setPassword(e.target.value)} placeholder={t('Current password')} aria-label={t('Current password')} className={input} autoComplete="current-password" />
      <button type="submit" className="rounded-sf border border-sf-border px-4 py-2 font-medium">
        {t('Change email')}
      </button>
      <Message notice={notice} />
    </form>
  );
}

function PasswordForm({ storefront }: Shared) {
  const t = useT();
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [notice, setNotice] = useState<Notice>(null);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    try {
      await storefrontFetch(storefront, '/customer/password', { method: 'PUT', body: form });
      setForm({ current_password: '', password: '', password_confirmation: '' });
      setNotice({ tone: 'ok', text: t('Password changed. Other devices have been signed out.') });
    } catch (e) {
      setNotice({ tone: 'error', text: Object.values(validationErrors(e))[0]?.[0] ?? errorMessage(e) });
    }
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      <h2 className="text-lg font-semibold">{t('Password')}</h2>
      <input type="password" required value={form.current_password} onChange={(e) => setForm({ ...form, current_password: e.target.value })} placeholder={t('Current password')} aria-label={t('Current password')} className={input} autoComplete="current-password" />
      <input type="password" required value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} placeholder={t('New password (at least 10 characters)')} aria-label={t('New password')} className={input} autoComplete="new-password" />
      <input type="password" required value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} placeholder={t('Confirm new password')} aria-label={t('Confirm new password')} className={input} autoComplete="new-password" />
      <button type="submit" className="rounded-sf border border-sf-border px-4 py-2 font-medium">
        {t('Change password')}
      </button>
      <Message notice={notice} />
    </form>
  );
}

function DownloadData({ storefront }: Shared) {
  const [error, setError] = useState<string | null>(null);
  const t = useT();

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
        {t('Download my data')}
      </button>
      {error && <p className="mt-2 text-sm text-sf-error">{error}</p>}
    </>
  );
}
