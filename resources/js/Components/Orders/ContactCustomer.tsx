import { useEffect, useState } from 'react';
import Button, { buttonClass } from '@/Components/ui/Button';
import Dialog from '@/Components/ui/Dialog';
import { SelectField, TextAreaField } from '@/Components/ui/Form';
import Icon from '@/Components/ui/Icon';
import { MESSAGE_KINDS, MESSAGE_LANGUAGES, orderMessage, orderPhone, whatsappLink, type MessageKind, type MessageLanguage } from '@/lib/contact';
import { orderCustomer, type Order } from '@/lib/orders';
import { useAdminT } from '@/lib/adminI18n';

/**
 * UI-15 — write to the order's customer on WhatsApp from the store's own
 * phone. The dialog suggests the text (confirm the order, it is on its way,
 * or a free message) in English or Roman Urdu; the seller edits it and
 * "Open WhatsApp" opens wa.me with it. Nothing is sent from the platform.
 */
const LANGUAGE_KEY = 'ut.whatsapp-language';

function storedLanguage(): MessageLanguage {
  try {
    return window.localStorage.getItem(LANGUAGE_KEY) === 'en' ? 'en' : 'roman_ur';
  } catch {
    return 'roman_ur';
  }
}

export function WhatsAppDialog({ order, storeName, initialKind = 'confirm', onClose }: { order: Order; storeName: string; initialKind?: MessageKind; onClose: () => void }) {
  const t = useAdminT();
  const [kind, setKind] = useState<MessageKind>(initialKind);
  const [language, setLanguage] = useState<MessageLanguage>('roman_ur');
  const [text, setText] = useState('');
  useEffect(() => setLanguage(storedLanguage()), []);
  useEffect(() => setText(orderMessage(kind, language, order, storeName, orderCustomer(order))), [kind, language, order, storeName]);

  const phone = orderPhone(order);
  const link = whatsappLink(phone, text);

  return (
    <Dialog open title={t('Message the customer on WhatsApp')} description={phone ? t('To {name} · {phone}', { name: orderCustomer(order).replace(/\s*\(guest\)$/, ''), phone }) : undefined} onClose={onClose} guard={false}>
      <div className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField label={t('Message')} value={kind} onChange={(v) => setKind(v as MessageKind)} options={MESSAGE_KINDS.map((k) => ({ value: k.id, label: t(k.label) }))} />
          <SelectField
            label={t('Message language')}
            value={language}
            onChange={(v) => {
              setLanguage(v as MessageLanguage);
              try {
                window.localStorage.setItem(LANGUAGE_KEY, v);
              } catch {
                // Remembered for this visit only.
              }
            }}
            options={MESSAGE_LANGUAGES.map((l) => ({ value: l.id, label: l.label }))}
          />
        </div>
        <TextAreaField label={t('Text')} rows={6} value={text} onChange={setText} hint={t('You can change it here, and again in WhatsApp before you send it.')} />
        <div className="flex flex-wrap justify-end gap-2">
          <Button onClick={onClose}>{t('Close')}</Button>
          {link && (
            <a href={link} target="_blank" rel="noreferrer" onClick={onClose} className={buttonClass('primary', 'md', '!bg-[#128C4A] hover:!bg-[#0e7a40]')}>
              <Icon name="chat" size={16} />{t('Open WhatsApp')}<span className="sr-only"> {t('(opens in a new tab)')}</span>
            </a>
          )}
        </div>
      </div>
    </Dialog>
  );
}

/** WhatsApp and call buttons for an order's customer; nothing when the order has no usable phone. */
export function ContactButtons({ order, storeName, size = 'md' }: { order: Order; storeName: string; size?: 'sm' | 'md' }) {
  const t = useAdminT();
  const [open, setOpen] = useState(false);
  const phone = orderPhone(order);
  if (!phone) return null;

  return (
    <>
      <Button size={size} onClick={() => setOpen(true)} className="!border-emerald-200 !text-emerald-800 hover:!bg-emerald-50">
        <Icon name="chat" size={16} />{t('WhatsApp')}
      </Button>
      <a href={`tel:${phone.replace(/[^\d+]/g, '')}`} className={buttonClass('secondary', size)}>
        <Icon name="call" size={16} />{t('Call')}<span className="sr-only"> {phone}</span>
      </a>
      {open && <WhatsAppDialog order={order} storeName={storeName} onClose={() => setOpen(false)} />}
    </>
  );
}
