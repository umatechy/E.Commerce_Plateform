<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

/** Module 22 §16-33 — only the report types B12 actually implements (see docs/development/b12-inspection-findings.md "Scope Decision"). */
enum ReportType: string
{
    case Sales = 'sales';
    case Products = 'products';
    case Customers = 'customers';
    case Payments = 'payments';
    case Shipping = 'shipping';
    case Promotions = 'promotions';
    case Marketing = 'marketing';
    case Notifications = 'notifications';
    case Inventory = 'inventory';
}
