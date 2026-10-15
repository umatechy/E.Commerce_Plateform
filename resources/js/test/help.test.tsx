import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import ArticleView from '@/Components/Help/ArticleView';
import { HelpButton } from '@/Components/Help/HelpPanel';
import type { HelpArticle } from '@/lib/help';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase B54 (Module 34 §12, §28): page help from the top bar, and an article with "Was this helpful?". */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const summary = { key: 'handle-orders', locale: 'en', title: 'Handle an order', summary: 'From placed to delivered.', category: 'orders', category_label: 'Orders', type: 'guide', type_label: 'Guide', audience: 'merchant', status: 'published' as const, updated_at: '2026-10-14T10:00:00Z', applies_from: '1.1', current_version: true };

function json(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

describe('Help panel', () => {
  it('opens from the top bar with the articles about the current page and the way to more help', async () => {
    const fetchMock = vi.fn(async () => json({ data: [summary] }));
    vi.stubGlobal('fetch', fetchMock);
    render(<HelpButton path="/orders" unseenRelease="1.1" canAskPlatform />);

    expect(screen.getByText(/New: what changed in version 1.1/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: /Help/ }));

    expect(await screen.findByText('Handle an order')).toBeTruthy();
    expect(String((fetchMock.mock.calls as unknown as [string][])[0][0])).toContain('/help/page?path=%2Forders');
    expect(screen.getByRole('link', { name: 'Browse the help center' }).getAttribute('href')).toBe('/help');
    expect(screen.getByRole('link', { name: /What's new in 1.1/ }).getAttribute('href')).toBe('/help/whats-new');
    expect(screen.getByRole('link', { name: 'Ask Umar Techy support' })).toBeTruthy();
  });
});

describe('Article view', () => {
  const article: HelpArticle = {
    ...summary,
    status: 'deprecated',
    html: '<h2>Steps</h2><p>Open the order.</p>',
    revision: 3,
    owner: 'Orders (Module 09)',
    module: '09',
    tags: [],
    error_codes: [],
    applies_until: null,
    deprecated_at: '2026-10-14T10:00:00Z',
    replaced_by: { ...summary, key: 'ship-orders', title: 'Ship your orders' },
    translation_outdated: false,
    related: [],
  };

  it('shows the text, points an out-of-date article to its replacement, and sends feedback with a reason', async () => {
    const fetchMock = vi.fn(async () => json({ message: 'Thank you.' }, 201));
    vi.stubGlobal('fetch', fetchMock);
    render(<ArticleView article={article} />);

    expect(screen.getByRole('heading', { name: 'Steps' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Ship your orders' }).getAttribute('href')).toBe('/help/articles/ship-orders');

    fireEvent.click(screen.getByRole('button', { name: 'No' }));
    fireEvent.change(screen.getByLabelText('What was wrong?'), { target: { value: 'outdated' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send' }));

    expect(await screen.findByText(/Thank you. Your feedback helps/)).toBeTruthy();
    await waitFor(() => expect(fetchMock).toHaveBeenCalled());
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toContain('/help/articles/handle-orders/feedback');
    expect(JSON.parse(String(init.body))).toMatchObject({ helpful: false, reason: 'outdated' });
  });
});
