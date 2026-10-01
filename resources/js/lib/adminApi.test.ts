import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AdminApiError, adminErrorMessage, adminFetch, fieldErrors, messageForStatus, registerStepUpHandler, wasCancelled } from './adminApi';

function respond(status: number, body: unknown = {}, headers: Record<string, string> = {}): Response {
  return new Response(status === 204 ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } });
}

const fetchMock = vi.fn();

beforeEach(() => {
  vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
  fetchMock.mockReset();
  registerStepUpHandler(null);
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

async function failure(promise: Promise<unknown>): Promise<AdminApiError> {
  try {
    await promise;
  } catch (e) {
    return e as AdminApiError;
  }
  throw new Error('The call did not fail.');
}

describe('adminFetch', () => {
  it('sends the query without empty values and returns the body', async () => {
    fetchMock.mockResolvedValue(respond(200, { data: [1] }));

    await expect(adminFetch('/products', { query: { search: 'shirt', status: '', page: 2, none: undefined } })).resolves.toEqual({ data: [1] });
    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/products?search=shirt&page=2');
  });

  it('ends a request that never answers instead of waiting for ever', async () => {
    vi.useFakeTimers();
    fetchMock.mockImplementation((_url: string, init: RequestInit) => new Promise((_resolve, reject) => init.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')))));

    const pending = failure(adminFetch('/slow', { timeoutMs: 1000 }));
    await vi.advanceTimersByTimeAsync(1000);
    const error = await pending;

    expect(error.status).toBe(0);
    expect(error.code).toBe('timeout');
    expect(error.message).toMatch(/took too long/);
  });

  it('says so when the server cannot be reached', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));

    const error = await failure(adminFetch('/products'));
    expect(error.code).toBe('network');
    expect(error.message).toMatch(/Could not reach the server/);
  });

  it('never shows the text of a server fault', async () => {
    fetchMock.mockResolvedValue(respond(500, { message: 'SQLSTATE[42S02]: Base table or view not found: users' }));

    const error = await failure(adminFetch('/products'));
    expect(error.message).toBe('Something went wrong on our side. Please try again.');
    expect(adminErrorMessage(error)).not.toMatch(/SQLSTATE/);
  });

  it('explains a refusal, a missing item and a rate limit in words', async () => {
    expect(messageForStatus(403, { message: 'This action is unauthorized.' })).toBe('You do not have permission to do this.');
    expect(messageForStatus(403, { message: "You've reached your plan's product limit (500)." })).toMatch(/product limit/);
    expect(messageForStatus(404, {})).toMatch(/not found/);
    expect(messageForStatus(429, {}, '30')).toBe('Too many requests. Try again in 30 seconds.');
    expect(messageForStatus(429, {}, null)).toMatch(/Wait a moment/);
    expect(messageForStatus(419, {})).toMatch(/session expired/);
    expect(messageForStatus(409, { message: 'The store is already live.' })).toBe('The store is already live.');
  });

  it('gives the validation messages of a 422 by field', async () => {
    fetchMock.mockResolvedValue(respond(422, { message: 'The name field is required.', errors: { name: ['The name field is required.'], price_minor: ['Must be at least 0.', 'second'] } }));

    const error = await failure(adminFetch('/products', { method: 'POST', body: {} }));
    expect(fieldErrors(error)).toEqual({ name: 'The name field is required.', price_minor: 'Must be at least 0.' });
    expect(adminErrorMessage(error)).toBe('The name field is required.');
    expect(fieldErrors(new Error('other'))).toEqual({});
  });

  it('sends a signed-out user to the sign-in page', async () => {
    const assign = vi.fn();
    vi.stubGlobal('location', { ...window.location, assign });
    fetchMock.mockResolvedValue(respond(401, { message: 'Unauthenticated.' }));

    const error = await failure(adminFetch('/orders'));
    expect(error.status).toBe(401);
    expect(assign).toHaveBeenCalledWith('/login');
  });
});

describe('step-up', () => {
  it('asks for the password and then repeats the same request', async () => {
    const handler = vi.fn().mockResolvedValue(true);
    registerStepUpHandler(handler);
    fetchMock.mockResolvedValueOnce(respond(403, { code: 'step_up_required', message: 'Confirm your password to continue.' })).mockResolvedValueOnce(respond(200, { data: { ok: true } }));

    await expect(adminFetch('/super-admin/settings/x', { method: 'PUT', body: { value: 1 } })).resolves.toEqual({ data: { ok: true } });
    expect(handler).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock.mock.calls[1][0]).toBe(fetchMock.mock.calls[0][0]);
    expect(fetchMock.mock.calls[1][1].body).toBe(fetchMock.mock.calls[0][1].body);
  });

  it('changes nothing when the user cancels', async () => {
    registerStepUpHandler(vi.fn().mockResolvedValue(false));
    fetchMock.mockResolvedValue(respond(403, { code: 'step_up_required' }));

    const error = await failure(adminFetch('/backups/x/restore-request', { method: 'POST' }));
    expect(wasCancelled(error)).toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(1); // the action was not repeated
  });

  it('asks only once: a step-up that still does not satisfy the server is reported', async () => {
    const handler = vi.fn().mockResolvedValue(true);
    registerStepUpHandler(handler);
    fetchMock.mockResolvedValue(respond(403, { code: 'step_up_required', message: 'Confirm your password to continue.' }));

    const error = await failure(adminFetch('/x', { method: 'POST' }));
    expect(handler).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(error.status).toBe(403);
    expect(wasCancelled(error)).toBe(false);
  });

  it('does not treat an ordinary refusal as a step-up', async () => {
    const handler = vi.fn();
    registerStepUpHandler(handler);
    fetchMock.mockResolvedValue(respond(403, { message: 'This action is unauthorized.' }));

    await failure(adminFetch('/roles', { method: 'POST', body: {} }));
    expect(handler).not.toHaveBeenCalled();
  });
});
