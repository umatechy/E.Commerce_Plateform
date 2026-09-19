<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

/**
 * Module 07 §29 "Attribute Types". The module also describes dedicated
 * Measurement and Color sub-types (§34–35) with unit/hex-specific
 * behavior — simplified in B3 to Numeric and Select respectively
 * (documented simplification, not silently done): a "color" attribute
 * is a Select attribute whose values happen to be color names, and a
 * "measurement" attribute is a Numeric attribute with a unit stored in
 * AttributeValue's metadata. Revisit if the storefront actually needs a
 * native color-swatch or unit-conversion UI.
 */
enum AttributeType: string
{
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case Boolean = 'boolean';
    case Numeric = 'numeric';
    case Text = 'text';
}
