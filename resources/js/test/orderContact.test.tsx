import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { WhatsAppDialog } from '@/Components/Orders/ContactCustomer';
import { orderMessage, orderPhone, whatsappLink, whatsappNumber } from '@/lib/contact';
import type { Order } from '@/lib/orders';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertiaMock')).inertiaMock);

/** UI-15: reaching an order's customer from the store's own WhatsApp. */
afterEach(() => {
  cleanup();
  window.localStorage.clear();
});

const order = {
  id: 'o1', order_number: 'ORD-000026', status: 'confirmed', payment_status: 'unpaid', currency: 'PKR', grand_total_minor: 491400,
  is_guest_order: true, guest_name: 'Ayesha Khan', guest_phone: '0300 1112233', customer: null, shipping_address: null, billing_address: null,
  items: [{ id: 'i1', quantity: 2, product_name: 'Pure Wool Shawl' }],
} as unknown as Order;

describe('phone numbers for WhatsApp', () => {
  it('completes Pakistani local numbers and keeps international ones', () => {
    expect(whatsappNumber('0300 1112233')).toBe('923001112233');
    expect(whatsappNumber('300-1112233')).toBe('923001112233');
    expect(whatsappNumber('+92 300 1112233')).toBe('923001112233');
    expect(whatsappNumber('0092 300 1112233')).toBe('923001112233');
    expect(whatsappNumber('12345')).toBeNull();
    expect(whatsappNumber(null)).toBeNull();
  });

  it('takes the phone from the order and builds a wa.me link with the text', () => {
    expect(orderPhone(order)).toBe('0300 1112233');
    expect(orderPhone({ ...order, guest_phone: null, shipping_address: { phone: '03211234567' } })).toBe('03211234567');
    expect(whatsappLink('0300 1112233', 'Hi there')).toBe('https://wa.me/923001112233?text=Hi%20there');
  });

  it('suggests a cash-on-delivery confirmation with the items and the amount', () => {
    const text = orderMessage('confirm', 'roman_ur', order, 'Awan Garments', 'Ayesha Khan (guest)');
    expect(text).toContain('Assalam o Alaikum Ayesha Khan!');
    expect(text).toContain('ORD-000026');
    expect(text).toContain('2 × Pure Wool Shawl');
    expect(text).toContain('Delivery par Rs. 4,914 ada karne honge');
    expect(orderMessage('confirm', 'en', { ...order, payment_status: 'paid' }, 'Awan Garments', 'Ayesha Khan')).toContain('Your payment of Rs. 4,914 is received.');
  });
});

describe('the WhatsApp dialog', () => {
  it('opens WhatsApp with the text as edited, in the chosen language', () => {
    render(<WhatsAppDialog order={order} storeName="Awan Garments" onClose={vi.fn()} />);
    const link = () => screen.getByRole('link', { name: /Open WhatsApp/ }).getAttribute('href') ?? '';
    expect(decodeURIComponent(link())).toContain('Assalam o Alaikum');

    fireEvent.change(screen.getByLabelText('Message language'), { target: { value: 'en' } });
    expect(decodeURIComponent(link())).toContain('Hello Ayesha Khan');

    fireEvent.change(screen.getByLabelText('Text'), { target: { value: 'Custom note' } });
    expect(link()).toBe('https://wa.me/923001112233?text=Custom%20note');
  });
});
