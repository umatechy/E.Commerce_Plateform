import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import Returns from '@/Pages/Storefront/Returns';
import OrderReturns, { guestReturnsApi } from '@/Components/Storefront/OrderReturns';
import ReturnPhotos from '@/Components/Orders/ReturnPhotos';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';
import type { ReturnRecord } from '@/lib/returns';
import type { Seo, Shell } from '@/Storefront/types';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);
// The storefront shell (header, cart, search) is not what these tests are about.
vi.mock('@/Components/Storefront/StoreLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

const TOKEN = 'k'.repeat(64);
const RETURN = '01JRETURN00000000000000001';
const shell = { base_path: '/shop/acme', store: { slug: 'acme', currency: 'PKR' } } as unknown as Shell;
const seo = {} as Seo;
const line = { order_item_id: 7, name: 'Rose Attar', sku: null, variant: null, ordered: 2, delivered: 2, in_returns: 0, returnable: 2, delivered_at: '2026-10-01T10:00:00Z', window_open: true, unit_price_minor: 100000 };
const none = { review: false, approve: false, reject: false, cancel: false, mark_in_transit: false, receive: false, inspect: false, approve_refund: false, replace: false, refund: false };

const record = (overrides: Partial<ReturnRecord> = {}): ReturnRecord => ({
  id: RETURN, return_number: 'R-ORD-1-1', status: 'requested', resolution: 'refund', reason: 'defective', description: null, requested_by: 'guest',
  decision_note: null, return_method: null, return_shipping_paid_by: null, return_carrier: null, return_tracking_number: null, currency: 'PKR',
  items_refund_minor: 0, shipping_refund_minor: 0, restocking_fee_minor: 0, refund_total_minor: 0, refunded_minor: 0,
  items: [{ order_item_id: 7, name: 'Rose Attar', sku: null, variant: null, unit_price_minor: 100000, quantity: 1, resalable_quantity: 0, damaged_quantity: 0, rejected_quantity: 0, refund_minor: 0 }],
  photos: [], can: { ...none, cancel: true, add_photos: true },
  created_at: '2026-10-01T10:00:00Z', decided_at: null, shipped_back_at: null, received_at: null, inspected_at: null, refunded_at: null, completed_at: null, cancelled_at: null,
  ...overrides,
});

beforeEach(() => {
  setPage(owner, '/');
  window.sessionStorage.clear();
  window.history.replaceState(null, '', '/shop/acme/returns');
  URL.createObjectURL = vi.fn(() => 'blob:photo');
  URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

describe('returns for a guest', () => {
  it('asks for the link with the order number and email, and says the same whatever the answer', async () => {
    let sent: unknown = null;
    vi.stubGlobal('fetch', routeFetch({
      'POST /storefront/returns/lookup': (_url, init) => {
        sent = JSON.parse(init.body as string);

        return json(202, { data: { sent: true } });
      },
    }));
    render(<Returns storefront={shell} seo={seo} token="" />);

    fireEvent.change(screen.getByLabelText('Order number'), { target: { value: ' ORD-000001 ' } });
    fireEvent.change(screen.getByLabelText('Email used for the order'), { target: { value: 'sana@example.com' } });
    fireEvent.click(screen.getByRole('button', { name: 'Email me the link' }));

    await waitFor(() => expect(sent).toEqual({ order_number: 'ORD-000001', email: 'sana@example.com', website: '' }));
    expect(await screen.findByText(/If the order number and email belong together/)).toBeTruthy();
    expect((screen.getByRole('link', { name: 'Open your orders' })).getAttribute('href')).toBe('/shop/acme/account/orders');
  });

  it('takes the token out of the address bar and sends it as a header', async () => {
    window.history.replaceState(null, '', `/shop/acme/returns?token=${TOKEN}`);
    const fetchMock = routeFetch({
      '/storefront/returns/guest': () => json(200, { data: { enabled: true, blocked: null, window_days: 7, max_photos: 6, order: { id: 'o', order_number: 'ORD-000001', currency: 'PKR' }, lines: [line], returns: [] } }),
    });
    vi.stubGlobal('fetch', fetchMock);
    render(<Returns storefront={shell} seo={seo} token={TOKEN} />);

    await screen.findByRole('heading', { name: 'Request a return' });
    expect(window.location.search).toBe('');
    const [, init] = fetchMock.mock.calls[0];
    expect((init?.headers as Record<string, string>)['X-Return-Token']).toBe(TOKEN);
    expect(window.sessionStorage.getItem('storefront:acme:return-token')).toBe(TOKEN);
  });

  it('goes back to the form when the link is no longer valid', async () => {
    vi.stubGlobal('fetch', routeFetch({ '/storefront/returns/guest': () => json(404, { message: 'This link is not valid any more. Ask for a new one with your order number and email.', code: 'return_link_invalid' }) }));
    render(<Returns storefront={shell} seo={seo} token={TOKEN} />);

    expect(await screen.findByText(/This link is not valid any more/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Email me the link' })).toBeTruthy();
    expect(window.sessionStorage.getItem('storefront:acme:return-token')).toBeNull();
  });

  it('sends the request, then its photos to the new return', async () => {
    const calls: string[] = [];
    let returns: ReturnRecord[] = [];
    vi.stubGlobal('fetch', routeFetch({
      '/storefront/returns/guest': () => json(200, { data: { enabled: true, blocked: null, window_days: 7, max_photos: 6, lines: [{ ...line, returnable: returns.length ? 1 : 2 }], returns } }),
      'POST /storefront/returns/guest': (_url, init) => {
        calls.push(`create:${(init.headers as Record<string, string>)['X-Return-Token'] === TOKEN}`);
        returns = [record()];

        return json(201, { data: returns[0] });
      },
      [`POST /storefront/returns/guest/${RETURN}/photos`]: (_url, init) => {
        calls.push(`photo:${init.body instanceof FormData && (init.body.get('photo') as File).name}:${(init.headers as Record<string, string>)['Content-Type'] ?? 'multipart'}`);
        returns = [record({ photos: [{ id: '01JPHOTO000000000000000001', width: 400, height: 300, uploaded_by: 'guest' }] })];

        return json(201, { data: returns[0] });
      },
      [`/storefront/returns/guest/${RETURN}/photos/01JPHOTO000000000000000001`]: () => new Response(new Blob(['x'], { type: 'image/jpeg' }), { status: 200 }),
    }));
    render(<OrderReturns shell={shell} api={guestReturnsApi(shell, TOKEN)} />);

    await screen.findByRole('heading', { name: 'Request a return' });
    fireEvent.change(screen.getByLabelText('How many of Rose Attar to return'), { target: { value: '1' } });
    fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'defective' } });
    const file = new File(['bytes'], 'leak.jpg', { type: 'image/jpeg' });
    fireEvent.change(screen.getByLabelText(/Photos \(optional/), { target: { files: [file] } });
    fireEvent.click(screen.getByRole('button', { name: 'Send request' }));

    // The JSON request first, then the file as multipart (no JSON content type), both with the token.
    await waitFor(() => expect(calls).toEqual(['create:true', 'photo:leak.jpg:multipart']));
    expect(await screen.findByAltText('Photo attached to this return')).toBeTruthy();
    expect(screen.getByText('Your request was sent. The store will answer by email.')).toBeTruthy();
  });
});

describe('return photos (staff)', () => {
  it('shows photos from the API, and lets only who manages add or remove', async () => {
    const withPhoto = record({ photos: [{ id: '01JPHOTO000000000000000001', width: 400, height: 300, uploaded_by: 'guest' }] });
    const fetchMock = routeFetch({
      [`/returns/${RETURN}/photos/01JPHOTO000000000000000001`]: () => new Response(new Blob(['x'], { type: 'image/jpeg' }), { status: 200 }),
      [`DELETE /returns/${RETURN}/photos/01JPHOTO000000000000000001`]: () => json(200, { data: record() }),
    });
    vi.stubGlobal('fetch', fetchMock);
    const onChanged = vi.fn();
    const { rerender } = render(<ReturnPhotos record={withPhoto} canManage={false} onChanged={onChanged} />);

    expect(await screen.findByAltText(/Photo added by customer \(guest\)/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Add a photo' })).toBeNull();
    expect(screen.queryByRole('button', { name: /Remove/ })).toBeNull();

    rerender(<ReturnPhotos record={withPhoto} canManage onChanged={onChanged} />);
    expect(screen.getByRole('button', { name: 'Add a photo' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Remove this photo' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Remove photo' }));
    await waitFor(() => expect(onChanged).toHaveBeenCalledWith(expect.objectContaining({ photos: [] })));
  });
});
