<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

use App\Domain\Tenancy\Models\Store;

/**
 * Module 16 §20 "Robots.txt" — Non-Negotiable: "ensure robots.txt does
 * not accidentally expose admin/customer account/checkout/private
 * APIs/internal routes." Server-controlled, fixed disallow list — no
 * client input of any kind reaches this output.
 */
final class RobotsService
{
    private const DISALLOWED_PATHS = ['/api/', '/customer/', '/cart', '/checkout', '/wishlist'];

    public function generate(Store $store): string
    {
        $lines = ['User-agent: *'];

        foreach (self::DISALLOWED_PATHS as $path) {
            $lines[] = "Disallow: {$path}";
        }

        $base = rtrim(config('seo.storefront_base_url'), '/');
        $lines[] = '';
        $lines[] = "Sitemap: {$base}/{$store->slug}/sitemap.xml";

        return implode("\n", $lines);
    }
}
