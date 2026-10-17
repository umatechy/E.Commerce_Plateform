import { FormEvent } from 'react';
import { useApiForm } from '@/lib/useApiForm';
import AuthShell, { FieldError, FormAlert, Spinner, authButton, authInput, authLink } from '@/Components/Auth/AuthShell';
import PasswordField from '@/Components/Auth/PasswordField';
import Icon from '@/Components/ui/Icon';

/**
 * Store-owner self-registration. Submits to AuthController::register,
 * which creates the account and, through StoreProvisioningService, the
 * store, its owner membership and its trial atomically server-side — this
 * form never sends a store_id or role, only what a new owner provides.
 *
 * Phase B44 (owner decision 13): what the store sells is chosen here; when
 * Umar Techy has closed public sign-up, the page says whom to contact.
 *
 * UI-1: one page in two parts (your account, your store), so a mistake the
 * server finds is always shown next to its field.
 */
/** Phase B58: `partner` — the Umar Techy partner whose sign-up link brought this visitor. */
type Props = { signupOpen?: boolean; businessCategories?: { value: string; label: string }[]; partner?: string | null };

function Step({ n, title }: { n: number; title: string }) {
  return (
    <h2 className="flex items-center gap-2.5 text-sm font-semibold uppercase tracking-wide text-slate-500">
      <span className="flex h-6 w-6 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">{n}</span>
      {title}
    </h2>
  );
}

export default function Register({ signupOpen = true, businessCategories = [], partner = null }: Props) {
  const { data, setData, post, processing, errors } = useApiForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    store_name: '',
    business_category: '',
    starter_template: '1', // '1' or '0' (the form holds text)
  });

  function submit(e: FormEvent) {
    e.preventDefault();
    post('/auth/register', () => window.location.assign('/'));
  }

  if (!signupOpen) {
    return (
      <AuthShell title="Get your online store" footer={<>Already have a store? <a href="/login" className={authLink}>Sign in</a></>}>
        <p className="text-sm text-slate-700">
          New stores are set up by the Umar Techy team. Contact us with what you sell and your budget, and we will create your store and send you an email to take it over.
        </p>
      </AuthShell>
    );
  }

  const field = (id: 'name' | 'email' | 'store_name', label: string, type: string, autoComplete: string, placeholder?: string) => (
    <div>
      <label htmlFor={id} className="block text-sm font-medium text-slate-700">{label}</label>
      <input id={id} type={type} value={data[id]} onChange={(e) => setData(id, e.target.value)} autoComplete={autoComplete} placeholder={placeholder}
        aria-invalid={errors[id] ? true : undefined} aria-describedby={errors[id] ? `${id}-error` : undefined} className={authInput} />
      <FieldError id={`${id}-error`} message={errors[id]} />
    </div>
  );

  return (
    <AuthShell
      wide
      title="Create your store"
      subtitle="Start with a free trial. No card needed; you choose a package when you are ready."
      footer={<>Already have a store? <a href="/login" className={authLink}>Sign in</a></>}
    >
      {partner && (
        <p className="mb-5 flex items-start gap-2 rounded-lg border border-brand-200 bg-brand-50 px-3.5 py-2.5 text-sm text-brand-900" role="status">
          <Icon name="handshake" size={18} className="mt-0.5" />
          <span>Recommended by <strong>{partner}</strong>, an Umar Techy partner. Your store gets their partner price.</span>
        </p>
      )}

      <form onSubmit={submit} className="space-y-6" noValidate>
        <fieldset className="space-y-4">
          <legend className="sr-only">Your account</legend>
          <Step n={1} title="Your account" />
          {field('name', 'Your name', 'text', 'name')}
          {field('email', 'Email', 'email', 'username', 'you@example.com')}
          <div className="grid gap-4 sm:grid-cols-2">
            <PasswordField id="password" label="Password" value={data.password} onChange={(v) => setData('password', v)} error={errors.password} autoComplete="new-password" strength />
            <PasswordField id="password_confirmation" label="Confirm password" value={data.password_confirmation} onChange={(v) => setData('password_confirmation', v)} error={errors.password_confirmation} autoComplete="new-password" />
          </div>
        </fieldset>

        <fieldset className="space-y-4 border-t border-slate-200 pt-6">
          <legend className="sr-only">Your store</legend>
          <Step n={2} title="Your store" />
          {field('store_name', 'Store name', 'text', 'organization', 'For example: Lahore Fashion House')}
          {businessCategories.length > 0 && (
            <div>
              <label htmlFor="business_category" className="block text-sm font-medium text-slate-700">What will you sell?</label>
              <select id="business_category" value={data.business_category} onChange={(e) => setData('business_category', e.target.value)}
                aria-invalid={errors.business_category ? true : undefined} className={authInput}>
                <option value="">Choose…</option>
                {businessCategories.map((c) => (
                  <option key={c.value} value={c.value}>{c.label}</option>
                ))}
              </select>
              <FieldError message={errors.business_category} />
              {/* Phase B45 (Module 07 §105): start from the category's ready-made structure, or empty. */}
              {data.business_category !== '' && (
                <label className="mt-3 flex items-start gap-2.5 rounded-lg bg-slate-50 p-3 text-sm text-slate-700">
                  <input
                    type="checkbox"
                    checked={data.starter_template === '1'}
                    onChange={(e) => setData('starter_template', e.target.checked ? '1' : '0')}
                    className="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus-visible:ring-2 focus-visible:ring-brand-600"
                  />
                  <span>Set up categories and filters for what I sell (you can change everything later)</span>
                </label>
              )}
            </div>
          )}
        </fieldset>

        {errors.form && <FormAlert>{errors.form}</FormAlert>}

        <button type="submit" disabled={processing} className={authButton}>
          {processing ? <><Spinner />Creating your store…</> : <>Create store<Icon name="arrowRight" size={18} /></>}
        </button>
        <p className="text-center text-xs text-slate-500">You become the owner of the store. You can invite your team later.</p>
      </form>
    </AuthShell>
  );
}
