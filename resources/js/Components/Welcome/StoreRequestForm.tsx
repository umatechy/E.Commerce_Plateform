import { FormEvent, useEffect, useState } from 'react';
import Icon from '@/Components/ui/Icon';
import { AdminApiError, adminErrorMessage, adminFetch } from '@/lib/adminApi';

/**
 * Phase S1 — "Ask for your store" (owner decision 2026-10-30: the platform
 * is closed to sign-up; the Umar Techy team builds every store). The
 * request reaches Super Admin → Store requests and the team by email.
 * `website` is a honeypot for bots.
 */
type Option = { value: string; label: string };
type Plan = { code: string; name: string };

export const HOSTING_CHOICES: { value: 'umar_techy' | 'own_server' | 'not_sure'; title: string; text: string }[] = [
  { value: 'umar_techy', title: 'Umar Techy hosting', text: 'We run your store for you; billed with your yearly renewal.' },
  { value: 'own_server', title: 'My own server', text: 'We install your store on your server. Payment comes first; no free trial.' },
  { value: 'not_sure', title: 'Not sure yet', text: 'We help you choose.' },
];

const input = 'mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-slate-900 shadow-sm transition focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30';

export default function StoreRequestForm({ plans, categories, whatsapp, packageCode }: { plans: Plan[]; categories: Option[]; whatsapp: string | null; packageCode: string }) {
  const [form, setForm] = useState({ business_name: '', contact_name: '', email: '', phone: '', city: '', business_category: '', package_code: packageCode, domain: '', hosting: 'umar_techy', message: '', website: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const set = (field: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [field]: e.target.value });
  // A package chosen on its card fills the form.
  useEffect(() => { if (packageCode) setForm((f) => ({ ...f, package_code: packageCode })); }, [packageCode]);
  const digits = (whatsapp ?? '').replace(/\D/g, '');

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setError(null);
    try {
      const nullable = (v: string) => (v.trim() === '' ? null : v.trim());
      await adminFetch('/public/store-requests', {
        method: 'POST',
        body: { ...form, city: nullable(form.city), business_category: nullable(form.business_category), package_code: nullable(form.package_code), domain: nullable(form.domain), message: nullable(form.message), website: form.website || null },
      });
      setSent(true);
    } catch (e) {
      const body = e instanceof AdminApiError ? (e.body.errors as Record<string, string[]> | undefined) : undefined;
      if (body) setErrors(Object.fromEntries(Object.entries(body).map(([key, messages]) => [key, messages[0]])));
      else setError(adminErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  const field = (key: keyof typeof form, label: string, props: Record<string, unknown> = {}) => (
    <label className="block text-sm font-medium text-slate-700">
      {label}
      <input value={form[key]} onChange={set(key)} className={input} name={key} aria-invalid={errors[key] ? true : undefined} {...props} />
      {errors[key] && <span role="alert" className="mt-1 block text-xs font-medium text-red-600">{errors[key]}</span>}
    </label>
  );

  if (sent) {
    return (
      <div role="status" className="rounded-3xl border border-emerald-200 bg-emerald-50 p-8 text-center">
        <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-600 text-white"><Icon name="checkCircle" size={28} /></span>
        <h3 className="mt-4 text-xl font-bold text-slate-900">Thank you — we received your request</h3>
        <p className="mt-2 text-slate-600">Our team will contact you soon on {form.phone} or by email to talk about your products, your domain and your hosting.</p>
      </div>
    );
  }

  return (
    <form onSubmit={submit} noValidate className="relative space-y-5 rounded-3xl border border-slate-200 bg-white p-6 shadow-xl shadow-indigo-900/5 sm:p-8">
      {error && <p role="alert" className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}
      <div className="grid gap-4 sm:grid-cols-2">
        {field('contact_name', 'Your name', { required: true, maxLength: 120, autoComplete: 'name' })}
        {field('business_name', 'Business name', { required: true, maxLength: 160, autoComplete: 'organization' })}
        {field('phone', 'WhatsApp / phone', { required: true, maxLength: 30, inputMode: 'tel', autoComplete: 'tel', placeholder: '03001234567' })}
        {field('email', 'Email', { required: true, type: 'email', maxLength: 190, autoComplete: 'email' })}
        {field('city', 'City (optional)', { maxLength: 80, autoComplete: 'address-level2' })}
        <label className="block text-sm font-medium text-slate-700">
          What you sell (optional)
          <select value={form.business_category} onChange={set('business_category')} className={input} name="business_category">
            <option value="">Choose…</option>
            {categories.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
          </select>
        </label>
        <label className="block text-sm font-medium text-slate-700">
          Package (optional)
          <select value={form.package_code} onChange={set('package_code')} className={input} name="package_code">
            <option value="">Help me choose</option>
            {plans.map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}
          </select>
          {errors.package_code && <span role="alert" className="mt-1 block text-xs font-medium text-red-600">{errors.package_code}</span>}
        </label>
        {field('domain', 'Your domain (optional)', { maxLength: 190, placeholder: 'mystore.pk', autoCapitalize: 'off', spellCheck: false })}
      </div>
      <fieldset>
        <legend className="text-sm font-medium text-slate-700">Where should your store run?</legend>
        <div className="mt-2 grid gap-3 sm:grid-cols-3">
          {HOSTING_CHOICES.map((h) => (
            <label key={h.value} className={`cursor-pointer rounded-2xl border p-4 text-sm transition hover:border-indigo-300 ${form.hosting === h.value ? 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-500/20' : 'border-slate-200'}`}>
              <input type="radio" name="hosting" value={h.value} checked={form.hosting === h.value} onChange={set('hosting')} className="sr-only" />
              <span className="block font-semibold text-slate-900">{h.title}</span>
              <span className="mt-1 block text-slate-600">{h.text}</span>
            </label>
          ))}
        </div>
      </fieldset>
      <label className="block text-sm font-medium text-slate-700">
        Anything else (optional)
        <textarea rows={3} maxLength={2000} value={form.message} onChange={set('message')} className={input} name="message" placeholder="How many products, which payment methods, when you want to start…" />
      </label>
      {/* Honeypot: hidden from people and screen readers; bots that fill it are refused. */}
      <div aria-hidden="true" className="absolute -left-[9999px] h-px w-px overflow-hidden">
        <label>Website<input tabIndex={-1} autoComplete="off" value={form.website} onChange={set('website')} name="website" /></label>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <button type="submit" disabled={busy} className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-indigo-500 to-violet-500 px-6 py-3 font-semibold text-white shadow-lg shadow-indigo-500/30 transition hover:brightness-110 disabled:opacity-60 motion-reduce:transition-none">
          {busy ? 'Sending…' : 'Send my request'}<Icon name="arrowRight" size={18} />
        </button>
        {digits && (
          <a href={`https://wa.me/${digits}`} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-2 rounded-xl border border-emerald-300 bg-emerald-50 px-5 py-3 font-semibold text-emerald-800 transition hover:bg-emerald-100">
            <Icon name="chat" size={18} />Or message us on WhatsApp
          </a>
        )}
      </div>
    </form>
  );
}
