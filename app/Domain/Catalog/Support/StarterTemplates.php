<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

/**
 * Phase B45 — Module 07 §20, §101, §105–106; Module 03 §58; gap G23: the
 * platform's ready-made starting structures, one per business category
 * (BusinessCategories), GLOBAL platform data like ThemeCatalog.
 *
 * A template is structure only: categories (with an SEO description that
 * the category page uses until the owner writes their own, SeoResolver),
 * attributes and their values, which attributes apply to each category and
 * which are storefront filters, an attribute set, a suggested theme and the
 * default product order. Applying it copies everything into the store's own
 * data (§101 "tenant-owned copies") and only ever adds (§103).
 *
 * Deliberately absent: products, prices, reviews, stock or policies — a
 * template never fills a store with things that look real but are not.
 * Brands are offered only where shoppers choose by brand (mobiles and
 * electronics) and only when the owner asks for them.
 *
 * Attributes listed on a top-level category also apply to its
 * sub-categories (CategoryAttributes::effective()). Flags: `filter`
 * (customers can filter by it) and `required` (needed on its products).
 *
 * Raising a template's `version` is how a changed template is told apart
 * in the applications history; re-applying adds only what is missing.
 *
 * @phpstan-type AttributeDef array{key: string, name: string, type: string, group?: string, unit?: string, values?: list<string|array{0: string, 1: string}>}
 * @phpstan-type CategoryDef array{name: string, description?: string, attributes?: array<string, string>, children?: list<string>}
 * @phpstan-type TemplateDef array{name: string, version: int, summary: string, themes: list<string>, default_sort: string, attributes: list<AttributeDef>, categories: list<CategoryDef>, brands?: list<string>}
 */
final class StarterTemplates
{
    /** Shared colour list (name, hex) — used by several templates. */
    private const COLOURS = [
        ['Black', '#000000'], ['White', '#FFFFFF'], ['Grey', '#808080'], ['Navy', '#1F2A44'], ['Blue', '#1D4ED8'],
        ['Red', '#B91C1C'], ['Maroon', '#7F1D1D'], ['Pink', '#EC4899'], ['Green', '#15803D'], ['Olive', '#556B2F'],
        ['Beige', '#D6C7A1'], ['Brown', '#7C4A1E'], ['Mustard', '#D4A017'], ['Purple', '#6B21A8'],
    ];

