import { ReactNode, useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Breadcrumbs, PageHeader, type Crumb } from '@/Components/ui/Page';
import { accessFrom, type AuthProps } from '@/lib/access';
import { breadcrumbsFor, navigationFor } from '@/lib/adminNav';

/**
 * An admin page: the shell, the breadcrumb trail and the page heading.
 * The trail comes from the navigation (Dashboard / section / page);
 * `trail` adds the levels below the page, such as a product's name.
 */
type Props = { title: string; description?: ReactNode; actions?: ReactNode; trail?: Crumb[]; children: ReactNode };

/** The trail of the current page: Dashboard / section / page, then `trail`. When the page is not in the navigation, `fallback` is used instead. */
function useCrumbs(trail: Crumb[], fallback: Crumb[] = []): Crumb[] {
  const page = usePage<{ auth: AuthProps }>();
  const path = page.url.split('?')[0];

  return useMemo(() => {
    const base = breadcrumbsFor(navigationFor(accessFrom(page.props.auth)), path);

    return base.length === 0 ? [...fallback, ...trail] : [...base, ...trail];
    // `trail` and `fallback` are rebuilt every render; their labels are what matters.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page.props.auth, path, [...fallback, ...trail].map((crumb) => crumb.label).join('|')]);
}

/**
 * Only the breadcrumb trail, for a page that draws its own heading (Phase
 * B32: Security, Support, platform backups), so every admin page shows
 * where it is.
 */
export function AdminCrumbs({ trail = [], fallback = [] }: { trail?: Crumb[]; fallback?: Crumb[] }) {
  return <Breadcrumbs items={useCrumbs(trail, fallback)} />;
}

export default function AdminPage({ title, description, actions, trail = [], children }: Props) {
  const crumbs = useCrumbs(trail);

  return (
    <AuthenticatedLayout>
      <PageHeader title={title} description={description} actions={actions} crumbs={crumbs} />
      {children}
    </AuthenticatedLayout>
  );
}
