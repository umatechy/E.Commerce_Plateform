import { ReactNode, useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Breadcrumbs, PageHeader, SectionTabs, type Crumb } from '@/Components/ui/Page';
import { accessFrom, type AuthProps } from '@/lib/access';
import { activeHref, breadcrumbsFor, navigationFor, NAV_ICONS, sectionFor, toneClass, type ResolvedGroup } from '@/lib/adminNav';

/**
 * An admin page: the shell, the breadcrumb trail and the page heading.
 * The trail comes from the navigation (Dashboard / section / page);
 * `trail` adds the levels below the page, such as a product's name.
 *
 * UI-26: the page takes its section's colour (cards, chips, tabs), shows
 * the section icon by its heading, and — on a section's own pages, not on
 * a record or form below them — the section's other pages as tabs.
 */
type Props = { title: string; description?: ReactNode; actions?: ReactNode; trail?: Crumb[]; children: ReactNode };

function useNav(): { path: string; groups: ResolvedGroup[] } {
  const page = usePage<{ auth: AuthProps }>();
  const path = page.url.split('?')[0];
  const groups = useMemo(() => navigationFor(accessFrom(page.props.auth)), [page.props.auth]);

  return { path, groups };
}

/** The trail of the current page: Dashboard / section / page, then `trail`. When the page is not in the navigation, `fallback` is used instead. */
function useCrumbs(trail: Crumb[], fallback: Crumb[] = []): Crumb[] {
  const { path, groups } = useNav();

  return useMemo(() => {
    const base = breadcrumbsFor(groups, path);

    return base.length === 0 ? [...fallback, ...trail] : [...base, ...trail];
    // `trail` and `fallback` are rebuilt every render; their labels are what matters.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [groups, path, [...fallback, ...trail].map((crumb) => crumb.label).join('|')]);
}

/**
 * Only the breadcrumb trail, for a page that draws its own heading (Phase
 * B32: Security, Support, platform backups), so every admin page shows
 * where it is.
 */
export function AdminCrumbs({ trail = [], fallback = [] }: { trail?: Crumb[]; fallback?: Crumb[] }) {
  return <Breadcrumbs items={useCrumbs(trail, fallback)} />;
}

/** UI-26: the section colour around a page that draws its own heading. */
export function useSectionTone(): string {
  const { path, groups } = useNav();

  return toneClass(sectionFor(groups, path)?.tone);
}

export default function AdminPage({ title, description, actions, trail = [], children }: Props) {
  const crumbs = useCrumbs(trail);
  const { path, groups } = useNav();
  const section = sectionFor(groups, path);
  const current = activeHref(groups, path);
  // Tabs on the section's own pages (a list, a setup page), not on a record or form below one.
  const onSectionPage = current !== null && current === path;
  const tabs = section && !section.hub && !section.platform && onSectionPage
    ? section.items.map((item) => ({ href: item.href, label: item.label, icon: NAV_ICONS[item.href], disabled: item.state !== 'open', reason: item.reason }))
    : [];
  const icon = (current && NAV_ICONS[current]) || (section?.hub === path ? NAV_ICONS[path] : undefined) || section?.icon;

  return (
    <AuthenticatedLayout>
      <div className={toneClass(section?.tone)}>
        <PageHeader title={title} description={description} actions={actions} crumbs={crumbs} icon={icon} below={<SectionTabs items={tabs} current={current} />} />
        {children}
      </div>
    </AuthenticatedLayout>
  );
}
