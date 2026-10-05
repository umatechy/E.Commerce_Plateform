import { FormEvent } from 'react';
import { useApiForm } from '@/lib/useApiForm';

/**
 * Store-owner self-registration. Submits to AuthController::register,
 * which creates the account and, through StoreProvisioningService, the
 * store, its owner membership and its trial atomically server-side — this
 * form never sends a store_id or role, only what a new owner provides.
 *
 * Phase B44 (owner decision 13): what the store sells is chosen here; when
 * Umar Techy has closed public sign-up, the page says whom to contact.
 */
type Props = { signupOpen?: boolean; businessCategories?: { value: string; label: string }[] };

export default function Register({ signupOpen = true, businessCategories = [] }: Props) {
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
      <div className="mx-auto mt-24 max-w-sm">
        <h1 className="text-xl font-semibold">Get your online store</h1>
        <p className="mt-4 text-sm text-gray-700">
          New stores are set up by the Umar Techy team. Contact us with what you sell and your budget, and we will create your store and send you an email to take it over.
        </p>
        <a href="/login" className="mt-6 inline-block text-sm font-medium text-indigo-700 underline">Already have a store? Sign in</a>
      </div>
    );
  }

  return (
    <div className="mx-auto mt-24 max-w-sm">
      <h1 className="text-xl font-semibold">Create your store</h1>

      <form onSubmit={submit} className="mt-6 space-y-4">
        {(
          [
            ['name', 'Your name', 'text', 'name'],
            ['email', 'Email', 'email', 'username'],
            ['password', 'Password', 'password', 'new-password'],
            ['password_confirmation', 'Confirm password', 'password', 'new-password'],
            ['store_name', 'Store name', 'text', 'organization'],
          ] as const
        ).map(([field, label, type, autoComplete]) => (
          <div key={field}>
            <label htmlFor={field} className="block text-sm font-medium text-gray-700">
              {label}
            </label>
            <input
              id={field}
              type={type}
              value={data[field]}
              onChange={(e) => setData(field, e.target.value)}
              className="mt-1 block w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600"
              autoComplete={autoComplete}
            />
            {errors[field] && <p className="mt-1 text-sm text-red-600">{errors[field]}</p>}
          </div>
        ))}

        {businessCategories.length > 0 && (
          <div>
            <label htmlFor="business_category" className="block text-sm font-medium text-gray-700">
              What will you sell?
            </label>
            <select
              id="business_category"
              value={data.business_category}
              onChange={(e) => setData('business_category', e.target.value)}
              className="mt-1 block w-full rounded border border-gray-300 px-3 py-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600"
            >
              <option value="">Choose…</option>
              {businessCategories.map((c) => (
                <option key={c.value} value={c.value}>{c.label}</option>
              ))}
            </select>
            {errors.business_category && <p className="mt-1 text-sm text-red-600">{errors.business_category}</p>}
            {/* Phase B45 (Module 07 §105): start from the category's ready-made structure, or empty. */}
            {data.business_category !== '' && (
              <label className="mt-2 flex items-start gap-2 text-sm text-gray-700">
                <input
                  type="checkbox"
                  checked={data.starter_template === '1'}
                  onChange={(e) => setData('starter_template', e.target.checked ? '1' : '0')}
                  className="mt-0.5 h-4 w-4 rounded border-gray-300 focus-visible:ring-2 focus-visible:ring-indigo-600"
                />
                <span>Set up categories and filters for what I sell (you can change everything later)</span>
              </label>
            )}
          </div>
        )}

        {errors.form && <p className="text-sm text-red-600">{errors.form}</p>}

        <button
          type="submit"
          disabled={processing}
          className="w-full rounded bg-gray-900 py-2 text-white disabled:opacity-50"
        >
          {processing ? 'Creating your store…' : 'Create store'}
        </button>
      </form>
    </div>
  );
}
