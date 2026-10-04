import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import MessageThread from './MessageThread';
import RequesterTicket from './RequesterTicket';
import type { TicketDetail, TicketMessage } from '@/lib/support';

// Phase B38: the ticket components read the page language through Inertia.
vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

afterEach(cleanup);

const message = (overrides: Partial<TicketMessage> = {}): TicketMessage => ({
  id: 'm1',
  author_type: 'requester',
  author_name: 'Amna',
  body: 'Hello',
  created_at: '2026-09-30T10:00:00Z',
  ...overrides,
});

const ticket = (overrides: Partial<TicketDetail> = {}): TicketDetail => ({
  id: '01JSUPPORTTICKET0000000001',
  number: 'S-000001',
  subject: 'Late parcel',
  category: 'shipping',
  status: 'awaiting_customer',
  created_at: '2026-09-30T10:00:00Z',
  updated_at: '2026-09-30T10:00:00Z',
  order: null,
  messages: [message()],
  can_reply: true,
  can_resolve: true,
  can_rate: false,
  satisfaction: null,
  ...overrides,
});

describe('MessageThread', () => {
  it('renders message text as text, never as markup', () => {
    const { container } = render(<MessageThread messages={[message({ body: '<img src=x onerror=alert(1)>' })]} perspective="agent" tone="admin" />);

    expect(container.querySelector('img')).toBeNull();
    expect(screen.getByText('<img src=x onerror=alert(1)>')).toBeTruthy();
  });

  it('marks internal notes for the team', () => {
    render(<MessageThread messages={[message({ author_type: 'agent', author_name: 'Sara Khan', internal: true, body: 'Refund approved' })]} perspective="agent" tone="admin" />);

    expect(screen.getByText('Internal note')).toBeTruthy();
  });
});

describe('RequesterTicket', () => {
  const noop = () => Promise.resolve(ticket());

  it('offers reply and resolve on an active request, and no rating yet', () => {
    render(<RequesterTicket ticket={ticket()} tone="storefront" onChange={() => {}} reply={noop} resolve={noop} rate={noop} describeError={() => 'x'} />);

    expect(screen.getByText('Waiting for your reply')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Send reply' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'My issue is solved' })).toBeTruthy();
    expect(screen.queryByRole('radiogroup')).toBeNull();
  });

  it('sends a rating once the request is resolved', async () => {
    const rated = ticket({ status: 'resolved', can_resolve: false, can_rate: false, satisfaction: { rating: 4, comment: null } });
    const rate = vi.fn().mockResolvedValue(rated);
    const onChange = vi.fn();
    render(
      <RequesterTicket ticket={ticket({ status: 'resolved', can_resolve: false, can_rate: true })} tone="storefront" onChange={onChange} reply={noop} resolve={noop} rate={rate} describeError={() => 'x'} />,
    );

    fireEvent.click(screen.getByRole('radio', { name: '4 out of 5' }));
    fireEvent.click(screen.getByRole('button', { name: 'Send rating' }));

    await waitFor(() => expect(onChange).toHaveBeenCalledWith(rated));
    expect(rate).toHaveBeenCalledWith(4, '');
  });

  it('shows the server refusal and a way to start again on a closed request', async () => {
    const reply = vi.fn().mockRejectedValue(new Error('closed'));
    const { rerender } = render(
      <RequesterTicket ticket={ticket()} tone="admin" onChange={() => {}} reply={reply} resolve={noop} rate={noop} describeError={() => 'This request is closed. Please open a new one.'} />,
    );

    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Any news?' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send reply' }));
    expect(await screen.findByRole('alert')).toBeTruthy();

    rerender(<RequesterTicket ticket={ticket({ status: 'closed', can_reply: false, can_resolve: false })} tone="admin" onChange={() => {}} reply={reply} resolve={noop} rate={noop} describeError={() => 'x'} newRequestHref="/contact" />);
    expect(screen.getByRole('link', { name: 'Open a new request' }).getAttribute('href')).toBe('/contact');
  });
});
