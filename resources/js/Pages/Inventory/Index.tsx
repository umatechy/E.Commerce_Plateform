import { useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import LoadingState from '@/Components/LoadingState';
import EmptyState from '@/Components/EmptyState';
import ErrorState from '@/Components/ErrorState';

/**
 * Module 08 "Inventory Frontend" — list + on-hand/reserved/available +
 * low-stock status + a minimal adjustment form. Reads/writes go through
 * /api/v1/inventory — server-authoritative in every case (this page
 * never computes or trusts a balance itself, only displays what the
 * API returns).
 */
type InventoryRow = {
  id: string;
  warehouse: { name: string };
  on_hand: number;
  reserved: number;
  available: number;
  is_low_stock: boolean;
  is_out_of_stock: boolean;
};

export default function Index() {
  const [rows, setRows] = useState<InventoryRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [adjusting, setAdjusting] = useState<string | null>(null);

  function load() {
    fetch('/api/v1/inventory')
      .then((r) => (r.ok ? r.json() : Promise.reject(r)))
      .then((body) => setRows(body.data))
      .catch(() => setError('Could not load inventory.'));
  }

  useEffect(load, []);

  function submitAdjustment(inventoryId: string, quantity: number, reason: string) {
    setAdjusting(inventoryId);
    fetch(`/api/v1/inventory/${inventoryId}/adjust`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ quantity, reason }),
    })
      .then((r) => (r.ok ? load() : Promise.reject(r)))
      .finally(() => setAdjusting(null));
  }

  return (
    <AuthenticatedLayout>
      <h1 className="text-lg font-semibold">Inventory</h1>

      {error && <ErrorState message={error} />}
      {!error && rows === null && <LoadingState />}
      {!error && rows !== null && rows.length === 0 && (
        <EmptyState title="No inventory records yet" description="Create one from a product's page." />
      )}

      {rows && rows.length > 0 && (
        <table className="mt-4 w-full text-sm">
          <thead>
            <tr className="border-b text-left text-gray-500">
              <th className="py-2">Warehouse</th>
              <th>On Hand</th>
              <th>Reserved</th>
              <th>Available</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} className="border-b">
                <td className="py-2">{row.warehouse.name}</td>
                <td>{row.on_hand}</td>
                <td>{row.reserved}</td>
                <td>{row.available}</td>
                <td>
                  {row.is_out_of_stock ? (
                    <span className="text-red-600">Out of stock</span>
                  ) : row.is_low_stock ? (
                    <span className="text-amber-600">Low stock</span>
                  ) : (
                    <span className="text-green-600">In stock</span>
                  )}
                </td>
                <td>
                  <button
                    disabled={adjusting === row.id}
                    className="text-gray-500 hover:text-gray-900"
                    onClick={() => {
                      const qty = Number(prompt('Adjustment quantity (use a negative number to decrease):', '0'));
                      const reason = prompt('Reason for this adjustment:') ?? '';
                      if (qty !== 0 && reason) submitAdjustment(row.id, qty, reason);
                    }}
                  >
                    Adjust
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </AuthenticatedLayout>
  );
}
