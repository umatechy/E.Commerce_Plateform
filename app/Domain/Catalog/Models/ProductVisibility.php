<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

/** Module 06 §28 "Product Visibility" — the module's own suggested list. */
enum ProductVisibility: string
{
    case Public = 'public';
    case CatalogOnly = 'catalog_only';
    case SearchOnly = 'search_only';
    case Hidden = 'hidden';
    case Private = 'private';
    case Scheduled = 'scheduled';
}
