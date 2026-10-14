import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import ApiReference from '@/Components/Developer/ApiReference';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B53 (SRS API-012): the store's Developer page reads the API's own OpenAPI description. */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const spec = {
  info: { version: 'v1' },
  paths: {
    '/products': { get: { summary: 'List products', 'x-scope': 'products:read' } },
    '/orders/{order}': { get: { summary: 'Get an order', 'x-scope': 'orders:read' } },
  },
  webhooks: { 'order.created': { post: { description: 'A new order was placed.\n\nSent as POST…' } } },
};

describe('API reference', () => {
  it('lists the endpoints with their scope and the webhook events, with a link to the OpenAPI file', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(spec), { status: 200, headers: { 'Content-Type': 'application/json' } })));
    render(<ApiReference />);

    expect(await screen.findByText('/api/dev/v1/orders/{order}')).toBeTruthy();
    expect(screen.getByText('products:read')).toBeTruthy();
    expect(screen.getByText('Webhook events (1)')).toBeTruthy();
    expect(screen.getByText('— A new order was placed.')).toBeTruthy();
    expect(screen.getByRole('link', { name: /OpenAPI file/ }).getAttribute('href')).toBe('/api/dev/v1/openapi.json');
  });
});
