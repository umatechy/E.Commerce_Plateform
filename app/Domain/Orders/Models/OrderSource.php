<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

/** Module 09 §6 "Order Sources" — the module's own list. */
enum OrderSource: string
{
    case Storefront = 'storefront';
    case Pwa = 'pwa';
    case MobileApp = 'mobile_app';
    case Admin = 'admin';
    case Pos = 'pos';
    case Api = 'api';
    case Marketplace = 'marketplace';
    case SocialCommerce = 'social_commerce';
    case Replacement = 'replacement'; // Module 09 §54 (Phase B33): made from a return by staff, never by a shopper or the API
}
