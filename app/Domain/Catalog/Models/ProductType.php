<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

/**
 * Module 06 §6 "Product Types" — the module's own initial list, used
 * verbatim. Only Simple and Variable have concrete type-specific
 * behavior in B3 (see docs/development/b3-inspection-findings.md
 * "Scope Decision") — Digital/Service/Bundle are schema-ready (this
 * enum exists, the products table accepts these values) but have no
 * dedicated fields or workflow yet, so as not to invent behavior the
 * specification doesn't yet define for them.
 */
enum ProductType: string
{
    case Simple = 'simple';
    case Variable = 'variable';
    case Digital = 'digital';
    case Service = 'service';
    case Bundle = 'bundle';
}
