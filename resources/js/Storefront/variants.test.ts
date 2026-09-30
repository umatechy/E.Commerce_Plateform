import { describe, expect, it } from 'vitest';
import { findVariant, initialSelection, isSelectable } from './variants';
import type { Variant } from './types';

const variant = (id: string, options: Record<string, string>, purchasable = true): Variant => ({
  id,
  sku: id,
  options,
  price_minor: 1000,
  compare_at_minor: null,
  availability: purchasable ? 'in_stock' : 'out_of_stock',
  purchasable,
  image_id: null,
});

const variants = [
  variant('black-s', { color: 'black', size: 'S' }, false),
  variant('black-m', { color: 'black', size: 'M' }),
  variant('white-s', { color: 'white', size: 'S' }),
];
const axes = ['color', 'size'];

describe('variant selection', () => {
  it('starts from the first variant that can be bought', () => {
    expect(initialSelection(variants)).toEqual({ color: 'black', size: 'M' });
  });

  it('finds the variant only once every option is chosen', () => {
    expect(findVariant(variants, { color: 'white', size: 'S' }, axes)?.id).toBe('white-s');
    expect(findVariant(variants, { color: 'white' }, axes)).toBeNull();
    expect(findVariant(variants, { color: 'white', size: 'M' }, axes)).toBeNull(); // combination does not exist
  });

  it('greys out values that lead only to sold-out or missing variants', () => {
    // With black chosen, S is sold out and M can be bought.
    expect(isSelectable(variants, { color: 'black', size: 'M' }, 'size', 'S')).toBe(false);
    expect(isSelectable(variants, { color: 'black', size: 'M' }, 'size', 'M')).toBe(true);
    // Switching colour is judged against the other choices: white exists in S only.
    expect(isSelectable(variants, { color: 'black', size: 'M' }, 'color', 'white')).toBe(false);
    expect(isSelectable(variants, { color: 'black', size: 'S' }, 'color', 'white')).toBe(true);
  });
});
