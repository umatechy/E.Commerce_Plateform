<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Models;

/**
 * Module 31 §15 "API Scopes" — the ONE fixed, server-authoritative
 * list. Deliberately read-only in B18's scope (see
 * docs/development/b18-inspection-findings.md "Architectural Decision
 * — Scope"). Every ApiKey.scopes value is validated against this enum
 * before storage — there is no code path that accepts an unrecognized
 * scope string.
 */
enum ApiScope: string
{
    case ProductsRead = 'products:read';
    case CategoriesRead = 'categories:read';
    case OrdersRead = 'orders:read';
    case CustomersRead = 'customers:read';
    case InventoryRead = 'inventory:read';
}
