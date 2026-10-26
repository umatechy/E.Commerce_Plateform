import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import Automations, { type Automation } from '@/Pages/Marketing/Automations';
import { clearToasts } from '@/Components/ui/toast';
import { json, owner, routeFetch, setPage } from '@/test/inertiaMock';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** Phase P4b: marketing automations (Business and Premium). */
afterEach(() => {
  cleanup();
  clearToasts();
  vi.unstubAllGlobals();
});

const automation = (kind: Automation['kind'], more: Partial<Automation> = {}): Automation => ({
  kind, is_enabled: false, delay_days: kind === 'win_back' ? 60 : 0, subject: `Subject ${kind}`, body: 'Hi {{customer.name}}', coupon_id: null, enabled_at: null, sent_30d: 0,
  limits: { min: kind === 'win_back' ? 14 : 0, max: 365 }, ...more,
});

describe('automations', () => {
  it('explains the package to a store without it', () => {
    setPage({ ...owner, features: {}, package: { code: 'basic', name: 'Basic' } }, '/automations');
    vi.stubGlobal('fetch', routeFetch({ '/attention': () => json(200, { data: [] }) }));
    render(<Automations />);
    expect(screen.getByText('Automations are part of the Business and Premium packages')).toBeTruthy();
  });

  it('turns a message on with its delay and coupon, and says how it will go out', async () => {
    setPage({ ...owner, features: { 'marketing.advanced': true } }, '/automations');
    let sent: Record<string, unknown> | null = null;
    vi.stubGlobal('fetch', routeFetch({
      '/marketing/automations': () => json(200, { data: { included: true, can_manage: true, coupons: [{ id: 7, code: 'COMEBACK10' }], automations: [automation('welcome', { is_enabled: true, sent_30d: 12 }), automation('post_purchase', { delay_days: 3 }), automation('win_back'), automation('birthday')] } }),
      'PUT /marketing/automations/win_back': (_url, init) => {
        sent = JSON.parse(init.body as string);
        return json(200, { message: 'Saved.' });
      },
      '/attention': () => json(200, { data: [] }),
    }));
    render(<Automations />);

    expect(await screen.findByText('Right away · 12 sent in 30 days')).toBeTruthy();
    const card = screen.getByText('We miss you').closest('section') as HTMLElement;
    const save = within(card).getByRole('button', { name: 'Save' }) as HTMLButtonElement;
    expect(save.disabled).toBe(true); // nothing changed yet
    fireEvent.click(within(card).getByRole('switch'));
    fireEvent.change(within(card).getByLabelText('Days since the last order'), { target: { value: '90' } });
    fireEvent.change(within(card).getByLabelText(/Add a coupon code/), { target: { value: '7' } });
    expect(within(card).getByText('90 days after the last order · 0 sent in 30 days')).toBeTruthy();
    fireEvent.click(save);
    await waitFor(() => expect(sent).toMatchObject({ is_enabled: true, delay_days: 90, coupon_id: 7 }));
  });
});
