/**
 * Amounts travel in minor units (ADR-003). They are converted with the
 * currency's own number of decimals (JPY has none, KWD has three), never
 * an assumed 2.
 */
export function formatMoney(minor: number, currency: string): string {
  const format = new Intl.NumberFormat(undefined, { style: 'currency', currency });
  const digits = format.resolvedOptions().maximumFractionDigits ?? 2;

  return format.format(minor / 10 ** digits);
}
