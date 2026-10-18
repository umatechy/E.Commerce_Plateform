import { afterEach, describe, expect, it } from 'vitest';
import { act, cleanup, renderHook } from '@testing-library/react';
import { useForm } from '@/lib/useForm';
import { AdminApiError } from '@/lib/adminApi';

/** Owner testing 2026-10-18: "Check the highlighted fields" with nothing highlighted. */
afterEach(cleanup);

function rejected(errors: Record<string, string[]>) {
  return () => Promise.reject(new AdminApiError('The given data was invalid.', 422, { errors }));
}

describe('a server message for a field the form has no input for', () => {
  it('is shown at the top instead of pointing at highlighted fields', async () => {
    const { result } = renderHook(() => useForm({ guest_name: '', guest_email: '' }));
    await act(() => result.current.submit(rejected({ items: ['Keyboard is out of stock.'] })));

    expect(result.current.formError).toBe('Keyboard is out of stock.');
  });

  it('a message for an input on the form still says to check the highlighted fields', async () => {
    const { result } = renderHook(() => useForm({ guest_name: '', guest_email: '' }));
    await act(() => result.current.submit(rejected({ guest_email: ['Enter a valid email.'] })));

    expect(result.current.formError).toBe('Check the highlighted fields.');
    expect(result.current.errors.guest_email).toBe('Enter a valid email.');
  });
});
