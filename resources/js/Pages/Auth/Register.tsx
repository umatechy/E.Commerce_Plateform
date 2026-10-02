import { FormEvent } from 'react';
import { useApiForm } from '@/lib/useApiForm';

/**
 * Store-owner self-registration. Submits to AuthController::register,
 * which creates User + Store + owner Role + membership atomically
 * server-side — this form never sends a store_id or role, only the
 * inputs a new owner actually provides.
 */
export default function Register() {
  const { data, setData, post, processing, errors } = useApiForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    store_name: '',
  });

  function submit(e: FormEvent) {
    e.preventDefault();
    post('/auth/register', () => window.location.assign('/'));
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
