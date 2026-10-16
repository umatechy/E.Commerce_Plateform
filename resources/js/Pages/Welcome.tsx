import { useState } from 'react';
import { Head } from '@inertiajs/react';
import BrandLogo, { useBrand } from '@/Components/Brand';
import Icon, { type IconName } from '@/Components/ui/Icon';
import { FEATURE_LABELS, USAGE_LABELS } from '@/lib/labels';
import { money } from '@/lib/money';

/**
 * UI-1 — the public home page of the platform's own host (signed-in users
 * get Pages/Dashboard instead, see routes/web.php): what the platform
 * does, how a store starts, the packages as Umar Techy priced them (from
 * the server; nothing here invents a price or a feature), partners and
 * answers to first questions.
 */
type Plan = { code: string; name: string; price: { amount_minor: number; currency: string; interval: string } | null; limits: Record<string, number | null>; features: string[] };
type Props = { plans?: Plan[]; trialDays?: number; signupOpen?: boolean };

const FEATURES: { icon: IconName; title: string; text: string }[] = [
  { icon: 'wallet', title: 'Payments Pakistan uses', text: 'Cash on delivery, bank transfer, JazzCash and Easypaisa. The money goes to your own accounts.' },
  { icon: 'truck', title: 'Shipping and COD collection', text: 'Zones, rates and shipments with tracking, and the cash your courier collects, followed to the rupee.' },
  { icon: 'language', title: 'Urdu and English shop', text: 'Customers read your shop in Urdu, right to left, or in English. Translate names of products and categories.' },
  { icon: 'palette', title: 'Themes you can make yours', text: 'Ready themes, your colours, fonts and home page sections. Preview before you publish.' },
  { icon: 'phone', title: 'Your shop as a phone app', text: 'Customers add your shop to their home screen and open it like an app, without an app store.' },
  { icon: 'tag', title: 'Promotions and marketing', text: 'Coupons, sales, email campaigns, WhatsApp order updates and an affiliate program.' },
  { icon: 'chart', title: 'Reports that matter', text: 'Sales, products, payments and customers at a glance, in your store’s currency and time.' },
  { icon: 'shieldCheck', title: 'Safe by design', text: 'Two-step sign-in, encrypted details, daily backups and a record of who did what.' },
];

const STEPS: { title: string; text: string }[] = [
  { title: 'Create your store', text: 'Your name, your store’s name and what you sell. Categories and filters can be set up for you.' },
  { title: 'Add products and choose a look', text: 'Products with pictures, prices and stock; a theme with your logo and colours.' },
  { title: 'Start selling', text: 'Share your shop’s address, take orders on cash on delivery or online, and ship.' },
];

const FAQ: { q: string; a: string }[] = [
  { q: 'Do I need a website or a developer?', a: 'No. Your store gets its own address on the platform as soon as you create it. You can connect your own domain later.' },
  { q: 'Which payments can my customers use?', a: 'Cash on delivery and bank transfer from the first day. JazzCash and Easypaisa go to your own merchant account once it is connected.' },
  { q: 'Can my customers shop in Urdu?', a: 'Yes. Your shop can be offered in Urdu and English; customers choose, and Urdu is shown right to left.' },
  { q: 'Who owns my store and my data?', a: 'You do. You can download your data at any time, and close or reopen your store yourself.' },
  { q: 'Is my account safe?', a: 'Store owners sign in with two-step sign-in. Sensitive changes ask for the password again, and every change is recorded.' },
];

function Price({ plan }: { plan: Plan }) {
  if (!plan.price) return <p className="text-sm text-slate-500">Price on request</p>;

  return (
    <p className="flex items-baseline gap-1">
      <span className="text-3xl font-bold tracking-tight text-slate-900">{money(plan.price.amount_minor, plan.price.currency)}</span>
      <span className="text-sm text-slate-500">/ {plan.price.interval === 'yearly' ? 'year' : 'month'}</span>
    </p>
  );
}

function PlanCard({ plan, highlighted, signupOpen }: { plan: Plan; highlighted: boolean; signupOpen: boolean }) {
  const limits = Object.entries(plan.limits).filter(([key]) => USAGE_LABELS[key]);

  return (
    <article className={`flex flex-col rounded-2xl border bg-white p-6 shadow-sm ${highlighted ? 'border-indigo-500 ring-2 ring-indigo-500/20' : 'border-slate-200'}`}>
      <h3 className="text-lg font-semibold text-slate-900">{plan.name}</h3>
      <div className="mt-3"><Price plan={plan} /></div>
      <ul className="mt-6 flex-1 space-y-2.5 text-sm text-slate-700">
        {limits.map(([key, value]) => (
          <li key={key} className="flex gap-2"><Icon name="check" size={18} className="text-indigo-600" />{USAGE_LABELS[key]}: {value === null ? 'unlimited' : value.toLocaleString()}</li>
        ))}
        {plan.features.map((key) => (
          <li key={key} className="flex gap-2"><Icon name="check" size={18} className="text-indigo-600" />{FEATURE_LABELS[key] ?? key}</li>
        ))}
      </ul>
      {signupOpen && (
        <a href="/register" className={`mt-6 inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 ${highlighted ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'border border-slate-300 text-slate-800 hover:bg-slate-50'}`}>
          Start with {plan.name}
        </a>
      )}
    </article>
  );
}

