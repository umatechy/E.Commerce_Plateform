import { useState } from 'react';
import AdminPage from '@/Components/AdminPage';
import { SelectField } from '@/Components/ui/Form';
import { ButtonLink } from '@/Components/ui/Button';
import { Card, QueryState, StatCard } from '@/Components/ui/Page';
import { useAccess } from '@/lib/access';
import { useApi } from '@/lib/useApi';
import { DATE_FILTERS } from '@/lib/labels';

/**
 * Customers (Module 10).
 *
 * What the backend has today for staff is the customer report of Module
 * 22 (GET /api/v1/reports/customers) and a segment's member preview.
 * There is no staff API that lists or opens customers yet (gap G7,
 * Module 10 completion), so this page shows the real figures and says
 * plainly what is not available, instead of drawing a list it cannot
 * fill.
 */
type Report = { new_customers: number; customers_with_orders: number; repeat_customers: number; repeat_customer_rate_percent: number | null };

export default function Index() {
  const access = useAccess();
  const [range, setRange] = useState('this_month');
  const state = useApi<{ data: Report }>('/reports/customers', { date_filter: range });

  return (
    <AdminPage title="Customers" description="How many customers you gained and how many came back.">
      <div className="mb-4 w-48">
        <SelectField label="Period" value={range} onChange={setRange} options={DATE_FILTERS.filter((option) => option.value !== 'custom')} />
      </div>

      <QueryState state={state} lines={3}>
        {({ data }) => (
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="New customers" value={data.new_customers} />
            <StatCard label="Customers who ordered" value={data.customers_with_orders} />
            <StatCard label="Ordered more than once" value={data.repeat_customers} />
            <StatCard label="Repeat rate" value={data.repeat_customer_rate_percent === null ? null : `${data.repeat_customer_rate_percent}%`} hint="Of the customers who ordered in this period." />
          </div>
        )}
      </QueryState>

      <div className="mt-6 space-y-4">
        <Card title="Customer list">
          <p className="text-sm text-slate-700">
            A list of your customers with their details and order history is not available in the admin yet. It is planned with the customer management work
            (groups, notes, blocking and import).
          </p>
          <p className="mt-2 text-sm text-slate-700">Until then you can find a customer in these places:</p>
          <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-700">
            <li>The customer of an order is shown on the order's page.</li>
            <li>A segment's "See who matches" lists the customers in that segment, with name and email.</li>
          </ul>
          <div className="mt-3 flex flex-wrap gap-2">
            {access.can('orders.view') && <ButtonLink href="/orders" size="sm">Orders</ButtonLink>}
            {access.can('marketing.view') && <ButtonLink href="/segments" size="sm">Segments</ButtonLink>}
          </div>
        </Card>
      </div>
    </AdminPage>
  );
}