    private const CLOTHING_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];

    /** @return array<string, TemplateDef> */
    private static function all(): array
    {
        $colour = ['key' => 'color', 'name' => 'Colour', 'type' => 'color', 'values' => self::COLOURS];
        $metalColour = ['key' => 'color', 'name' => 'Colour', 'type' => 'color', 'values' => [['Gold', '#C9A227'], ['Silver', '#C0C0C0'], ['Rose gold', '#B76E79'], ['Black', '#000000'], ['Multicolour', '#808080']]];
        $material = static fn (array $values): array => ['key' => 'material', 'name' => 'Material', 'type' => 'select', 'group' => 'Material', 'values' => $values];

        return [
            'fashion' => [
                'name' => 'Clothing & fashion', 'version' => 1,
                'summary' => 'Women, men, kids and accessories, with size, colour, fabric, fit and stitching filters.',
                'themes' => ['boutique', 'modern', 'default'], 'default_sort' => 'newest',
                'attributes' => [
                    ['key' => 'size', 'name' => 'Size', 'type' => 'select', 'group' => 'Fit', 'values' => self::CLOTHING_SIZES],
                    $colour,
                    ['key' => 'fabric', 'name' => 'Fabric', 'type' => 'select', 'group' => 'Material', 'values' => ['Cotton', 'Lawn', 'Khaddar', 'Linen', 'Cambric', 'Silk', 'Chiffon', 'Wool', 'Polyester']],
                    ['key' => 'fit', 'name' => 'Fit', 'type' => 'select', 'group' => 'Fit', 'values' => ['Regular', 'Slim', 'Loose']],
                    ['key' => 'stitching', 'name' => 'Stitching', 'type' => 'select', 'values' => ['Stitched', 'Unstitched']],
                    ['key' => 'pieces', 'name' => 'Pieces', 'type' => 'select', 'values' => ['1 piece', '2 piece', '3 piece']],
                    ['key' => 'care', 'name' => 'Care instructions', 'type' => 'text', 'group' => 'Care'],
                ],
                'categories' => [
                    ['name' => 'Women', 'description' => 'Kurtas, suits, dupattas and everyday wear for women.', 'attributes' => ['size' => 'filter', 'color' => 'filter', 'fabric' => 'filter', 'stitching' => 'filter', 'pieces' => 'filter', 'care' => ''], 'children' => ['Kurtas & kurtis', 'Unstitched suits', 'Dupattas & shawls', 'Western wear']],
                    ['name' => 'Men', 'description' => 'Shalwar kameez, kurtas, shirts and waistcoats for men.', 'attributes' => ['size' => 'filter', 'color' => 'filter', 'fabric' => 'filter', 'fit' => 'filter', 'stitching' => 'filter', 'care' => ''], 'children' => ['Shalwar kameez', 'Kurtas', 'Shirts & T-shirts', 'Waistcoats']],
                    ['name' => 'Kids', 'description' => 'Clothes for girls and boys.', 'attributes' => ['size' => 'filter', 'color' => 'filter', 'fabric' => 'filter'], 'children' => ['Girls', 'Boys']],
                    ['name' => 'Accessories', 'description' => 'Bags, belts, caps and scarves.', 'attributes' => ['color' => 'filter'], 'children' => ['Bags', 'Belts', 'Caps & scarves']],
                ],
            ],
            'footwear' => [
                'name' => 'Shoes & footwear', 'version' => 1,
                'summary' => 'Men, women and kids footwear, with shoe size, colour, upper material and closure filters.',
                'themes' => ['modern', 'default'], 'default_sort' => 'newest',
                'attributes' => [
                    ['key' => 'shoe_size', 'name' => 'Shoe size (UK)', 'type' => 'select', 'group' => 'Fit', 'values' => ['3', '4', '5', '6', '7', '8', '9', '10', '11', '12']],
                    $colour,
                    ['key' => 'upper_material', 'name' => 'Upper material', 'type' => 'select', 'group' => 'Material', 'values' => ['Leather', 'Synthetic leather', 'Suede', 'Canvas', 'Mesh']],
                    ['key' => 'sole', 'name' => 'Sole', 'type' => 'select', 'group' => 'Material', 'values' => ['Rubber', 'PU', 'EVA', 'Leather']],
                    ['key' => 'closure', 'name' => 'Closure', 'type' => 'select', 'values' => ['Lace-up', 'Slip-on', 'Velcro', 'Buckle']],
                ],
                'categories' => [
                    ['name' => 'Men', 'description' => 'Sneakers, formal shoes, sandals and chappals for men.', 'attributes' => ['shoe_size' => 'filter', 'color' => 'filter', 'upper_material' => 'filter', 'sole' => '', 'closure' => 'filter'], 'children' => ['Sneakers', 'Formal shoes', 'Sandals & chappals']],
                    ['name' => 'Women', 'description' => 'Heels, flats, khussas and sneakers for women.', 'attributes' => ['shoe_size' => 'filter', 'color' => 'filter', 'upper_material' => 'filter', 'sole' => '', 'closure' => 'filter'], 'children' => ['Heels', 'Flats & khussas', 'Sneakers']],
                    ['name' => 'Kids', 'description' => 'Shoes for girls and boys.', 'attributes' => ['shoe_size' => 'filter', 'color' => 'filter', 'closure' => 'filter']],
                    ['name' => 'Socks & shoe care', 'description' => 'Socks, polish and care kits.', 'attributes' => ['color' => 'filter']],
                ],
            ],
            'beauty' => [
                'name' => 'Beauty & personal care', 'version' => 1,
                'summary' => 'Skincare, makeup, hair, bath and grooming, with skin type, concern and size filters.',
                'themes' => ['boutique', 'modern', 'default'], 'default_sort' => 'best_selling',
                'attributes' => [
                    ['key' => 'skin_type', 'name' => 'Skin type', 'type' => 'multi_select', 'values' => ['Normal', 'Dry', 'Oily', 'Combination', 'Sensitive']],
                    ['key' => 'concern', 'name' => 'Concern', 'type' => 'multi_select', 'values' => ['Acne', 'Dryness', 'Dark spots', 'Dullness', 'Fine lines', 'Sun protection']],
                    ['key' => 'hair_type', 'name' => 'Hair type', 'type' => 'multi_select', 'values' => ['Straight', 'Wavy', 'Curly', 'Dry', 'Oily', 'Coloured']],
                    ['key' => 'size_ml', 'name' => 'Size', 'type' => 'numeric', 'unit' => 'ml'],
                    ['key' => 'fragrance_free', 'name' => 'Fragrance free', 'type' => 'boolean'],
                    ['key' => 'ingredients', 'name' => 'Ingredients', 'type' => 'text', 'group' => 'Details'],
                ],
                'categories' => [
                    ['name' => 'Skincare', 'description' => 'Cleansers, moisturisers, sunscreen and serums.', 'attributes' => ['skin_type' => 'filter', 'concern' => 'filter', 'size_ml' => 'filter', 'fragrance_free' => 'filter', 'ingredients' => ''], 'children' => ['Cleansers', 'Moisturisers', 'Sunscreen', 'Serums']],
                    ['name' => 'Makeup', 'description' => 'Face, eye and lip makeup.', 'attributes' => ['skin_type' => 'filter', 'ingredients' => ''], 'children' => ['Face', 'Eyes', 'Lips']],
                    ['name' => 'Hair care', 'description' => 'Shampoos, conditioners, oils and styling.', 'attributes' => ['hair_type' => 'filter', 'size_ml' => 'filter', 'ingredients' => '']],
                    ['name' => 'Bath & body', 'description' => 'Soaps, body wash and lotions.', 'attributes' => ['skin_type' => 'filter', 'size_ml' => 'filter', 'fragrance_free' => 'filter']],
                    ['name' => "Men's grooming", 'description' => 'Shaving, beard care and grooming.', 'attributes' => ['skin_type' => 'filter', 'size_ml' => 'filter']],
                ],
            ],
            'fragrance' => [
                'name' => 'Perfume & attar', 'version' => 1,
                'summary' => 'Attars, perfumes, mists, gift sets and bakhoor, with scent family, strength, size and gender filters.',
                'themes' => ['boutique', 'modern', 'default'], 'default_sort' => 'best_selling',
                'attributes' => [
                    ['key' => 'volume_ml', 'name' => 'Volume', 'type' => 'numeric', 'unit' => 'ml'],
                    ['key' => 'concentration', 'name' => 'Type', 'type' => 'select', 'values' => ['Attar (oil)', 'Eau de parfum', 'Eau de toilette', 'Body mist']],
                    ['key' => 'scent_family', 'name' => 'Scent family', 'type' => 'multi_select', 'values' => ['Floral', 'Woody', 'Oud', 'Musk', 'Amber', 'Citrus', 'Fresh', 'Spicy', 'Sweet']],
                    ['key' => 'gender', 'name' => 'For', 'type' => 'select', 'values' => ['Men', 'Women', 'Unisex']],
                    ['key' => 'alcohol_free', 'name' => 'Alcohol free', 'type' => 'boolean'],
                    ['key' => 'notes', 'name' => 'Notes', 'type' => 'text', 'group' => 'Details'],
                ],
                'categories' => [
                    ['name' => 'Attar', 'description' => 'Concentrated perfume oils.', 'attributes' => ['volume_ml' => 'filter', 'scent_family' => 'filter', 'gender' => 'filter', 'alcohol_free' => 'filter', 'notes' => '']],
                    ['name' => 'Perfumes', 'description' => 'Eau de parfum and eau de toilette.', 'attributes' => ['volume_ml' => 'filter', 'concentration' => 'filter', 'scent_family' => 'filter', 'gender' => 'filter', 'notes' => '']],
                    ['name' => 'Body mists', 'description' => 'Light, fresh body sprays.', 'attributes' => ['volume_ml' => 'filter', 'scent_family' => 'filter', 'gender' => 'filter']],
                    ['name' => 'Gift sets', 'description' => 'Fragrance gift boxes.', 'attributes' => ['scent_family' => 'filter', 'gender' => 'filter']],
                    ['name' => 'Bakhoor & incense', 'description' => 'Bakhoor, oud chips and burners.', 'attributes' => ['scent_family' => 'filter']],
                ],
            ],
            'jewellery' => [
                'name' => 'Jewellery & accessories', 'version' => 1,
                'summary' => 'Earrings, necklaces, bangles, rings, bridal sets and watches, with metal, stone, colour and occasion filters.',
                'themes' => ['boutique', 'modern', 'default'], 'default_sort' => 'newest',
                'attributes' => [
                    ['key' => 'metal', 'name' => 'Metal', 'type' => 'select', 'group' => 'Material', 'values' => ['Gold', 'Silver', 'Gold plated', 'Silver plated', 'Brass', 'Alloy']],
                    ['key' => 'stone', 'name' => 'Stone', 'type' => 'multi_select', 'group' => 'Material', 'values' => ['None', 'Zircon', 'Pearl', 'Kundan', 'Crystal', 'Gemstone']],
                    $metalColour,
                    ['key' => 'occasion', 'name' => 'Occasion', 'type' => 'multi_select', 'values' => ['Daily wear', 'Party', 'Bridal']],
                    ['key' => 'weight_g', 'name' => 'Weight', 'type' => 'numeric', 'unit' => 'g'],
                ],
                'categories' => [
                    ['name' => 'Earrings', 'description' => 'Studs, jhumkas and drops.', 'attributes' => ['metal' => 'filter', 'stone' => 'filter', 'color' => 'filter', 'occasion' => 'filter', 'weight_g' => '']],
                    ['name' => 'Necklaces & sets', 'description' => 'Necklaces, chains and matching sets.', 'attributes' => ['metal' => 'filter', 'stone' => 'filter', 'color' => 'filter', 'occasion' => 'filter', 'weight_g' => '']],
                    ['name' => 'Bangles & bracelets', 'description' => 'Bangles, kangans and bracelets.', 'attributes' => ['metal' => 'filter', 'stone' => 'filter', 'color' => 'filter', 'occasion' => 'filter']],
                    ['name' => 'Rings', 'description' => 'Rings for every day and special days.', 'attributes' => ['metal' => 'filter', 'stone' => 'filter', 'color' => 'filter', 'weight_g' => '']],
                    ['name' => 'Bridal sets', 'description' => 'Complete bridal jewellery.', 'attributes' => ['metal' => 'filter', 'stone' => 'filter', 'color' => 'filter']],
                    ['name' => 'Watches & hair accessories', 'description' => 'Watches, clips and hair pins.', 'attributes' => ['color' => 'filter']],
                ],
            ],
            'electronics' => [
                'name' => 'Mobiles & electronics', 'version' => 1,
                'summary' => 'Phones, tablets, laptops, smart watches and accessories, with storage, RAM, condition and PTA filters, and brands if you want them.',
                'themes' => ['modern', 'bold', 'default'], 'default_sort' => 'newest',
                'attributes' => [
                    ['key' => 'storage', 'name' => 'Storage', 'type' => 'select', 'group' => 'Specifications', 'values' => ['32 GB', '64 GB', '128 GB', '256 GB', '512 GB', '1 TB']],
                    ['key' => 'ram', 'name' => 'RAM', 'type' => 'select', 'group' => 'Specifications', 'values' => ['2 GB', '3 GB', '4 GB', '6 GB', '8 GB', '12 GB', '16 GB', '32 GB']],
                    ['key' => 'screen_size', 'name' => 'Screen size', 'type' => 'numeric', 'group' => 'Specifications', 'unit' => 'inch'],
                    $colour,
                    ['key' => 'condition', 'name' => 'Condition', 'type' => 'select', 'values' => ['New', 'Open box', 'Used', 'Refurbished']],
                    ['key' => 'pta_approved', 'name' => 'PTA approved', 'type' => 'boolean'],
                    ['key' => 'warranty', 'name' => 'Warranty', 'type' => 'select', 'values' => ['No warranty', 'Shop warranty', 'Brand warranty']],
                ],
                'categories' => [
                    ['name' => 'Mobile phones', 'description' => 'Smartphones and feature phones.', 'attributes' => ['storage' => 'filter', 'ram' => 'filter', 'color' => 'filter', 'condition' => 'filter,required', 'pta_approved' => 'filter', 'warranty' => 'filter', 'screen_size' => '']],
                    ['name' => 'Tablets', 'description' => 'Tablets and e-readers.', 'attributes' => ['storage' => 'filter', 'ram' => 'filter', 'screen_size' => 'filter', 'condition' => 'filter,required', 'warranty' => 'filter']],
                    ['name' => 'Laptops', 'description' => 'Laptops and notebooks.', 'attributes' => ['ram' => 'filter', 'storage' => 'filter', 'screen_size' => 'filter', 'condition' => 'filter,required', 'warranty' => 'filter']],
                    ['name' => 'Smart watches', 'description' => 'Smart watches and fitness bands.', 'attributes' => ['color' => 'filter', 'condition' => 'filter', 'warranty' => 'filter']],
                    ['name' => 'Accessories', 'description' => 'Chargers, earphones, covers and power banks.', 'attributes' => ['color' => 'filter', 'warranty' => 'filter'], 'children' => ['Chargers & cables', 'Earphones & headphones', 'Covers & protectors', 'Power banks']],
                ],
                'brands' => ['Samsung', 'Apple', 'Xiaomi', 'Infinix', 'Tecno', 'Oppo', 'Vivo', 'Realme'],
            ],
            'home' => [
                'name' => 'Home, kitchen & furniture', 'version' => 1,
                'summary' => 'Kitchen, décor, bedding, furniture and cleaning, with material, colour and room filters.',
                'themes' => ['minimal', 'modern', 'default'], 'default_sort' => 'newest',
                'attributes' => [
                    $material(['Wood', 'Metal', 'Steel', 'Ceramic', 'Glass', 'Plastic', 'Cotton', 'Marble']),
                    $colour,
                    ['key' => 'room', 'name' => 'Room', 'type' => 'multi_select', 'values' => ['Living room', 'Bedroom', 'Kitchen', 'Bathroom', 'Outdoor']],
                    ['key' => 'set_pieces', 'name' => 'Pieces in the set', 'type' => 'numeric'],
                    ['key' => 'dimensions', 'name' => 'Dimensions', 'type' => 'text', 'group' => 'Details'],
                ],
                'categories' => [
                    ['name' => 'Kitchen & dining', 'description' => 'Cookware, dinnerware and storage.', 'attributes' => ['material' => 'filter', 'color' => 'filter', 'set_pieces' => 'filter', 'dimensions' => ''], 'children' => ['Cookware', 'Dinnerware', 'Storage & containers']],
                    ['name' => 'Home décor', 'description' => 'Wall décor, cushions and lighting.', 'attributes' => ['material' => 'filter', 'color' => 'filter', 'room' => 'filter', 'dimensions' => ''], 'children' => ['Wall décor', 'Cushions & throws', 'Lighting']],
                    ['name' => 'Bedding & bath', 'description' => 'Bed sheets, towels and bath mats.', 'attributes' => ['material' => 'filter', 'color' => 'filter', 'dimensions' => '']],
                    ['name' => 'Furniture', 'description' => 'Tables, chairs, beds and storage.', 'attributes' => ['material' => 'filter', 'color' => 'filter', 'room' => 'filter', 'dimensions' => '']],
                    ['name' => 'Cleaning & laundry', 'description' => 'Everything to keep the home clean.', 'attributes' => ['material' => 'filter']],
                ],
            ],
            'grocery' => [
                'name' => 'Grocery & daily needs', 'version' => 1,
                'summary' => 'Fresh food, staples, spices, drinks, snacks and household items, with weight, diet and storage filters.',
                'themes' => ['default', 'modern'], 'default_sort' => 'best_selling',
                'attributes' => [
                    ['key' => 'net_weight', 'name' => 'Net weight', 'type' => 'numeric', 'unit' => 'g'],
                    ['key' => 'volume_ml', 'name' => 'Volume', 'type' => 'numeric', 'unit' => 'ml'],
                    ['key' => 'dietary', 'name' => 'Dietary', 'type' => 'multi_select', 'values' => ['Sugar free', 'Gluten free', 'Organic', 'Vegetarian']],
                    ['key' => 'keep', 'name' => 'Keep', 'type' => 'select', 'values' => ['Room temperature', 'Chilled', 'Frozen']],
                    ['key' => 'ingredients', 'name' => 'Ingredients', 'type' => 'text', 'group' => 'Details'],
                ],
                'categories' => [
                    ['name' => 'Fruits & vegetables', 'description' => 'Fresh fruit and vegetables.', 'attributes' => ['net_weight' => 'filter', 'keep' => 'filter']],
                    ['name' => 'Dairy & eggs', 'description' => 'Milk, yoghurt, butter, cheese and eggs.', 'attributes' => ['net_weight' => 'filter', 'volume_ml' => 'filter', 'keep' => 'filter']],
                    ['name' => 'Rice, flour & pulses', 'description' => 'Rice, atta, daal and grains.', 'attributes' => ['net_weight' => 'filter', 'dietary' => 'filter']],
                    ['name' => 'Cooking oil & ghee', 'description' => 'Oil, ghee and banaspati.', 'attributes' => ['volume_ml' => 'filter']],
                    ['name' => 'Spices & masala', 'description' => 'Whole spices and ready masala mixes.', 'attributes' => ['net_weight' => 'filter', 'ingredients' => '']],
                    ['name' => 'Tea & beverages', 'description' => 'Tea, coffee, juices and drinks.', 'attributes' => ['net_weight' => 'filter', 'volume_ml' => 'filter', 'dietary' => 'filter']],
                    ['name' => 'Snacks & biscuits', 'description' => 'Biscuits, chips and namkeen.', 'attributes' => ['net_weight' => 'filter', 'dietary' => 'filter', 'ingredients' => '']],
                    ['name' => 'Household & cleaning', 'description' => 'Detergents, dishwash and cleaners.', 'attributes' => ['volume_ml' => 'filter', 'net_weight' => 'filter']],
                ],
            ],
            'food' => [
                'name' => 'Food, bakery & sweets', 'version' => 1,
                'summary' => 'Cakes, mithai, bakery, cookies, savouries and gift boxes, with weight, flavour, serving and diet filters.',
                'themes' => ['default', 'modern', 'boutique'], 'default_sort' => 'best_selling',
                'attributes' => [
                    ['key' => 'net_weight', 'name' => 'Weight', 'type' => 'numeric', 'unit' => 'g'],
                    ['key' => 'flavour', 'name' => 'Flavour', 'type' => 'select', 'values' => ['Chocolate', 'Vanilla', 'Strawberry', 'Pineapple', 'Pistachio', 'Coffee', 'Mixed']],
                    ['key' => 'serves', 'name' => 'Serves', 'type' => 'numeric', 'unit' => 'people'],
                    ['key' => 'eggless', 'name' => 'Eggless', 'type' => 'boolean'],
                    ['key' => 'sugar_free', 'name' => 'Sugar free', 'type' => 'boolean'],
                    ['key' => 'shelf_life', 'name' => 'Best before', 'type' => 'text', 'group' => 'Details'],
                ],
                'categories' => [
                    ['name' => 'Cakes', 'description' => 'Birthday, celebration and tea cakes.', 'attributes' => ['net_weight' => 'filter', 'flavour' => 'filter', 'serves' => 'filter', 'eggless' => 'filter', 'shelf_life' => '']],
                    ['name' => 'Mithai & sweets', 'description' => 'Traditional mithai by weight and in boxes.', 'attributes' => ['net_weight' => 'filter', 'sugar_free' => 'filter', 'shelf_life' => '']],
                    ['name' => 'Bakery & bread', 'description' => 'Bread, buns and pastries.', 'attributes' => ['net_weight' => 'filter', 'eggless' => 'filter', 'shelf_life' => '']],
                    ['name' => 'Cookies & rusks', 'description' => 'Cookies, biscuits and rusks.', 'attributes' => ['net_weight' => 'filter', 'flavour' => 'filter', 'sugar_free' => 'filter', 'shelf_life' => '']],
                    ['name' => 'Savoury & snacks', 'description' => 'Samosas, patties, nimko and more.', 'attributes' => ['net_weight' => 'filter', 'serves' => 'filter']],
                    ['name' => 'Gift boxes', 'description' => 'Boxes for Eid, weddings and gifts.', 'attributes' => ['net_weight' => 'filter', 'sugar_free' => 'filter']],
                ],
            ],
            'books' => [
                'name' => 'Books & stationery', 'version' => 1,
                'summary' => 'Fiction, non-fiction, religious, children’s and school books plus stationery, with language, format and age filters.',
                'themes' => ['minimal', 'default'], 'default_sort' => 'newest',
                'attributes' => [
                    ['key' => 'author', 'name' => 'Author', 'type' => 'text', 'group' => 'Book details'],
                    ['key' => 'publisher', 'name' => 'Publisher', 'type' => 'text', 'group' => 'Book details'],
                    ['key' => 'language', 'name' => 'Language', 'type' => 'select', 'group' => 'Book details', 'values' => ['Urdu', 'English', 'Arabic', 'Bilingual']],
                    ['key' => 'format', 'name' => 'Format', 'type' => 'select', 'group' => 'Book details', 'values' => ['Paperback', 'Hardcover']],
                    ['key' => 'pages', 'name' => 'Pages', 'type' => 'numeric', 'group' => 'Book details'],
                    ['key' => 'reader_age', 'name' => 'Reader age', 'type' => 'select', 'values' => ['Children', 'Teens', 'Adults']],
                    $colour,
                ],
                'categories' => [
                    ['name' => 'Fiction', 'description' => 'Novels and short stories.', 'attributes' => ['author' => '', 'publisher' => '', 'language' => 'filter', 'format' => 'filter', 'pages' => '']],
                    ['name' => 'Non-fiction', 'description' => 'History, biography, self-help and more.', 'attributes' => ['author' => '', 'publisher' => '', 'language' => 'filter', 'format' => 'filter', 'pages' => '']],
                    ['name' => 'Religious & Islamic', 'description' => 'Quran, tafseer, hadith and Islamic books.', 'attributes' => ['author' => '', 'publisher' => '', 'language' => 'filter', 'format' => 'filter']],
                    ['name' => "Children's books", 'description' => 'Story books, activity books and early learning.', 'attributes' => ['author' => '', 'language' => 'filter', 'reader_age' => 'filter', 'format' => 'filter']],
                    ['name' => 'School & exam books', 'description' => 'Textbooks, guides and exam preparation.', 'attributes' => ['publisher' => '', 'language' => 'filter']],
                    ['name' => 'Stationery', 'description' => 'Notebooks, pens and art supplies.', 'attributes' => ['color' => 'filter'], 'children' => ['Notebooks & paper', 'Pens & pencils', 'Art supplies']],
                ],
            ],
            'kids' => [
                'name' => 'Kids, toys & baby', 'version' => 1,
                'summary' => 'Toys, baby care, kids clothing, feeding and school supplies, with age, gender and colour filters.',
                'themes' => ['bold', 'modern', 'default'], 'default_sort' => 'best_selling',
                'attributes' => [
                    ['key' => 'age_group', 'name' => 'Age', 'type' => 'select', 'values' => ['0–6 months', '6–12 months', '1–2 years', '3–5 years', '6–8 years', '9–12 years']],
                    ['key' => 'kids_for', 'name' => 'For', 'type' => 'select', 'values' => ['Girls', 'Boys', 'Unisex']],
                    $colour,
                    $material(['Cotton', 'Plastic', 'Wood', 'Fabric', 'Silicone']),
                    ['key' => 'needs_batteries', 'name' => 'Needs batteries', 'type' => 'boolean'],
                ],
                'categories' => [
                    ['name' => 'Toys & games', 'description' => 'Toys, puzzles and games by age.', 'attributes' => ['age_group' => 'filter', 'kids_for' => 'filter', 'material' => 'filter', 'needs_batteries' => 'filter']],
                    ['name' => 'Baby care', 'description' => 'Diapers, wipes, bath and skin care for babies.', 'attributes' => ['age_group' => 'filter']],
                    ['name' => 'Kids clothing', 'description' => 'Clothes for girls and boys.', 'attributes' => ['age_group' => 'filter', 'kids_for' => 'filter', 'color' => 'filter', 'material' => 'filter']],
                    ['name' => 'Feeding & nursery', 'description' => 'Bottles, feeding sets and nursery items.', 'attributes' => ['age_group' => 'filter', 'material' => 'filter']],
                    ['name' => 'School supplies', 'description' => 'Bags, lunch boxes and stationery.', 'attributes' => ['color' => 'filter', 'kids_for' => 'filter']],
                ],
            ],
            'sports' => [
                'name' => 'Sports & fitness', 'version' => 1,
                'summary' => 'Cricket, football, gym, racket sports, sportswear and cycling, with sport, size, colour and weight filters.',
                'themes' => ['bold', 'modern', 'default'], 'default_sort' => 'best_selling',
                'attributes' => [
                    ['key' => 'sport', 'name' => 'Sport', 'type' => 'multi_select', 'values' => ['Cricket', 'Football', 'Hockey', 'Badminton', 'Tennis', 'Gym & fitness', 'Running', 'Cycling']],
                    ['key' => 'size', 'name' => 'Size', 'type' => 'select', 'group' => 'Fit', 'values' => self::CLOTHING_SIZES],
                    $colour,
                    $material(['Willow', 'Rubber', 'Leather', 'Polyester', 'Steel', 'Aluminium', 'Carbon fibre']),
                    ['key' => 'weight_kg', 'name' => 'Weight', 'type' => 'numeric', 'unit' => 'kg'],
                ],
                'categories' => [
                    ['name' => 'Cricket', 'description' => 'Bats, balls, pads and kits.', 'attributes' => ['material' => 'filter', 'size' => 'filter', 'weight_kg' => '']],
                    ['name' => 'Football', 'description' => 'Footballs, boots and kits.', 'attributes' => ['size' => 'filter', 'color' => 'filter', 'material' => 'filter']],
                    ['name' => 'Gym & fitness', 'description' => 'Dumbbells, mats, bands and machines.', 'attributes' => ['weight_kg' => 'filter', 'material' => 'filter', 'color' => 'filter']],
                    ['name' => 'Racket sports', 'description' => 'Badminton, tennis and table tennis.', 'attributes' => ['sport' => 'filter', 'material' => 'filter', 'weight_kg' => '']],
                    ['name' => 'Sportswear', 'description' => 'Shirts, trousers and tracksuits for sport.', 'attributes' => ['sport' => 'filter', 'size' => 'filter', 'color' => 'filter']],
                    ['name' => 'Outdoor & cycling', 'description' => 'Bicycles, helmets and outdoor gear.', 'attributes' => ['sport' => 'filter', 'size' => 'filter', 'color' => 'filter']],
                ],
            ],
            'handicrafts' => [
                'name' => 'Handicrafts & art', 'version' => 1,
                'summary' => 'Décor, textiles, pottery, woodwork, art and gifts, with craft, material, colour and handmade filters.',
                'themes' => ['boutique', 'minimal', 'default'], 'default_sort' => 'newest',
                'attributes' => [
                    ['key' => 'craft', 'name' => 'Craft', 'type' => 'multi_select', 'values' => ['Hand embroidery', 'Block print', 'Pottery', 'Wood carving', 'Truck art', 'Marble & onyx', 'Weaving']],
                    $material(['Wood', 'Clay', 'Marble', 'Onyx', 'Brass', 'Cotton', 'Wool', 'Camel skin']),
                    $colour,
                    ['key' => 'handmade', 'name' => 'Handmade', 'type' => 'boolean'],
                    ['key' => 'made_in', 'name' => 'Made in', 'type' => 'text', 'group' => 'Details'],
                    ['key' => 'dimensions', 'name' => 'Dimensions', 'type' => 'text', 'group' => 'Details'],
                ],
                'categories' => [
                    ['name' => 'Home décor', 'description' => 'Hand-made pieces for the home.', 'attributes' => ['craft' => 'filter', 'material' => 'filter', 'color' => 'filter', 'handmade' => 'filter', 'made_in' => '', 'dimensions' => '']],
                    ['name' => 'Textiles & shawls', 'description' => 'Embroidered and woven textiles.', 'attributes' => ['craft' => 'filter', 'material' => 'filter', 'color' => 'filter', 'handmade' => 'filter', 'made_in' => '']],
                    ['name' => 'Pottery & ceramics', 'description' => 'Hand-thrown and painted pottery.', 'attributes' => ['material' => 'filter', 'color' => 'filter', 'handmade' => 'filter', 'made_in' => '', 'dimensions' => '']],
                    ['name' => 'Woodwork', 'description' => 'Carved and inlaid wood.', 'attributes' => ['craft' => 'filter', 'material' => 'filter', 'handmade' => 'filter', 'made_in' => '', 'dimensions' => '']],
                    ['name' => 'Art & paintings', 'description' => 'Paintings, calligraphy and prints.', 'attributes' => ['craft' => 'filter', 'color' => 'filter', 'dimensions' => '']],
                    ['name' => 'Gifts', 'description' => 'Hand-made gifts.', 'attributes' => ['craft' => 'filter', 'material' => 'filter', 'handmade' => 'filter']],
                ],
            ],
            'other' => [
                'name' => 'General store', 'version' => 1,
                'summary' => 'A simple start for a mixed store: four broad categories with colour, size and material filters.',
                'themes' => ['default', 'modern'], 'default_sort' => 'newest',
                'attributes' => [
                    $colour,
                    ['key' => 'item_size', 'name' => 'Size', 'type' => 'select', 'values' => ['Small', 'Medium', 'Large']],
                    $material(['Cotton', 'Plastic', 'Metal', 'Wood', 'Glass', 'Leather']),
                ],
                'categories' => [
                    ['name' => 'Home & living', 'description' => 'Things for the home.', 'attributes' => ['color' => 'filter', 'material' => 'filter', 'item_size' => 'filter']],
                    ['name' => 'Personal care', 'description' => 'Everyday personal care.', 'attributes' => ['item_size' => 'filter']],
                    ['name' => 'Accessories', 'description' => 'Bags, wallets and small accessories.', 'attributes' => ['color' => 'filter', 'material' => 'filter']],
                    ['name' => 'Gifts', 'description' => 'Gifts for every occasion.', 'attributes' => ['color' => 'filter', 'item_size' => 'filter']],
                ],
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** @return TemplateDef */
    public static function get(string $key): array
    {
        return self::all()[$key] ?? throw new \InvalidArgumentException("Unknown starter template {$key}.");
    }


    /** @return list<string> the flags of a category's attribute entry */
    public static function flags(string $spec): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $spec))));
    }

    /** Limits a template must keep (a template saved from a large store is refused, not cut silently). */
    public const MAX_CATEGORIES = 60;

    public const MAX_CHILDREN = 40;

    public const MAX_ATTRIBUTES = 60;

    public const MAX_BRANDS = 100;

    /**
     * What is wrong with a template definition — the same rules for the
     * built-in templates (a test runs them) and for templates saved from a
     * store. A colour value may carry no code only in a saved template (the
     * store had none); built-in colours always have one.
     *
     * @param array<string, mixed> $t
     * @return list<string>
     */
    public static function problems(array $t, bool $builtIn = true): array
    {
        $problems = [];
        $attributes = is_array($t['attributes'] ?? null) ? $t['attributes'] : [];
        $categories = is_array($t['categories'] ?? null) ? $t['categories'] : [];
        $keys = array_column($attributes, 'key');
        if ($keys !== array_unique($keys)) {
            $problems[] = 'An attribute key is listed twice.';
        }
        if (count($attributes) > self::MAX_ATTRIBUTES || count($categories) > self::MAX_CATEGORIES || count($t['brands'] ?? []) > self::MAX_BRANDS) {
            $problems[] = 'Too large: at most '.self::MAX_CATEGORIES.' top-level categories, '.self::MAX_ATTRIBUTES.' attributes and '.self::MAX_BRANDS.' brands.';
        }
        foreach ($attributes as $a) {
            $type = \App\Domain\Catalog\Models\AttributeType::tryFrom((string) ($a['type'] ?? ''));
            if ($type === null || ! preg_match('/^[a-z0-9_-]{1,64}$/', (string) ($a['key'] ?? ''))) {
                $problems[] = 'Attribute '.($a['key'] ?? '?').' has an unknown type or key.';

                continue;
            }
            if (count($a['values'] ?? []) > \App\Domain\Catalog\Services\AttributeManager::MAX_VALUES) {
                $problems[] = "Attribute {$a['key']} has too many values.";
            }
            foreach ($a['values'] ?? [] as $v) {
                $isPair = is_array($v);
                $code = $isPair ? ($v[1] ?? null) : null;
                $badCode = $isPair && (($code === null && $builtIn) || ($code !== null && preg_match('/^#[0-9A-F]{6}$/i', (string) $code) !== 1));
                if ($isPair !== ($type === \App\Domain\Catalog\Models\AttributeType::Color) || $badCode) {
                    $problems[] = "Attribute {$a['key']}: a value does not fit its type.";

                    break;
                }
            }
        }
        $names = array_map(fn ($c) => mb_strtolower((string) ($c['name'] ?? '')), $categories);
        if ($names !== array_unique($names)) {
            $problems[] = 'A category name is listed twice.';
        }
        foreach ($categories as $c) {
            if (count($c['children'] ?? []) > self::MAX_CHILDREN || count($c['attributes'] ?? []) > \App\Domain\Catalog\Services\CategoryAttributes::MAX_PER_CATEGORY) {
                $problems[] = "Category {$c['name']} is too large.";
            }
            foreach ($c['attributes'] ?? [] as $key => $spec) {
                if (! in_array($key, $keys, true) || array_diff(self::flags((string) $spec), ['filter', 'required']) !== []) {
                    $problems[] = "Category {$c['name']} uses an undefined attribute or flag ({$key}).";
                }
            }
        }
        foreach ($t['themes'] ?? [] as $theme) {
            if (! \App\Domain\Theme\Support\ThemeCatalog::has((string) $theme)) {
                $problems[] = "Unknown theme {$theme}.";
            }
        }
        if (! in_array($t['default_sort'] ?? null, \App\Domain\Storefront\Services\StorefrontCatalog::SORTS, true)) {
            $problems[] = 'Unknown default order.';
        }

        return $problems;
    }
}
