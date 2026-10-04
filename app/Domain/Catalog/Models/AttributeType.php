<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

/**
 * Module 07 §29 "Attribute Types".
 *
 * Phase B41: `color` is its own type (§35) — a single choice whose values
 * carry a colour code, shown as swatches. A measurement (§34) is a number
 * with the attribute's `unit`; no unit conversion yet.
 */
enum AttributeType: string
{
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case Boolean = 'boolean';
    case Numeric = 'numeric';
    case Text = 'text';
    case Color = 'color';

    /** Types whose product values are chosen from the attribute's values. */
    public function hasValues(): bool
    {
        return in_array($this, [self::Select, self::MultiSelect, self::Color], true);
    }

    /** Types a customer can filter by (Module 07 §47): choices, yes/no and number ranges. */
    public function isFilterable(): bool
    {
        return $this !== self::Text;
    }
}
