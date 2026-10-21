import { useMemo } from 'react';
import { Link, usePage } from '@inertiajs/react';
import AdminPage from '@/Components/AdminPage';
import Icon, { type IconName } from '@/Components/ui/Icon';
import { FOCUS_RING } from '@/Components/ui/Button';
import { accessFrom, type AuthProps } from '@/lib/access';
import { accountNav, navigationFor, NAV_ICONS, toneClass, type NavEntry, type Tone } from '@/lib/adminNav';
import { useAdminT } from '@/lib/adminI18n';

/**
 * UI-26 — every setting of the store on one page, in four groups, instead
 * of a dozen entries in the sidebar. Each card says what is inside and
 * opens it in one click. Only what this user may open is listed (the
 * server still checks every page).
 */
const GROUPS: { title: string; description: string; icon: IconName; tone: Tone; hrefs: string[] }[] = [
  { title: 'Selling', description: 'How customers pay, where you deliver, and tax.', icon: 'cart', tone: 'blue', hrefs: ['/settings/general', '/settings/payments', '/shipping', '/settings/tax'] },
  { title: 'Team and account', description: 'Who works in the store, your package and your sign-in.', icon: 'users', tone: 'violet', hrefs: ['/team', '/team/roles', '/billing', accountNav.href] },
  { title: 'Data and connections', description: 'Backups, apps, the API and the record of changes.', icon: 'database', tone: 'cyan', hrefs: ['/backups', '/apps', '/settings/developer', '/settings/audit-log'] },
  { title: 'Closing the store', description: 'Download everything, close or reopen the store.', icon: 'lock', tone: 'pink', hrefs: ['/settings/close-store'] },
];

function SettingCard({ item, recommended = false }: { item: NavEntry; recommended?: boolean }) {
  const t = useAdminT();
  const icon = NAV_ICONS[item.href] ?? 'settings';
  const inner = (
    <>
      <span aria-hidden="true" className="tone-chip flex h-11 w-11 flex-none items-center justify-center rounded-xl transition-transform duration-200 group-hover:scale-105 motion-reduce:transform-none">
        <Icon name={icon} size={20} />
      </span>
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-2 text-[15px] font-semibold text-slate-900">
          {t(item.label)}
          {item.state === 'not_in_package' && <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700">{t('Upgrade')}</span>}
          {recommended && <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700">{t('Recommended')}</span>}
        </span>
        <span className="mt-0.5 block text-sm text-slate-600">{t(item.description)}</span>
      </span>
      <Icon name="arrowRight" size={16} className="mt-1 flex-none text-slate-300 transition-transform duration-150 group-hover:translate-x-0.5 group-hover:text-slate-500 rtl:-scale-x-100 rtl:group-hover:-translate-x-0.5 motion-reduce:transform-none" />
    </>
  );
  const box = 'group flex h-full items-start gap-4 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm shadow-slate-900/5';

  return item.state === 'open' ? (
    <Link href={item.href} className={`${box} transition duration-200 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md motion-reduce:transform-none motion-reduce:transition-none ${FOCUS_RING}`}>
      {inner}
    </Link>
  ) : (
    <div aria-disabled="true" title={item.reason} className={`${box} opacity-60`}>{inner}</div>
  );
}

export default function SettingsHub() {
  const t = useAdminT();
  const page = usePage<{ auth: AuthProps }>();
  const entries = useMemo(() => {
    const all = navigationFor(accessFrom(page.props.auth)).flatMap((group) => group.items);

    return new Map(all.map((item) => [item.href, item]));
  }, [page.props.auth]);

  return (
    <AdminPage title="Settings" description="Everything about how your store works, in one place. Choose what you want to set up.">
      <div className="space-y-8">
        {GROUPS.map((group) => {
          const items = group.hrefs.map((href) => entries.get(href)).filter((item): item is NavEntry => item !== undefined);
          if (items.length === 0) return null;

          return (
            <section key={group.title} aria-labelledby={`settings-${group.icon}`} className={toneClass(group.tone)}>
              <div className="mb-3 flex items-center gap-3">
                <span aria-hidden="true" className="tone-bar h-6 w-1 rounded-full" />
                <div>
                  <h2 id={`settings-${group.icon}`} className="text-lg font-semibold text-slate-900">{t(group.title)}</h2>
                  <p className="text-sm text-slate-500">{t(group.description)}</p>
                </div>
              </div>
              <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {items.map((item) => (
                  <li key={item.href}>
                    <SettingCard item={item} recommended={item.href === accountNav.href && page.props.auth.user?.mfa_enabled === false} />
                  </li>
                ))}
              </ul>
            </section>
          );
        })}
      </div>
    </AdminPage>
  );
}
