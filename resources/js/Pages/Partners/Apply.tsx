import { FormEvent, useState } from 'react';
import { AdminApiError, adminErrorMessage, adminFetch } from '@/lib/adminApi';

const input = 'mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600';

/**
 * Phase B58 (Module 28 §92, §117) — the public partner page: what an
 * Umar Techy partner does and gets, and the application. Umar Techy reviews
 * each application and sets the terms (the tier) when approving; the answer
 * is the same whether or not the address is already known. The hidden
 * `fax` field is a honeypot for bots.
 */
export default function Apply() {
  const [form, setForm] = useState({ business_name: '', contact_name: '', email: '', phone: '', country: 'PK', website: '', note: '', fax: '' });
  const [accept, setAccept] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const set = (field: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [field]: e.target.value });

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setError(null);
    try {
      await adminFetch('/partner/applications', {
        method: 'POST',
        body: { ...form, phone: form.phone || null, website: form.website || null, note: form.note || null, country: form.country || null, accept_terms: accept },
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
    <label className="block text-sm">
      <span className="text-slate-700">{label}</span>
      <input value={form[key]} onChange={set(key)} className={input} name={key} {...props} />
      {errors[key] && <span className="mt-1 block text-red-600">{errors[key]}</span>}
    </label>
  );

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="bg-brand-900 text-white">
        <div className="mx-auto max-w-5xl px-4 py-14">
          <p className="text-sm font-semibold uppercase tracking-wider text-brand-200">Umar Techy partner program</p>
          <h1 className="mt-2 max-w-2xl text-3xl font-bold sm:text-4xl">Sell online stores to your clients, and earn every time they renew</h1>
          <p className="mt-4 max-w-2xl text-brand-100">
            For agencies, freelancers and consultants who set up businesses online. Bring your clients to Umar Techy: they get a partner price, you earn a commission on what they pay.
          </p>
        </div>
      </header>

      <main className="mx-auto grid max-w-5xl gap-10 px-4 py-12 lg:grid-cols-[1fr_26rem]">
        <section aria-labelledby="how">
          <h2 id="how" className="text-xl font-semibold text-slate-900">How it works</h2>
          <ol className="mt-4 space-y-4">
            {[
              ['Apply', 'Tell us about your business. We review each application and agree your terms with you.'],
              ['Bring clients', 'Share your sign-up link, or create stores for your clients from your partner portal. Each client owns their store.'],
              ['Earn', 'You earn a commission on your clients’ paid invoices: on the first one, and on renewals for the period of your terms.'],
              ['Get paid', 'Follow every store, commission and payout in your portal. Umar Techy pays you by bank transfer, JazzCash or Easypaisa.'],
            ].map(([title, text], i) => (
              <li key={title} className="flex gap-4">
                <span aria-hidden="true" className="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-brand-700 font-bold text-white">{i + 1}</span>
                <span>
                  <span className="block font-semibold text-slate-900">{title}</span>
                  <span className="block text-sm text-slate-600">{text}</span>
                </span>
              </li>
            ))}
          </ol>
          <div className="mt-8 rounded-lg border border-slate-200 bg-white p-5 text-sm text-slate-600">
            <p className="font-semibold text-slate-900">What you never get</p>
            <p className="mt-1">Access to your clients’ customers, orders, payments or settings. Your portal shows what you need to look after them: their store, its package and whether it is set up.</p>
          </div>
        </section>

        <section aria-labelledby="apply" className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
          <h2 id="apply" className="text-lg font-semibold text-slate-900">Apply</h2>
          {sent ? (
            <div className="mt-4" role="status">
              <p className="font-medium text-slate-900">Thank you for applying.</p>
              <p className="mt-2 text-sm text-slate-600">Our team reviews each application. If it is approved, we email you an invitation to the partner portal.</p>
            </div>
          ) : (
            <form onSubmit={submit} className="mt-4 space-y-4">
              {field('business_name', 'Business name', { required: true, maxLength: 160, autoComplete: 'organization' })}
              {field('contact_name', 'Your name', { required: true, maxLength: 120, autoComplete: 'name' })}
              {field('email', 'Work email', { required: true, type: 'email', maxLength: 190, autoComplete: 'email' })}
              {field('phone', 'Phone (optional)', { type: 'tel', maxLength: 30, autoComplete: 'tel' })}
              {field('website', 'Website (optional)', { type: 'url', maxLength: 255, placeholder: 'https://' })}
              <label className="block text-sm">
                <span className="text-slate-700">About your clients (optional)</span>
                <textarea rows={3} maxLength={2000} value={form.note} onChange={set('note')} className={input} name="note" placeholder="What kind of businesses you work with, and how many stores you expect" />
              </label>
              {/* Honeypot: hidden from people and screen readers; bots that fill it are refused. */}
              <div aria-hidden="true" className="absolute -left-[9999px] h-px w-px overflow-hidden">
                <label>
                  Fax
                  <input tabIndex={-1} autoComplete="off" value={form.fax} onChange={set('fax')} name="fax" />
                </label>
              </div>
              <label className="flex items-start gap-2 text-sm">
                <input type="checkbox" required checked={accept} onChange={(e) => setAccept(e.target.checked)} className="mt-1" />
                <span className="text-slate-700">I agree that Umar Techy contacts me about this application.</span>
              </label>
              {errors.accept_terms && <span className="block text-sm text-red-600">{errors.accept_terms}</span>}
              {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
              <button type="submit" disabled={busy} className="w-full rounded-md bg-brand-700 py-2.5 font-medium text-white hover:bg-brand-800 disabled:opacity-50">
                {busy ? 'Sending…' : 'Send application'}
              </button>
              <p className="text-xs text-slate-500">Already a partner? <a href="/login" className="text-brand-700 underline">Sign in</a></p>
            </form>
          )}
        </section>
      </main>
    </div>
  );
}
