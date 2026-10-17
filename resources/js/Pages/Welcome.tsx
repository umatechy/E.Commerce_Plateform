import { useState, type ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import BrandLogo, { useBrand } from '@/Components/Brand';
import Icon, { type IconName } from '@/Components/ui/Icon';
import { FEATURE_LABELS } from '@/lib/labels';
import { money } from '@/lib/money';

/**
 * UI-1 — the public home page of the platform's own host (signed-in users
 * get Pages/Dashboard instead, see routes/web.php): what the platform
 * does, how a store starts, the packages as Umar Techy priced them (from
 * the server; nothing here invents a price, a figure or a feature),
 * partners and answers to first questions.
 */
type Plan = { code: string; name: string; price: { amount_minor: number; first_period_amount_minor?: number | null; currency: string; interval: string } | null; limits: Record<string, number | null>; features: string[] };
type Props = { plans?: Plan[]; trialDays?: number; signupOpen?: boolean };

type Feature = { icon: IconName; title: string; text: string; wide?: boolean; chips?: string[] };

const FEATURES: Feature[] = [
  { icon: 'wallet', title: 'Payments Pakistan uses', text: 'Take money the way your customers want to pay. Every payment goes straight to your own accounts.', wide: true, chips: ['Cash on delivery', 'JazzCash', 'Easypaisa', 'Bank transfer'] },
  { icon: 'truck', title: 'Shipping and COD collection', text: 'Zones, rates and tracked shipments, with the cash your courier collects followed to the rupee.' },
  { icon: 'language', title: 'Urdu and English shop', text: 'Customers shop in Urdu, right to left, or in English. Translate product and category names.' },
  { icon: 'palette', title: 'Themes you can make yours', text: 'Ready themes with your colours, fonts and home page sections. Preview before you publish.' },
  { icon: 'phone', title: 'Your shop as a phone app', text: 'Customers add your shop to their home screen and open it like an app — no app store needed.' },
  { icon: 'megaphone', title: 'Promotions and marketing', text: 'Bring customers back and reward the people who send them to you.', wide: true, chips: ['Coupons', 'Timed sales', 'Email campaigns', 'WhatsApp order updates', 'Affiliate program'] },
  { icon: 'chart', title: 'Reports that matter', text: 'Sales, products, payments and customers at a glance, in your store’s currency and time zone.', wide: true, chips: ['Sales by day', 'Best sellers', 'Payments', 'Customers'] },
  { icon: 'shieldCheck', title: 'Safe by design', text: 'Your store and your customers’ details are protected at every step.', wide: true, chips: ['Two-step sign-in', 'Encrypted details', 'Daily backups', 'Record of who did what'] },
];

const STEPS: { icon: IconName; title: string; text: string }[] = [
  { icon: 'store', title: 'Create your store', text: 'Your name, your store’s name and what you sell. Categories and filters can be set up for you.' },
  { icon: 'box', title: 'Add products and choose a look', text: 'Products with pictures, prices and stock; a theme with your logo and colours.' },
  { icon: 'cart', title: 'Start selling', text: 'Share your shop’s address, take orders on cash on delivery or online, and ship.' },
];

const HIGHLIGHTS = ['Cash on delivery', 'JazzCash & Easypaisa', 'Urdu storefront', 'Installable app'];

/** Shown only when it is true of the prices on the page (see faqFor). */
const PRICING_FAQ = { q: 'How does payment for a package work?', a: 'You pay the package’s one-time price once; it includes your first year. From the second year your store renews every year at the yearly price shown with the package. You can move to a higher package from your store’s billing page.' };

function faqFor(brandName: string, oneTime: boolean): { q: string; a: string }[] {
  return [
    { q: 'Do I need a website or a developer?', a: 'No. Your store gets its own address on the platform as soon as you create it. You can connect your own domain later.' },
    ...(oneTime ? [PRICING_FAQ] : []),
    { q: `Can the ${brandName} team set up my store for me?`, a: `Yes. The ${brandName} team, or one of our partners, can create your store and send you an invitation. You accept it, choose your password and the store is yours.` },
    { q: 'Which payments can my customers use?', a: 'Cash on delivery and bank transfer from the first day. JazzCash and Easypaisa go to your own merchant account once it is connected.' },
    { q: 'Can my customers shop in Urdu?', a: 'Yes. Your shop can be offered in Urdu and English; customers choose, and Urdu is shown right to left.' },
    { q: 'Who owns my store and my data?', a: 'You do. You can download your data at any time, and close or reopen your store yourself.' },
    { q: 'Is my account safe?', a: 'Store owners sign in with two-step sign-in. Sensitive changes ask for the password again, and every change is recorded.' },
  ];
}

/**
 * How each feature a package includes is described to a buyer. Keys are the
 * package features the server sends (only built ones); a key without copy
 * here falls back to its short admin label.
 */
const FEATURE_COPY: Record<string, string> = {
  'payment.cod': 'Cash on delivery, with collection tracked to the rupee',
  'payment.online': 'Online payments with JazzCash and Easypaisa, paid into your own merchant account',
  'payment.bank_transfer': 'Bank transfer with payment confirmation',
  'promotions.basic': 'Discount codes, coupons and timed sales',
  'reviews.product': 'Verified-buyer product reviews and star ratings',
  'pwa.enabled': 'Your shop as an installable app on customers’ phones',
  'domains.custom_domain': 'Connect your own domain name',
  'customers.advanced': 'Customer groups and tags, import and export, duplicate merging',
  'marketing.basic': 'Email campaigns and customer segments',
  'affiliate.programs': 'Your own affiliate program, with commissions and payouts',
  'reports.advanced': 'Advanced sales, product and customer reports',
  'inventory.multi_warehouse': 'Stock across multiple warehouses and locations',
  'custom_roles.enabled': 'Custom staff roles with fine-grained permissions',
  'themes.business': 'The Modern business theme',
  'themes.premium': 'Premium Boutique and Bold themes',
  'integrations.api': 'Developer API and webhooks for your own systems',
  'products.variants': 'Product variants such as sizes and colours',
  'wishlist.basic': 'Customer accounts with wishlists and order history',
  'seo.basic': 'Search-engine titles, descriptions and content pages',
  'products.units_sold': 'Units sold shown on product pages',
  'homepage.advanced_sections': 'Advanced home page sections',
  'animation.advanced': 'Smooth scroll and hover effects',
  'theme.custom_css': 'Custom CSS for your own design touches',
  'layout.advanced': 'Premium page layouts',
  'homepage.reorder': 'Arrange your home page sections freely',
  'animation.premium': 'Premium, playful animations',
};

/** The package's limits in buyer's words; a limit the package does not set is not capped by the platform. */
function limitLines(limits: Record<string, number | null>): string[] {
  const lines: string[] = [];
  const at = (key: string) => (key in limits ? limits[key] : undefined);
  const products = at('max_products');
  const staff = at('max_staff_accounts');
  const orders = at('max_monthly_orders');
  const storage = at('max_storage_gb');
  if (products !== undefined) lines.push(products === null ? 'Unlimited products' : `Up to ${products.toLocaleString()} products`);
  if (staff !== undefined) lines.push(staff === null ? 'Unlimited team members' : `${staff.toLocaleString()} team members`);
  if (orders !== undefined) lines.push(orders === null ? 'Unlimited orders' : `Up to ${orders.toLocaleString()} orders a month`);
  // Storage is named only where it is capped: an unlimited-storage promise is not ours to make.
  if (typeof storage === 'number') lines.push(`${storage.toLocaleString()} GB of storage for pictures and files`);

  return lines;
}

const INTERVAL_WORDS: Record<string, string> = { monthly: '/ month', yearly: '/ year', one_time: 'one-time payment' };

const PERIOD_WORDS: Record<string, string> = { monthly: 'month', yearly: 'year' };

/** A package's features are shown up to this many, then behind "Show all". */
const FEATURES_SHOWN = 7;

const hasOneTime = (plan: Plan) => plan.price?.first_period_amount_minor !== null && plan.price?.first_period_amount_minor !== undefined;

function Price({ plan, dark }: { plan: Plan; dark: boolean }) {
  const strong = dark ? 'text-white' : 'text-slate-900';
  const soft = dark ? 'text-slate-300' : 'text-slate-500';
  if (!plan.price) return <p className={`text-sm ${soft}`}>Price on request</p>;
  const { first_period_amount_minor: once, amount_minor: renewal, currency, interval } = plan.price;
  const period = PERIOD_WORDS[interval] ?? interval;

  // A one-time price that includes the first period, then the renewal price.
  if (once !== null && once !== undefined) {
    return (
      <div>
        <p className="flex flex-wrap items-baseline gap-x-2">
          <span className={`text-4xl font-extrabold tracking-tight ${strong}`}>{money(once, currency)}</span>
          <span className={`text-sm font-medium ${soft}`}>one-time payment</span>
        </p>
        <p className={`mt-3 rounded-lg px-3 py-2 text-sm ${dark ? 'bg-white/10 text-indigo-100' : 'bg-indigo-50 text-indigo-900'}`}>Includes the first {period}, then {money(renewal, currency)} / {period} renewal</p>
      </div>
    );
  }

  return (
    <p className="flex flex-wrap items-baseline gap-x-2">
      <span className={`text-4xl font-extrabold tracking-tight ${strong}`}>{money(plan.price.amount_minor, plan.price.currency)}</span>
      <span className={`text-sm font-medium ${soft}`}>{INTERVAL_WORDS[plan.price.interval] ?? `/ ${plan.price.interval}`}</span>
    </p>
  );
}

function Line({ children, strong = false, dark = false }: { children: string; strong?: boolean; dark?: boolean }) {
  return (
    <li className="flex gap-3">
      <span className={`mt-0.5 flex h-5 w-5 flex-none items-center justify-center rounded-full ${dark ? 'bg-indigo-400/20 text-indigo-200' : 'bg-indigo-50 text-indigo-600'}`}><Icon name="check" size={12} strokeWidth={3} /></span>
      <span className={strong ? `font-semibold ${dark ? 'text-white' : 'text-slate-900'}` : ''}>{children}</span>
    </li>
  );
}

function PlanCard({ plan, previous, highlighted, signupOpen }: { plan: Plan; previous: Plan | null; highlighted: boolean; signupOpen: boolean }) {
  const [all, setAll] = useState(false);
  // A higher package lists what it adds to the one before it.
  const added = previous ? plan.features.filter((key) => !previous.features.includes(key)) : plan.features;
  const shown = all ? added : added.slice(0, FEATURES_SHOWN);
  const dark = highlighted;

  return (
    <article className={`relative flex flex-col rounded-3xl p-8 ${dark ? 'bg-slate-950 text-slate-300 shadow-2xl shadow-indigo-900/30 ring-1 ring-indigo-500/40 lg:-my-4 lg:py-12' : 'border border-slate-200 bg-white text-slate-600 shadow-sm'}`}>
      {dark && <div aria-hidden="true" className="pointer-events-none absolute inset-0 overflow-hidden rounded-3xl"><div className="absolute -right-24 -top-24 h-64 w-64 rounded-full bg-indigo-600/30 blur-3xl" /></div>}
      <div className="relative flex flex-1 flex-col">
        <div className="flex items-center justify-between gap-3">
          <h3 className={`text-xl font-bold ${dark ? 'text-white' : 'text-slate-900'}`}>{plan.name}</h3>
          {highlighted && <span className="rounded-full bg-gradient-to-r from-indigo-500 to-violet-500 px-3 py-1 text-xs font-semibold text-white">Best for growing stores</span>}
        </div>
        <div className="mt-5"><Price plan={plan} dark={dark} /></div>
        {signupOpen && (
          <a href="/register" className={`mt-7 inline-flex items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 motion-reduce:transition-none ${dark ? 'bg-white text-slate-950 hover:bg-indigo-50 focus-visible:ring-offset-slate-950' : 'bg-slate-900 text-white hover:bg-slate-800'}`}>
            Start with {plan.name}<Icon name="arrowRight" size={16} />
          </a>
        )}
        <ul className={`mt-8 space-y-3 border-b pb-6 text-sm ${dark ? 'border-white/10' : 'border-slate-100'}`}>
          {limitLines(plan.limits).map((line) => <Line key={line} strong dark={dark}>{line}</Line>)}
        </ul>
        <div className="mt-6 flex-1">
          {previous && <p className={`mb-4 text-xs font-semibold uppercase tracking-wider ${dark ? 'text-indigo-300' : 'text-indigo-600'}`}>Everything in {previous.name}, plus:</p>}
          <ul className="space-y-3 text-sm">
            {shown.map((key) => <Line key={key} dark={dark}>{FEATURE_COPY[key] ?? FEATURE_LABELS[key] ?? key}</Line>)}
          </ul>
          {added.length > FEATURES_SHOWN && (
            <button type="button" onClick={() => setAll((a) => !a)} aria-expanded={all} className={`mt-4 inline-flex items-center gap-1 text-sm font-semibold focus:outline-none focus-visible:underline ${dark ? 'text-indigo-300 hover:text-white' : 'text-indigo-600 hover:text-indigo-800'}`}>
              {all ? 'Show fewer' : `Show all ${added.length} features`}
              <Icon name="chevronDown" size={16} className={all ? 'rotate-180' : ''} />
            </button>
          )}
        </div>
      </div>
    </article>
  );
}

function SectionHead({ eyebrow, title, children, dark = false }: { eyebrow: string; title: string; children?: ReactNode; dark?: boolean }) {
  return (
    <div className="mx-auto max-w-2xl text-center">
      <p className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wider ${dark ? 'bg-white/10 text-indigo-200' : 'bg-indigo-50 text-indigo-700'}`}>{eyebrow}</p>
      <h2 className={`mt-4 text-3xl font-extrabold tracking-tight sm:text-4xl ${dark ? 'text-white' : 'text-slate-900'}`}>{title}</h2>
      {children && <p className={`mt-4 text-lg ${dark ? 'text-slate-300' : 'text-slate-600'}`}>{children}</p>}
    </div>
  );
}

/** A faint grid behind dark sections. */
const GRID = {
  backgroundImage: 'linear-gradient(rgba(255,255,255,0.05) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.05) 1px, transparent 1px)',
  backgroundSize: '48px 48px',
};

export default function Welcome({ plans = [], trialDays = 14, signupOpen = true }: Props) {
  const brand = useBrand();
  const [menu, setMenu] = useState(false);
  const cta = signupOpen ? { href: '/register', label: 'Create your store' } : { href: '/login', label: 'Sign in' };
  const middle = plans.length >= 3 ? Math.floor(plans.length / 2) : -1;
  const nav = [['#features', 'Features'], ['#how', 'How it works'], ['#pricing', 'Pricing'], ['/partners', 'Partners']];
  const oneTime = plans.some(hasOneTime);
  const faq = faqFor(brand.name, oneTime);
  const trial = signupOpen && trialDays > 0;

  return (
    <div className="min-h-screen bg-white text-slate-900 antialiased">
      <Head title={`${brand.name} — your online store, ready to sell`} />
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:shadow">Skip to content</a>

      <header className="sticky top-0 z-40 border-b border-white/10 bg-slate-950/85 text-white backdrop-blur-md">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4">
          <a href="/" className="rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400"><BrandLogo size={34} tone="light" /></a>
          <nav aria-label="Main" className="hidden items-center gap-1 text-sm font-medium text-slate-300 md:flex">
            {nav.map(([href, label]) => <a key={href} href={href} className="rounded-lg px-3 py-2 hover:bg-white/10 hover:text-white">{label}</a>)}
          </nav>
          <div className="hidden items-center gap-2 md:flex">
            <a href="/login" className="rounded-lg px-3.5 py-2 text-sm font-semibold text-slate-200 hover:bg-white/10 hover:text-white">Sign in</a>
            {signupOpen && <a href="/register" className="rounded-lg bg-white px-4 py-2 text-sm font-semibold text-slate-950 shadow-sm hover:bg-indigo-50">Create your store</a>}
          </div>
          <button type="button" onClick={() => setMenu((m) => !m)} aria-expanded={menu} aria-controls="mobile-menu" aria-label="Menu" className="rounded-lg p-2 text-slate-200 hover:bg-white/10 md:hidden">
            <Icon name={menu ? 'x' : 'menu'} size={22} />
          </button>
        </div>
        {menu && (
          <nav id="mobile-menu" aria-label="Main" className="border-t border-white/10 bg-slate-950 px-4 py-3 md:hidden">
            {nav.map(([href, label]) => <a key={href} href={href} onClick={() => setMenu(false)} className="block rounded-lg px-3 py-2.5 font-medium text-slate-200 hover:bg-white/10">{label}</a>)}
            <div className="mt-2 grid grid-cols-2 gap-2">
              <a href="/login" className="rounded-lg border border-white/20 px-3 py-2.5 text-center font-semibold text-white">Sign in</a>
              {signupOpen && <a href="/register" className="rounded-lg bg-white px-3 py-2.5 text-center font-semibold text-slate-950">Create store</a>}
            </div>
          </nav>
        )}
      </header>

      <main id="main">
        {/* Hero */}
        <section className="relative overflow-hidden bg-slate-950 text-white">
          <div aria-hidden="true" className="pointer-events-none absolute inset-0 [mask-image:radial-gradient(ellipse_at_top,black_30%,transparent_75%)]" style={GRID} />
          <div aria-hidden="true" className="pointer-events-none absolute -top-40 left-1/2 h-[36rem] w-[36rem] -translate-x-1/2 rounded-full bg-indigo-600/30 blur-[120px]" />
          <div aria-hidden="true" className="pointer-events-none absolute -right-32 top-40 h-80 w-80 rounded-full bg-violet-600/25 blur-[100px]" />
          <div className="relative mx-auto grid max-w-6xl items-center gap-14 px-4 pb-24 pt-16 sm:pt-24 lg:grid-cols-[1.05fr_1fr]">
            <div>
              <p className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-indigo-200">
                <Icon name="sparkles" size={14} />Made for sellers in Pakistan
              </p>
              <h1 className="mt-6 text-4xl font-extrabold leading-[1.05] tracking-tight sm:text-6xl">
                Your online store,<br /><span className="bg-gradient-to-r from-indigo-300 via-violet-300 to-fuchsia-300 bg-clip-text text-transparent">ready to sell.</span>
              </h1>
              <p className="mt-6 max-w-xl text-lg leading-relaxed text-slate-300">
                Create your shop, add your products and take orders with cash on delivery, JazzCash or Easypaisa — in Urdu and English, on every phone.
              </p>
              <div className="mt-9 flex flex-wrap gap-3">
                <a href={cta.href} className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-indigo-500 to-violet-500 px-6 py-3.5 font-semibold text-white shadow-lg shadow-indigo-500/30 transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950 motion-reduce:transition-none">
                  {cta.label}<Icon name="arrowRight" size={18} />
                </a>
                <a href="#pricing" className="inline-flex items-center rounded-xl border border-white/20 bg-white/5 px-6 py-3.5 font-semibold text-white hover:bg-white/10">See pricing</a>
              </div>
              {trial && <p className="mt-4 text-sm text-slate-400">Free {trialDays}-day trial. No card needed.</p>}
              <ul className="mt-9 grid max-w-md grid-cols-2 gap-x-6 gap-y-3 text-sm text-slate-300">
                {HIGHLIGHTS.map((t) => (
                  <li key={t} className="flex items-center gap-2"><Icon name="checkCircle" size={17} className="text-emerald-400" />{t}</li>
                ))}
              </ul>
            </div>
            <HeroPreview />
          </div>
        </section>

        {/* Features */}
        <section id="features" className="scroll-mt-20 py-24">
          <div className="mx-auto max-w-6xl px-4">
            <SectionHead eyebrow="Features" title="Everything a store needs, in one place">From the first product to the last delivery: no plugins to hunt for, no developer to call.</SectionHead>
            <div className="mt-16 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
              {FEATURES.map((f) => (
                <div key={f.title} className={`group relative overflow-hidden rounded-3xl border border-slate-200 bg-white p-7 transition hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-xl hover:shadow-indigo-900/5 motion-reduce:transform-none motion-reduce:transition-none ${f.wide ? 'sm:col-span-2' : ''}`}>
                  {f.wide && <div aria-hidden="true" className="pointer-events-none absolute -right-16 -top-16 h-48 w-48 rounded-full bg-gradient-to-br from-indigo-100 to-violet-100 opacity-70 blur-2xl" />}
                  <div className="relative">
                    <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/25"><Icon name={f.icon} size={22} /></span>
                    <h3 className="mt-5 text-lg font-bold text-slate-900">{f.title}</h3>
                    <p className="mt-2 text-sm leading-relaxed text-slate-600">{f.text}</p>
                    {f.chips && (
                      <ul className="mt-5 flex flex-wrap gap-2">
                        {f.chips.map((c) => <li key={c} className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-700">{c}</li>)}
                      </ul>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* How it works */}
        <section id="how" className="scroll-mt-20 border-y border-slate-200 bg-slate-50 py-24">
          <div className="mx-auto max-w-6xl px-4">
            <SectionHead eyebrow="How it works" title="Start selling in three steps">No code to write, no developer to hire.</SectionHead>
            <ol className="relative mt-16 grid gap-10 md:grid-cols-3 md:gap-8">
              <div aria-hidden="true" className="absolute left-[16%] right-[16%] top-8 hidden border-t-2 border-dashed border-indigo-200 md:block" />
              {STEPS.map((s, i) => (
                <li key={s.title} className="relative text-center">
                  <span className="relative mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-white text-indigo-600 shadow-md ring-1 ring-slate-200">
                    <Icon name={s.icon} size={26} />
                    <span className="absolute -right-2 -top-2 flex h-6 w-6 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-600 text-xs font-bold text-white">{i + 1}</span>
                  </span>
                  <h3 className="mt-6 text-lg font-bold">{s.title}</h3>
                  <p className="mx-auto mt-2 max-w-xs text-sm leading-relaxed text-slate-600">{s.text}</p>
                </li>
              ))}
            </ol>
          </div>
        </section>

        {/* Pricing */}
        <section id="pricing" className="scroll-mt-20 py-24">
          <div className="mx-auto max-w-6xl px-4">
            <SectionHead eyebrow="Pricing" title="A package for every stage">
              {oneTime
                ? 'Pay once to set up your store, renew each year to keep it running. Start small and move up when you grow.'
                : 'Start small and move up when you grow. Change your package any time from your store’s billing page.'}
            </SectionHead>
            {(oneTime || trial) && (
              <ul className="mx-auto mt-8 flex max-w-3xl flex-wrap justify-center gap-x-6 gap-y-2 text-sm font-medium text-slate-600">
                {oneTime && <li className="flex items-center gap-1.5"><Icon name="checkCircle" size={16} className="text-indigo-600" />One-time price includes the first year</li>}
                {oneTime && <li className="flex items-center gap-1.5"><Icon name="checkCircle" size={16} className="text-indigo-600" />Simple yearly renewal</li>}
                {trial && <li className="flex items-center gap-1.5"><Icon name="checkCircle" size={16} className="text-indigo-600" />Try it free for {trialDays} days</li>}
              </ul>
            )}
            {plans.length === 0 ? (
              <p className="mt-12 text-center text-slate-600">Packages are being prepared. Contact us for a price.</p>
            ) : (
              <div className={`mx-auto mt-16 grid items-start gap-6 ${plans.length >= 3 ? 'lg:grid-cols-3' : 'max-w-3xl md:grid-cols-2'}`}>
                {plans.map((p, i) => <PlanCard key={p.code} plan={p} previous={i > 0 ? plans[i - 1] : null} highlighted={i === middle} signupOpen={signupOpen} />)}
              </div>
            )}
          </div>
        </section>

        {/* Partners */}
        <section className="px-4">
          <div className="relative mx-auto max-w-6xl overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600 p-8 text-white sm:p-12">
            <div aria-hidden="true" className="pointer-events-none absolute inset-0 opacity-60" style={GRID} />
            <div className="relative flex flex-col items-start justify-between gap-6 md:flex-row md:items-center">
              <div className="flex gap-5">
                <span className="hidden h-14 w-14 flex-none items-center justify-center rounded-2xl bg-white/15 sm:flex"><Icon name="handshake" size={28} /></span>
                <div>
                  <h2 className="text-2xl font-bold sm:text-3xl">Set up stores for your clients?</h2>
                  <p className="mt-2 max-w-xl text-indigo-100">Agencies and consultants can become {brand.name} partners: your clients get a partner price, you earn on what they pay.</p>
                </div>
              </div>
              <a href="/partners" className="inline-flex flex-none items-center gap-2 rounded-xl bg-white px-6 py-3 font-semibold text-indigo-700 shadow-lg hover:bg-indigo-50">
                Become a partner<Icon name="arrowRight" size={18} />
              </a>
            </div>
          </div>
        </section>

        {/* FAQ */}
        <section id="faq" className="scroll-mt-20 py-24">
          <div className="mx-auto grid max-w-6xl gap-10 px-4 lg:grid-cols-[1fr_1.6fr]">
            <div>
              <p className="inline-flex rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-indigo-700">FAQ</p>
              <h2 className="mt-4 text-3xl font-extrabold tracking-tight sm:text-4xl">Questions</h2>
              <p className="mt-4 text-lg text-slate-600">What sellers usually ask before they open their store.</p>
            </div>
            <div className="space-y-3">
              {faq.map((f) => (
                <details key={f.q} className="group rounded-2xl border border-slate-200 bg-white px-6 py-5 transition open:border-indigo-200 open:bg-indigo-50/40 open:shadow-sm motion-reduce:transition-none [&_summary::-webkit-details-marker]:hidden">
                  <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold text-slate-900">
                    {f.q}
                    <span aria-hidden="true" className="flex h-7 w-7 flex-none items-center justify-center rounded-full bg-slate-100 text-slate-500 transition-transform group-open:rotate-45 group-open:bg-indigo-600 group-open:text-white motion-reduce:transition-none">+</span>
                  </summary>
                  <p className="mt-3 text-sm leading-relaxed text-slate-600">{f.a}</p>
                </details>
              ))}
            </div>
          </div>
        </section>

        {/* Final call */}
        <section className="px-4 pb-24">
          <div className="relative mx-auto max-w-6xl overflow-hidden rounded-3xl bg-slate-950 px-6 py-16 text-center text-white sm:py-20">
            <div aria-hidden="true" className="pointer-events-none absolute inset-0 [mask-image:radial-gradient(ellipse_at_center,black_20%,transparent_70%)]" style={GRID} />
            <div aria-hidden="true" className="pointer-events-none absolute left-1/2 top-0 h-72 w-[40rem] -translate-x-1/2 rounded-full bg-indigo-600/30 blur-[100px]" />
            <div className="relative">
              <h2 className="text-3xl font-extrabold tracking-tight sm:text-4xl">Ready to open your shop?</h2>
              <p className="mx-auto mt-4 max-w-lg text-lg text-slate-300">It takes a few minutes, and you can change everything later.</p>
              <a href={cta.href} className="mt-9 inline-flex items-center gap-2 rounded-xl bg-white px-7 py-3.5 font-semibold text-slate-950 shadow-lg hover:bg-indigo-50">
                {cta.label}<Icon name="arrowRight" size={18} />
              </a>
              {trial && <p className="mt-4 text-sm text-slate-400">Free {trialDays}-day trial, then the package you choose.</p>}
            </div>
          </div>
        </section>
      </main>

      <footer className="bg-slate-950 text-slate-400">
        <div className="mx-auto grid max-w-6xl gap-10 px-4 py-14 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr]">
          <div>
            <BrandLogo size={32} tone="light" />
            <p className="mt-4 max-w-xs text-sm leading-relaxed">Online stores for sellers in Pakistan — payments, shipping and an Urdu storefront in one place.</p>
          </div>
          <FooterLinks title="Product" links={[['#features', 'Features'], ['#how', 'How it works'], ['#pricing', 'Pricing']]} />
          <FooterLinks title="Company" links={[['/partners', 'Partners'], ['#faq', 'Questions']]} />
          <FooterLinks title="Account" links={[['/login', 'Sign in'], ...(signupOpen ? [['/register', 'Create your store']] : [])]} />
        </div>
        <p className="border-t border-white/10 py-5 text-center text-xs">© {new Date().getFullYear()} {brand.name}. All rights reserved.</p>
      </footer>
    </div>
  );
}

function FooterLinks({ title, links }: { title: string; links: string[][] }) {
  return (
    <nav aria-label={title}>
      <p className="text-sm font-semibold text-white">{title}</p>
      <ul className="mt-4 space-y-2.5 text-sm">
        {links.map(([href, label]) => <li key={href}><a href={href} className="hover:text-white">{label}</a></li>)}
      </ul>
    </nav>
  );
}

/** An illustration of the admin and a shop on a phone (shapes and words, no real or invented figures). */
function HeroPreview() {
  return (
    <div aria-hidden="true" className="relative mx-auto w-full max-w-lg lg:max-w-none">
      <div className="absolute -inset-6 rounded-[2.5rem] bg-gradient-to-br from-indigo-500/30 to-fuchsia-500/20 blur-3xl" />
      {/* Admin window */}
      <div className="relative overflow-hidden rounded-2xl border border-white/10 bg-white shadow-2xl shadow-black/40">
        <div className="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-4 py-3">
          <span className="h-2.5 w-2.5 rounded-full bg-red-300" /><span className="h-2.5 w-2.5 rounded-full bg-amber-300" /><span className="h-2.5 w-2.5 rounded-full bg-emerald-300" />
          <span className="ml-3 h-5 flex-1 rounded-md bg-white ring-1 ring-slate-200" />
        </div>
        <div className="grid grid-cols-[6.5rem_1fr]">
          <div className="space-y-1.5 border-r border-slate-100 p-3">
            {['Dashboard', 'Orders', 'Products', 'Customers', 'Reports'].map((l, i) => (
              <div key={l} className={`rounded-md px-2 py-1.5 text-[11px] font-medium ${i === 0 ? 'bg-indigo-50 text-indigo-700' : 'text-slate-500'}`}>{l}</div>
            ))}
          </div>
          <div className="space-y-3 p-4 pr-16 sm:pr-24">
            <div className="grid grid-cols-3 gap-2">
              {['Sales', 'Orders', 'Visitors'].map((l, i) => (
                <div key={l} className="rounded-lg border border-slate-100 p-2">
                  <div className="text-[10px] uppercase text-slate-400">{l}</div>
                  <div className={`mt-1.5 h-2.5 rounded ${['w-3/4 bg-indigo-200', 'w-1/2 bg-violet-200', 'w-2/3 bg-slate-200'][i]}`} />
                </div>
              ))}
            </div>
            <div className="rounded-lg border border-slate-100 p-3">
              <svg viewBox="0 0 200 60" className="h-20 w-full">
                <defs><linearGradient id="hero-area" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="#6366f1" stopOpacity="0.35" /><stop offset="1" stopColor="#6366f1" stopOpacity="0" /></linearGradient></defs>
                <path d="M0 48 L25 40 L50 44 L75 30 L100 34 L125 20 L150 24 L175 12 L200 16 L200 60 L0 60 Z" fill="url(#hero-area)" />
                <path d="M0 48 L25 40 L50 44 L75 30 L100 34 L125 20 L150 24 L175 12 L200 16" fill="none" stroke="#6366f1" strokeWidth="2" />
              </svg>
            </div>
            {['Delivered', 'Confirmed', 'Shipped'].map((s, i) => (
              <div key={s} className="flex items-center gap-3 rounded-lg border border-slate-100 px-3 py-2">
                <div className="h-2 w-16 rounded bg-slate-200" />
                <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${['bg-emerald-50 text-emerald-700', 'bg-blue-50 text-blue-700', 'bg-amber-50 text-amber-700'][i]}`}>{s}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
      {/* Shop on a phone */}
      <div className="absolute -bottom-10 -right-2 w-32 rounded-[1.6rem] border-4 border-slate-800 bg-white p-2 shadow-2xl shadow-black/50 sm:-right-4 sm:w-40">
        <div className="mx-auto mb-2 h-1 w-10 rounded-full bg-slate-200" />
        <div className="flex items-center justify-between px-1">
          <span className="h-2 w-10 rounded bg-indigo-200" />
          <span className="rounded bg-slate-100 px-1 text-[8px] font-semibold text-slate-600">اردو</span>
        </div>
        <div className="mt-2 grid grid-cols-2 gap-1.5">
          {['from-indigo-200 to-violet-200', 'from-amber-100 to-rose-200', 'from-emerald-100 to-teal-200', 'from-sky-100 to-indigo-200'].map((g) => (
            <div key={g}>
              <div className={`aspect-square rounded-md bg-gradient-to-br ${g}`} />
              <div className="mt-1 h-1.5 w-3/4 rounded bg-slate-200" />
            </div>
          ))}
        </div>
        <div className="mt-2 rounded-md bg-indigo-600 py-1 text-center text-[8px] font-semibold text-white">Add to cart</div>
      </div>
      {/* Cash on delivery note */}
      <div className="absolute -bottom-6 -left-4 hidden rounded-2xl border border-slate-200 bg-white p-3 shadow-xl sm:block">
        <div className="flex items-center gap-2.5">
          <span className="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"><Icon name="cash" size={18} /></span>
          <div><div className="text-xs font-semibold text-slate-800">Cash on delivery</div><div className="text-[11px] text-slate-500">collected and recorded</div></div>
        </div>
      </div>
    </div>
  );
}
