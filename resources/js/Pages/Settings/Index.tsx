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
      // Phase B44 (Module 03 §14, §24): business information.
      'store.legal_name': { label: 'Business name (legal)', description: 'Your registered business name, for invoices and your policies.' },
      'store.contact_email': { label: 'Contact email', description: 'Where customers and Umar Techy can reach your store. Needed before you launch.' },
      'store.contact_phone': { label: 'Contact phone', description: 'For example +92 300 1234567.' },
      'store.country': { label: 'Country', description: 'Where your business is, as a two-letter code (PK for Pakistan).' },
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
      // Phase B43 (Module 05 §19, Module 06 §36–37).
      'catalog.default_sort': {
        label: 'Default product order',
        description: 'The order product lists open in. A category can choose its own; shoppers can always pick another.',
        choices: ['featured', 'newest', 'best_selling', 'price_asc', 'price_desc', 'name'],
        itemHint: 'featured = featured products first, then higher sort priority, then newest. best_selling = most units sold.',
      },
      'catalog.featured_in_search': {
        label: 'Lift featured products in search',
        description: 'Among products that match a search equally well, featured and higher-priority products come first. A better match always comes before a featured one.',
      },
      // Owner decision 15: reviews and units sold (Business and Premium packages).
      'reviews.enabled': { label: 'Product reviews', description: 'Customers who bought a product can rate and review it; your store shows the rating and the reviews you publish. Business and Premium.' },
      'reviews.auto_approve': { label: 'Publish reviews without checking', description: 'Off: you publish each review on the Reviews page first. On: reviews show at once; you can still reject or delete them.' },
      'storefront.show_units_sold': { label: 'Show how many were sold', description: 'Shows “120 sold” on product pages, counted from your orders (cancelled ones excluded). Business and Premium.' },
      'storefront.units_sold_minimum': { label: 'Show the number sold from', description: 'Products that sold fewer units than this do not show the number.' },
      'badges.new': { label: 'Badge: New', description: 'On products published within the number of days below.' },
      'badges.new_days': { label: 'New for (days)', description: 'How long a product shows the “New” badge after it is published.' },
      'badges.sale': { label: 'Badge: Sale', description: 'On products with a sale price.' },
      'badges.sale_percent': { label: 'Show the percent off', description: 'Shows “25% off” instead of “Sale” when the product has one price.' },
      'badges.bestseller': { label: 'Badge: Bestseller', description: 'On your top sellers (units sold on orders that count) of the last days below.' },
      'badges.bestseller_count': { label: 'Bestsellers: how many products', description: 'The number of top-selling products that show the badge.' },
      'badges.bestseller_days': { label: 'Bestsellers: over the last (days)', description: 'The period sales are counted over.' },
      'badges.low_stock': { label: 'Badge: Only a few left', description: 'On products whose stock is at or under the number below. Off by default: it shows shoppers that stock is low.' },
      'badges.low_stock_threshold': { label: 'Only a few left: at or under (units)', description: 'Counted in your default warehouse. Products whose stock you do not track never show it.' },
      'badges.featured': { label: 'Badge: Featured', description: 'On products you mark as featured.' },
      'badges.out_of_stock': { label: 'Badge: Sold out', description: 'On products that cannot be bought now.' },
      'badges.max_per_product': { label: 'Badges per product (most)', description: 'How many badges a card or product page shows at most; the highest priority ones are kept.' },
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
