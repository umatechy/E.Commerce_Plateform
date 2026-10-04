import { useMemo } from 'react';
import AdminPage from '@/Components/AdminPage';
import SettingsEditor, { type SettingInfo } from '@/Components/SettingsEditor';
import { useAccess } from '@/lib/access';

/**
 * Module 33 store settings (/api/v1/store/settings): the store's
 * currency, timezone and language. The server lists the settings that
 * exist; this page names and explains them.
 *
 * The timezone decides how dates are shown and how days are cut in
 * reports and promotions (Phase B28). Platform billing periods stay in
 * UTC whatever is chosen here.
 */
function timezones(): string[] | undefined {
  try {
    const supported = (Intl as unknown as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf?.('timeZone');

    return supported && supported.length > 0 ? ['UTC', ...supported.filter((zone) => zone !== 'UTC')] : undefined;
  } catch {
    return undefined;
  }
}

export default function Index() {
  const access = useAccess();
  const info = useMemo<Record<string, SettingInfo>>(
    () => ({
      'store.default_currency': {
        label: 'Store currency',
        description: 'The currency amounts are shown in across the admin and that new products and rates start with.',
        choices: access.currencies,
        itemHint: 'One of the currencies the platform offers. Pakistan stores use PKR (Rs.).',
        warning: 'Existing products, orders and rates keep the currency they were saved with. Nothing is converted.',
      },
      'returns.customer_requests_enabled': {
        label: 'Customers can ask for a return themselves',
        description: 'Shows “Request a return” on delivered orders in the customer account. When off, customers contact you and your team records the return.',
      },
      'returns.window_days': {
        label: 'Return period (days)',
        description: 'How many days after delivery a customer may ask for a return. Your team can still record one later.',
        warning: 'Set this to your own return policy. Make sure the policy page of your store says the same.',
      },
      'store_credit.expires': {
        label: 'Store credit expires',
        description: 'When on, store credit given from now on expires after the number of days below. Credit customers already have never expires.',
        warning: 'Say so in your store policy. Customers see the date their credit expires in their account.',
      },
      'store_credit.expiry_days': {
        label: 'Store credit expires after (days)',
        description: 'Used only when “Store credit expires” is on. Counted from the day the credit is given.',
      },
      'store.timezone': {
        label: 'Store timezone',
        description: 'Dates in the admin and on your storefront are shown in this timezone, and "today" in reports and promotions means the day here.',
        choices: timezones(),
        itemHint: 'A timezone name such as Asia/Karachi.',
        warning: 'Reports group sales by the days of the new timezone from now on, and promotion and campaign times you enter are read in it. Your invoices from the platform stay in UTC.',
      },
      'store.default_locale': {
        label: 'Default storefront language',
        description: 'The language your storefront opens in. Shoppers can switch to the other languages you offer.',
        choices: ['en', 'ur'],
        itemHint: 'en = English, ur = Urdu (اردو, right to left).',
        warning: 'It must be one of the storefront languages below. Product names you have not translated are shown in the original.',
      },
      // Phase B38 (Module 05 §41).
      'store.languages': {
        label: 'Storefront languages',
        description: 'The languages shoppers can choose on your storefront. With more than one, a language link appears in the header, and each language has its own address for search engines.',
        itemHint: 'One code per line: en (English), ur (Urdu). The default language must stay in the list.',
        warning: 'Translate your product, category and brand names and your home page texts to show them in each language; the storefront’s own words are already translated.',
      },
    }),
    [access.currencies],
  );

  return (
    <AdminPage title="Settings" description="How your store counts money and time.">
      <SettingsEditor
        listPath="/store/settings"
        updatePath="/store/settings"
        info={info}
        canManage={access.can('settings.manage')}
        historyPath={(key) => `/store/settings/${key}/history`}
        rollbackPath={(id) => `/store/settings/revisions/${id}/rollback`}
      />
    </AdminPage>
  );
}
