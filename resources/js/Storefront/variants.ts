import type { Variant } from './types';

export type Selection = Record<string, string>;

/** The variant matching every chosen option, if the choice is complete. */
export function findVariant(variants: Variant[], selection: Selection, axes: string[]): Variant | null {
  if (axes.some((axis) => !selection[axis])) return null;

  return variants.find((variant) => axes.every((axis) => variant.options[axis] === selection[axis])) ?? null;
}

/**
 * Whether choosing `value` for `axis`, keeping the other choices, leads
 * to at least one variant that can be bought — used to grey out
 * combinations that do not exist or are sold out.
 */
export function isSelectable(variants: Variant[], selection: Selection, axis: string, value: string): boolean {
  return variants.some(
    (variant) =>
      variant.purchasable &&
      variant.options[axis] === value &&
      Object.entries(selection).every(([other, chosen]) => other === axis || !chosen || variant.options[other] === chosen),
  );
}

/** Start from the first purchasable variant (or the first one). */
export function initialSelection(variants: Variant[]): Selection {
  const start = variants.find((variant) => variant.purchasable) ?? variants[0];

  return start ? { ...start.options } : {};
}
