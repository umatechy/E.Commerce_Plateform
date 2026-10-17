import type { Order } from './orders';
import { money } from './money';

/**
 * UI-15 — reaching an order's customer from the admin with the store's own
 * phone: WhatsApp click-to-chat (wa.me, no provider account or API key
 * involved; the message opens in the seller's WhatsApp to check and send)
 * and a call link.
 */

/** The phone the customer gave on the order (guest checkout or the shipping address). */
export function orderPhone(order: Pick<Order, 'guest_phone' | 'shipping_address' | 'billing_address'>): string | null {
  const fromAddress = (address: Record<string, unknown> | null) => (address && typeof address.phone === 'string' ? address.phone : null);
  const phone = order.guest_phone ?? fromAddress(order.shipping_address) ?? fromAddress(order.billing_address);

  return phone && phone.replace(/\D/g, '').length >= 10 ? phone : null;
}

/**
 * The number in the international form wa.me expects (digits only, country
 * code first). Pakistan local forms are completed: 0300 1234567 and
 * 300 1234567 become 923001234567; +92 and 0092 are kept. Null when it
 * cannot be a phone number.
 */
export function whatsappNumber(phone: string | null | undefined): string | null {
  if (!phone) return null;
  let digits = phone.replace(/\D/g, '');
  if (digits.startsWith('00')) digits = digits.slice(2);
  if (digits.length === 11 && digits.startsWith('0')) digits = `92${digits.slice(1)}`;
  else if (digits.length === 10 && digits.startsWith('3')) digits = `92${digits}`;

  return digits.length >= 10 && digits.length <= 15 ? digits : null;
}

export function whatsappLink(phone: string | null | undefined, text: string): string | null {
  const number = whatsappNumber(phone);

  return number ? `https://wa.me/${number}?text=${encodeURIComponent(text)}` : null;
}

export type MessageKind = 'confirm' | 'shipped' | 'general';
export type MessageLanguage = 'en' | 'roman_ur';

export const MESSAGE_KINDS: { id: MessageKind; label: string }[] = [
  { id: 'confirm', label: 'Confirm the order' },
  { id: 'shipped', label: 'Order is on its way' },
  { id: 'general', label: 'A message about the order' },
];

export const MESSAGE_LANGUAGES: { id: MessageLanguage; label: string }[] = [
  { id: 'en', label: 'English' },
  { id: 'roman_ur', label: 'Roman Urdu' },
];

/** The suggested text; the seller can change it before it opens in WhatsApp. */
export function orderMessage(kind: MessageKind, language: MessageLanguage, order: Order, storeName: string, customerName: string): string {
  const total = money(order.payable_minor ?? order.grand_total_minor, order.currency);
  const paid = order.payment_status === 'paid';
  const items = (order.items ?? []).map((item) => `${item.quantity} × ${item.product_name}`).join(', ');
  const name = customerName.replace(/\s*\(guest\)$/, '');

  if (language === 'roman_ur') {
    if (kind === 'confirm') {
      return [
        `Assalam o Alaikum ${name}!`,
        `${storeName} se aap ka order ${order.order_number} mil gaya hai${items ? `: ${items}` : ''}.`,
        paid ? `Raqam ${total} ada ho chuki hai.` : `Delivery par ${total} ada karne honge (cash on delivery).`,
        'Order confirm karne ke liye barah-e-karam "YES" reply karein. Shukriya!',
      ].join('\n');
    }
    if (kind === 'shipped') return `Assalam o Alaikum ${name}! ${storeName} ka aap ka order ${order.order_number} rawana ho gaya hai.${paid ? '' : ` Delivery par ${total} tayyar rakhein.`} Shukriya!`;

    return `Assalam o Alaikum ${name}! ${storeName} se aap ke order ${order.order_number} ke baare mein: `;
  }

  if (kind === 'confirm') {
    return [
      `Hello ${name},`,
      `thank you for your order ${order.order_number} at ${storeName}${items ? `: ${items}` : ''}.`,
      paid ? `Your payment of ${total} is received.` : `Please keep ${total} ready to pay on delivery (cash on delivery).`,
      'Please reply "YES" to confirm your order. Thank you!',
    ].join('\n');
  }
  if (kind === 'shipped') return `Hello ${name}, your order ${order.order_number} from ${storeName} is on its way.${paid ? '' : ` Please keep ${total} ready on delivery.`} Thank you!`;

  return `Hello ${name}, about your order ${order.order_number} at ${storeName}: `;
}
