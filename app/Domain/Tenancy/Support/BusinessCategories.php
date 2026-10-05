<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

/**
 * Phase B44 — what a store sells (Module 03 §14 store identity, Module 07
 * §105 "industry templates"). Chosen at sign-up or by Umar Techy staff when
 * they create the store. B45 builds a starter template (categories,
 * attributes, filters, theme) for each.
 *
 * A fixed list (never free text), so reports and templates can rely on it.
 */
final class BusinessCategories
{
    public const ALL = [
        'fashion' => 'Clothing & fashion',
        'footwear' => 'Shoes & footwear',
        'beauty' => 'Beauty & personal care',
        'fragrance' => 'Perfume & attar',
        'jewellery' => 'Jewellery & accessories',
        'electronics' => 'Mobiles & electronics',
        'home' => 'Home, kitchen & furniture',
        'grocery' => 'Grocery & daily needs',
        'food' => 'Food, bakery & sweets',
        'books' => 'Books & stationery',
        'kids' => 'Kids, toys & baby',
        'sports' => 'Sports & fitness',
        'handicrafts' => 'Handicrafts & art',
        'other' => 'Something else',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function label(?string $key): ?string
    {
        return $key === null ? null : (self::ALL[$key] ?? null);
    }
}