export default function Welcome({ plans = [], trialDays = 14, signupOpen = true }: Props) {
  const brand = useBrand();
  const [menu, setMenu] = useState(false);
  const cta = signupOpen ? { href: '/register', label: 'Create your store' } : { href: '/login', label: 'Sign in' };
  const middle = plans.length >= 3 ? Math.floor(plans.length / 2) : -1;
  const nav = [['#features', 'Features'], ['#how', 'How it works'], ['#pricing', 'Pricing'], ['/partners', 'Partners']];

  return (
    <div className="min-h-screen bg-white text-slate-900">
      <Head title={`${brand.name} — your online store, ready to sell`} />
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:shadow">Skip to content</a>

      <header className="sticky top-0 z-40 border-b border-slate-200/70 bg-white/85 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4">
          <a href="/" className="rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"><BrandLogo size={34} /></a>
          <nav aria-label="Main" className="hidden items-center gap-7 text-sm font-medium text-slate-600 md:flex">
            {nav.map(([href, label]) => <a key={href} href={href} className="hover:text-slate-900">{label}</a>)}
          </nav>
          <div className="hidden items-center gap-2 md:flex">
            <a href="/login" className="rounded-lg px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Sign in</a>
            {signupOpen && <a href="/register" className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Create your store</a>}
          </div>
          <button type="button" onClick={() => setMenu((m) => !m)} aria-expanded={menu} aria-controls="mobile-menu" aria-label="Menu" className="rounded-lg p-2 text-slate-700 hover:bg-slate-100 md:hidden">
            <Icon name={menu ? 'x' : 'menu'} size={22} />
          </button>
        </div>
        {menu && (
          <nav id="mobile-menu" aria-label="Main" className="border-t border-slate-200 bg-white px-4 py-3 md:hidden">
            {nav.map(([href, label]) => <a key={href} href={href} onClick={() => setMenu(false)} className="block rounded-lg px-3 py-2.5 font-medium text-slate-700 hover:bg-slate-50">{label}</a>)}
            <div className="mt-2 grid grid-cols-2 gap-2">
              <a href="/login" className="rounded-lg border border-slate-300 px-3 py-2.5 text-center font-semibold text-slate-800">Sign in</a>
              {signupOpen && <a href="/register" className="rounded-lg bg-indigo-600 px-3 py-2.5 text-center font-semibold text-white">Create store</a>}
            </div>
          </nav>
        )}
      </header>

      <main id="main">
        {/* Hero */}
        <section className="relative overflow-hidden bg-gradient-to-b from-indigo-50/70 via-white to-white">
          <div aria-hidden="true" className="pointer-events-none absolute -right-40 -top-40 h-[30rem] w-[30rem] rounded-full bg-violet-200/40 blur-3xl" />
          <div className="relative mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 sm:py-24 lg:grid-cols-2">
            <div>
              <p className="inline-flex items-center gap-2 rounded-full border border-indigo-200 bg-white px-3 py-1 text-xs font-semibold text-indigo-700 shadow-sm">
                <Icon name="sparkles" size={14} />Made for sellers in Pakistan
              </p>
              <h1 className="mt-5 text-4xl font-extrabold leading-[1.1] tracking-tight text-slate-900 sm:text-5xl">
                Your online store,<br /><span className="bg-gradient-to-r from-indigo-600 to-violet-600 bg-clip-text text-transparent">ready to sell.</span>
              </h1>
              <p className="mt-5 max-w-xl text-lg text-slate-600">
                Create your shop, add your products and take orders with cash on delivery, JazzCash or Easypaisa — in Urdu and English, on every phone.
              </p>
              <div className="mt-8 flex flex-wrap gap-3">
                <a href={cta.href} className="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-6 py-3 font-semibold text-white shadow-lg shadow-indigo-600/20 hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                  {cta.label}<Icon name="arrowRight" size={18} />
                </a>
                <a href="#pricing" className="inline-flex items-center rounded-xl border border-slate-300 bg-white px-6 py-3 font-semibold text-slate-800 hover:bg-slate-50">See pricing</a>
              </div>
              {signupOpen && trialDays > 0 && <p className="mt-4 text-sm text-slate-500">Free {trialDays}-day trial. No card needed.</p>}
              <ul className="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-600">
                {['Cash on delivery', 'JazzCash & Easypaisa', 'Urdu storefront', 'Installable app'].map((t) => (
                  <li key={t} className="flex items-center gap-1.5"><Icon name="checkCircle" size={16} className="text-emerald-600" />{t}</li>
                ))}
              </ul>
            </div>
            <HeroPreview />
          </div>
        </section>

        {/* Features */}
        <section id="features" className="scroll-mt-20 py-20">
          <div className="mx-auto max-w-6xl px-4">
            <div className="mx-auto max-w-2xl text-center">
              <p className="text-sm font-semibold uppercase tracking-wider text-indigo-600">Features</p>
              <h2 className="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">Everything a store needs, in one place</h2>
              <p className="mt-4 text-slate-600">From the first product to the last delivery: no plugins to hunt for, no developer to call.</p>
            </div>
            <div className="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
              {FEATURES.map((f) => (
                <div key={f.title} className="rounded-2xl border border-slate-200 bg-white p-6 transition-shadow hover:shadow-md motion-reduce:transition-none">
                  <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600"><Icon name={f.icon} size={22} /></span>
                  <h3 className="mt-4 font-semibold text-slate-900">{f.title}</h3>
                  <p className="mt-2 text-sm leading-relaxed text-slate-600">{f.text}</p>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* How it works */}
        <section id="how" className="scroll-mt-20 bg-slate-50 py-20">
          <div className="mx-auto max-w-6xl px-4">
            <h2 className="text-center text-3xl font-bold tracking-tight sm:text-4xl">Start selling in three steps</h2>
            <ol className="mt-14 grid gap-8 md:grid-cols-3">
              {STEPS.map((s, i) => (
                <li key={s.title} className="relative rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                  <span className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-indigo-600 to-violet-600 font-bold text-white">{i + 1}</span>
                  <h3 className="mt-4 text-lg font-semibold">{s.title}</h3>
                  <p className="mt-2 text-sm leading-relaxed text-slate-600">{s.text}</p>
                </li>
              ))}
            </ol>
          </div>
        </section>

        {/* Pricing */}
        <section id="pricing" className="scroll-mt-20 py-20">
          <div className="mx-auto max-w-6xl px-4">
            <div className="mx-auto max-w-2xl text-center">
              <p className="text-sm font-semibold uppercase tracking-wider text-indigo-600">Pricing</p>
              <h2 className="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">A package for every stage</h2>
              <p className="mt-4 text-slate-600">Start small and move up when you grow. Change your package any time from your store’s billing page.</p>
            </div>
            {plans.length === 0 ? (
              <p className="mt-12 text-center text-slate-600">Packages are being prepared. Contact us for a price.</p>
            ) : (
              <div className={`mt-14 grid gap-6 ${plans.length >= 3 ? 'lg:grid-cols-3' : 'md:grid-cols-2'} mx-auto ${plans.length < 3 ? 'max-w-3xl' : ''}`}>
                {plans.map((p, i) => <PlanCard key={p.code} plan={p} highlighted={i === middle} signupOpen={signupOpen} />)}
              </div>
            )}
          </div>
        </section>

        {/* Partners */}
        <section className="px-4">
          <div className="mx-auto flex max-w-6xl flex-col items-start justify-between gap-6 rounded-3xl bg-gradient-to-r from-indigo-700 to-violet-700 p-8 text-white sm:p-12 md:flex-row md:items-center">
            <div>
              <h2 className="text-2xl font-bold">Set up stores for your clients?</h2>
              <p className="mt-2 max-w-xl text-indigo-100">Agencies and consultants can become {brand.name} partners: your clients get a partner price, you earn on what they pay.</p>
            </div>
            <a href="/partners" className="inline-flex flex-none items-center gap-2 rounded-xl bg-white px-6 py-3 font-semibold text-indigo-700 hover:bg-indigo-50">
              Become a partner<Icon name="arrowRight" size={18} />
            </a>
          </div>
        </section>

        {/* FAQ */}
        <section id="faq" className="scroll-mt-20 py-20">
          <div className="mx-auto max-w-3xl px-4">
            <h2 className="text-center text-3xl font-bold tracking-tight">Questions</h2>
            <div className="mt-10 divide-y divide-slate-200 rounded-2xl border border-slate-200">
              {FAQ.map((f) => (
                <details key={f.q} className="group p-5 [&_summary::-webkit-details-marker]:hidden">
                  <summary className="flex cursor-pointer items-center justify-between gap-4 font-semibold text-slate-900">
                    {f.q}
                    <span aria-hidden="true" className="text-xl text-slate-400 transition-transform group-open:rotate-45 motion-reduce:transition-none">+</span>
                  </summary>
                  <p className="mt-3 text-sm leading-relaxed text-slate-600">{f.a}</p>
                </details>
              ))}
            </div>
          </div>
        </section>

        {/* Final call */}
        <section className="pb-20">
          <div className="mx-auto max-w-3xl px-4 text-center">
            <h2 className="text-3xl font-bold tracking-tight">Ready to open your shop?</h2>
            <p className="mt-3 text-slate-600">It takes a few minutes, and you can change everything later.</p>
            <a href={cta.href} className="mt-8 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-7 py-3.5 font-semibold text-white shadow-lg shadow-indigo-600/20 hover:bg-indigo-700">
              {cta.label}<Icon name="arrowRight" size={18} />
            </a>
          </div>
        </section>
      </main>

      <footer className="border-t border-slate-200 bg-slate-50">
        <div className="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-10 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <BrandLogo size={30} />
            <p className="mt-2 text-sm text-slate-500">Online stores for sellers in Pakistan.</p>
          </div>
          <nav aria-label="Footer" className="flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-600">
            <a href="#features" className="hover:text-slate-900">Features</a>
            <a href="#pricing" className="hover:text-slate-900">Pricing</a>
            <a href="/partners" className="hover:text-slate-900">Partners</a>
            <a href="/login" className="hover:text-slate-900">Sign in</a>
            {signupOpen && <a href="/register" className="hover:text-slate-900">Create your store</a>}
          </nav>
        </div>
        <p className="border-t border-slate-200 py-4 text-center text-xs text-slate-500">© {new Date().getFullYear()} {brand.name}</p>
      </footer>
    </div>
  );
}

/** An illustration of the admin (shapes and words, no real or invented figures). */
function HeroPreview() {
  return (
    <div aria-hidden="true" className="relative mx-auto w-full max-w-lg">
      <div className="absolute -inset-4 rounded-[2rem] bg-gradient-to-br from-indigo-500/20 to-violet-500/20 blur-2xl" />
      <div className="relative overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
        <div className="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-4 py-3">
          <span className="h-2.5 w-2.5 rounded-full bg-red-300" /><span className="h-2.5 w-2.5 rounded-full bg-amber-300" /><span className="h-2.5 w-2.5 rounded-full bg-emerald-300" />
        </div>
        <div className="grid grid-cols-[7rem_1fr]">
          <div className="space-y-2 border-r border-slate-100 p-3">
            {['Orders', 'Products', 'Customers', 'Promotions', 'Reports'].map((l, i) => (
              <div key={l} className={`rounded-md px-2 py-1.5 text-[11px] font-medium ${i === 0 ? 'bg-indigo-50 text-indigo-700' : 'text-slate-500'}`}>{l}</div>
            ))}
          </div>
          <div className="space-y-3 p-4">
            <div className="grid grid-cols-3 gap-2">
              {['Sales', 'Orders', 'Visitors'].map((l) => (
                <div key={l} className="rounded-lg border border-slate-100 p-2">
                  <div className="text-[10px] uppercase text-slate-400">{l}</div>
                  <div className="mt-1.5 h-2.5 w-3/4 rounded bg-slate-200" />
                </div>
              ))}
            </div>
            <div className="rounded-lg border border-slate-100 p-3">
              <svg viewBox="0 0 200 60" className="h-16 w-full">
                <defs><linearGradient id="hero-area" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="#6366f1" stopOpacity="0.35" /><stop offset="1" stopColor="#6366f1" stopOpacity="0" /></linearGradient></defs>
                <path d="M0 48 L25 40 L50 44 L75 30 L100 34 L125 20 L150 24 L175 12 L200 16 L200 60 L0 60 Z" fill="url(#hero-area)" />
                <path d="M0 48 L25 40 L50 44 L75 30 L100 34 L125 20 L150 24 L175 12 L200 16" fill="none" stroke="#6366f1" strokeWidth="2" />
              </svg>
            </div>
            {['Delivered', 'Confirmed', 'Shipped'].map((s, i) => (
              <div key={s} className="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2">
                <div className="h-2 w-16 rounded bg-slate-200" />
                <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${['bg-emerald-50 text-emerald-700', 'bg-blue-50 text-blue-700', 'bg-amber-50 text-amber-700'][i]}`}>{s}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
      <div className="absolute -bottom-6 -left-6 hidden rounded-2xl border border-slate-200 bg-white p-3 shadow-xl sm:block">
        <div className="flex items-center gap-2.5">
          <span className="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"><Icon name="cash" size={18} /></span>
          <div><div className="text-xs font-semibold text-slate-800">Cash on delivery</div><div className="text-[11px] text-slate-500">collected and recorded</div></div>
        </div>
      </div>
    </div>
  );
}
