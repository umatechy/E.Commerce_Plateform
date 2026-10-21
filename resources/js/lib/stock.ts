import { adminFetch, idempotencyKey } from './adminApi';
import { readPage } from './useApi';

/**
 * UI-26 — the opening stock of a new product (no variants), in the store's
 * default warehouse: the stock record, then its opening count. Used where a
 * product is added (Add product, the dashboard quick start), so a product
 * can be sold the moment it is created. Throws when it could not be saved;
 * the product itself stays.
 */
export async function setOpeningStock(productInternalId: number, quantity: number, reason: string): Promise<void> {
  const warehouses = readPage<{ id: string; internal_id: number; is_default: boolean }>(await adminFetch('/warehouses')).rows;
  const warehouse = warehouses.find((w) => w.is_default) ?? warehouses[0];
  if (!warehouse) throw new Error('This store has no warehouse yet.');
  const record = await adminFetch<{ data: { id: string } }>('/inventory', { method: 'POST', body: { warehouse_id: warehouse.internal_id, product_id: productInternalId, product_variant_id: null } });
  await adminFetch(`/inventory/${record.data.id}/opening-stock`, { method: 'POST', body: { quantity, reason, idempotency_key: idempotencyKey() } });
}

/** A typed stock count: null when left empty, NaN when it is not a whole number of 0 or more. */
export function parseStock(text: string): number | null {
  const trimmed = text.trim();
  if (trimmed === '') return null;

  return /^\d{1,9}$/.test(trimmed) ? Number(trimmed) : Number.NaN;
}
