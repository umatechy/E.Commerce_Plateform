import { ReactNode, useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageHeader, type Crumb } from '@/Components/ui/Page';
import { accessFrom, type AuthProps } from '@/lib/access';
import { breadcrumbsFor, navigationFor } from '@/lib/adminNav';

/**
 * An admin page: the shell, the breadcrumb trail and the page heading.
 * The trail comes from the navigation (Dashboard / section / page);
 * `trail` adds the levels below the page, such as a product's name.
 */
type Props = { title: string; description?: ReactNode; actions?: ReactNode; trail?: Crumb[]; children: ReactNode };

export default function AdminPage({ title, description, actions, trail = [], children }: Props) {
  const page = usePage<{ auth: AuthProps }>();
  const path = page.url.split('?')[0];
  const crumbs = useMemo(() => {
    const base = breadcrumbsFor(navigationFor(accessFrom(page.props.auth)), path);

    return [...base, ...trail];
    // `trail` is rebuilt every render; its labels are what matters.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page.props.auth, path, trail.map((crumb) => crumb.label).join('|')]);

  return (
    <AuthenticatedLayout>
      <PageHeader title={title} description={description} actions={actions} crumbs={crumbs} />
      {children}
    </AuthenticatedLayout>
  );
}
