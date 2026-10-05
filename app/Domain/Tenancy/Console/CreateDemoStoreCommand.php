<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Console;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Orders\Models\Customer;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Storefront\Services\StorefrontSetupService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\DemoStoreContent;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A demo store for testing and showing the platform: an owner, a launched
 * store, 3 categories with 5 products each (prices in the store's currency,
 * stock in the default warehouse) and one customer account.
 *
 * It goes through the same steps as registration (StoreObserver seeds the
 * roles, warehouse and pickup shipping; a trial subscription starts) and the
 * same services staff use for stock and launch. DatabaseSeeder deliberately
 * seeds no business data, so this is a separate, explicit command, and it
 * refuses to run in production. Passwords are random and printed once; none
 * is stored in the code.
 */
final class CreateDemoStoreCommand extends Command
{
    protected $signature = 'demo:store
        {--email=demo.owner@example.com : Email of the demo store owner}
        {--customer-email=demo.customer@example.com : Email of the demo customer account}
        {--name=Umar Techy Demo Store : Store name}
        {--package=premium : Package of the trial (basic, business or premium)}
        {--theme=boutique : Theme to publish (default, minimal, modern, boutique, bold; the package must include it)}
        {--no-pictures : Leave the products without demo pictures}
        {--refresh-look= : Instead of creating a store, give an existing demo store (its slug) the pictures and theme}';

    protected $description = 'Create a demo store with 3 categories and 15 products (not in production).';

    /**
     * Demo catalogue: category => [name, sku, price in major units, sale price or null, stock, short description].
     *
     * @var array<string, array{description: string, products: list<array{0: string, 1: string, 2: int, 3: int|null, 4: int, 5: string}>}>
     */
    private const CATALOGUE = [
        'Attar & Fragrances' => [
            'description' => 'Alcohol-free attars and gift sets.',
            'products' => [
                ['Rose Attar 12ml', 'DEMO-ATR-001', 2500, null, 40, 'Classic Taif rose, long lasting, alcohol free.'],
                ['Oud Al Arab 6ml', 'DEMO-ATR-002', 3800, 3400, 25, 'Deep, smoky oud for evenings.'],
                ['White Musk Tahara 12ml', 'DEMO-ATR-003', 1800, null, 60, 'Soft, clean musk for every day.'],
                ['Jasmine Attar 6ml', 'DEMO-ATR-004', 1500, null, 30, 'Fresh jasmine flowers in a roll-on bottle.'],
                ['Amber Gift Set (3 x 6ml)', 'DEMO-ATR-005', 6500, 5900, 3, 'Amber, oud and rose in a gift box. Low stock on purpose.'],
            ],
        ],
        "Men's Clothing" => [
            'description' => 'Everyday and festive wear.',
            'products' => [
                ['White Cotton Kurta', 'DEMO-MCL-001', 3200, null, 35, 'Breathable cotton, regular fit.'],
                ['Wash & Wear Shalwar Kameez', 'DEMO-MCL-002', 4500, 3999, 20, 'Easy care fabric, unstitched suit.'],
                ['Black Waistcoat', 'DEMO-MCL-003', 5500, null, 15, 'Classic waistcoat for weddings and Eid.'],
                ['Peshawari Chappal', 'DEMO-MCL-004', 3900, null, 18, 'Hand-made leather chappal.'],
                ['Pure Wool Shawl', 'DEMO-MCL-005', 2800, null, 0, 'Warm wool shawl. Out of stock on purpose.'],
            ],
        ],
        'Home & Kitchen' => [
            'description' => 'Useful things for the home.',
            'products' => [
                ['Ceramic Tea Set (6 cups)', 'DEMO-HOM-001', 4200, null, 12, 'Six cups, six saucers and a teapot.'],
                ['Steel Cooking Pot 5L', 'DEMO-HOM-002', 3600, 3200, 22, 'Stainless steel with a glass lid.'],
                ['Prayer Mat', 'DEMO-HOM-003', 1900, null, 50, 'Soft padded mat in a travel pouch.'],
                ['Cushion Covers (set of 4)', 'DEMO-HOM-004', 2200, null, 28, 'Embroidered cotton covers, 16 x 16 inch.'],
                ['Wooden Wall Clock', 'DEMO-HOM-005', 2700, null, 10, 'Silent movement, 12 inch.'],
            ],
        ],
    ];

    public function handle(
        TenantContext $context,
        InventoryService $inventory,
        StorefrontSetupService $setup,
        EntitlementService $entitlements,
        DemoStoreContent $content,
    ): int {
        if (app()->environment('production')) {
            $this->error('demo:store does not run in production.');

            return self::FAILURE;
        }

        if ($this->option('refresh-look')) {
            return $this->refreshLook((string) $this->option('refresh-look'), $context, $content);
        }

        $email = (string) $this->option('email');
        $customerEmail = (string) $this->option('customer-email');
        $package = Package::query()->where('code', (string) $this->option('package'))->first();

        if ($package === null) {
            $this->error('Unknown package. Use basic, business or premium (run the PackageSeeder first).');

            return self::INVALID;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error("A staff account with {$email} already exists. Use --email to choose another.");

            return self::FAILURE;
        }

        $ownerPassword = Str::password(16, symbols: false);
        $customerPassword = Str::password(16, symbols: false);

        [$owner, $store] = DB::transaction(function () use ($email, $ownerPassword, $package) {
            $owner = User::query()->create(['name' => 'Demo Owner', 'email' => $email, 'password' => $ownerPassword]);
            // Phase B44: the one store creation path (Module 03 §55); "demo-store" keeps the slug recognisable.
            $store = app(\App\Domain\Tenancy\Services\StoreProvisioningService::class)
                ->selfService($owner, (string) $this->option('name'), 'other', $package, 'demo-store');

            return [$owner, $store];
        });
        Cache::flush(); // entitlements of the new package

        $context->resolveToStore($store->id);
        // Business information (a launch requirement since B44).
        app(\App\Domain\Settings\Services\ConfigService::class)->set('store.contact_email', $email, \App\Domain\Settings\Models\SettingScope::Store, $owner->id, 'Demo store');
        $currency = (string) app(\App\Domain\Settings\Services\ConfigService::class)->get('store.default_currency');
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $count = 0;

        foreach (self::CATALOGUE as $categoryName => $group) {
            $category = Category::query()->create([
                'name' => $categoryName,
                'slug' => Str::slug($categoryName).'-'.Str::lower(Str::random(6)),
                'description' => $group['description'],
                'status' => 'active',
                'visibility' => 'public',
                'sort_order' => $count,
            ]);

            foreach ($group['products'] as [$name, $sku, $price, $sale, $stock, $summary]) {
                $product = DB::transaction(function () use ($name, $sku, $price, $sale, $summary, $category, $currency) {
                    $product = Product::query()->create([
                        'type' => 'simple',
                        'name' => $name,
                        'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
                        'sku' => $sku,
                        'short_description' => $summary,
                        'description' => "<p>{$summary}</p><p>Demo product for testing the store.</p>",
                        'status' => 'active',
                        'visibility' => 'public',
                        'primary_category_id' => $category->id,
                        'price_minor' => $price * 10 ** \App\Domain\Settings\Services\Currencies::digits($currency),
                        'sale_price_minor' => $sale === null ? null : $sale * 10 ** \App\Domain\Settings\Services\Currencies::digits($currency),
                        'currency' => $currency,
                        'published_at' => now(),
                    ]);
                    $product->categories()->sync([$category->id]);

                    return $product;
                });
                $entitlements->recordUsage('max_products');
                if (! $this->option('no-pictures')) {
                    $content->picture($product, $categoryName, $count);
                }

                $record = Inventory::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id])->refresh();
                if ($stock > 0) {
                    $inventory->setOpeningStock($record, $stock, 'Demo store opening stock', $owner->id, "demo:{$store->id}:{$sku}");
                }
                $count++;
            }
        }

        $customer = Customer::query()->create(['name' => 'Demo Customer', 'email' => $customerEmail, 'password' => $customerPassword]);
        $launch = $setup->launch($store);
        $content->dressLanguages($owner->id);
        $content->dressMerchandising($owner->id);
        $theme = $this->dress($store, $owner->id, $content);

        app(AuditLogger::class)->record('demo.store_created', ['products' => $count, 'package' => $package->code], $store, $store->id);

        $this->info("Demo store created: {$store->name}");
        $this->table(['', ''], [
            ['Store', "/shop/{$store->slug}"],
            ['Package', "{$package->code} (trial)"],
            ['Launched', $launch['ok'] ? 'yes' : 'no ('.($launch['code'] ?? '').')'],
            ['Catalogue', "3 categories, {$count} products ({$currency})"],
            ['Theme', $theme],
            ['Languages', 'English and Urdu (اردو) — add ?lang=ur to any page'],
            ['Owner sign-in', "{$email} / {$ownerPassword}"],
            ['Customer sign-in', "{$customerEmail} / {$customerPassword} (customer: {$customer->public_id})"],
        ]);
        $this->line('The owner sets up two-step sign-in at the first sign-in (mandatory for store owners).');
        $this->warn('The passwords are shown only now. This is local demo data, not for production.');

        return self::SUCCESS;
    }
    /** Publishes the chosen theme with the demo home page; reports what was done. */
    private function dress(Store $store, int $ownerId, DemoStoreContent $content): string
    {
        $key = (string) $this->option('theme');
        try {
            $content->dressTheme(\App\Domain\Theme\Models\StoreTheme::query()->where('store_id', $store->id)->firstOrFail(), $key, $ownerId);

            return "{$key}, published with the demo home page";
        } catch (\App\Domain\Theme\Exceptions\ThemeNotEntitledException|\App\Domain\Theme\Exceptions\InvalidThemeConfigException $e) {
            $this->warn("Theme not applied: {$e->getMessage()}");

            return 'not changed';
        }
    }

    /** --refresh-look: pictures for products without any, and the theme, for a store made by this command. */
    private function refreshLook(string $slug, TenantContext $context, DemoStoreContent $content): int
    {
        $store = Store::query()->where('slug', $slug)->first();
        if ($store === null || ! str_starts_with($store->slug, 'demo-store-')) {
            $this->error('Only a store made by demo:store (slug demo-store-…) can be refreshed.');

            return self::FAILURE;
        }
        $context->resolveToStore($store->id);
        $owner = $store->users()->wherePivot('status', 'active')->orderBy('store_user.id')->first();
        $pictures = 0;
        foreach (Category::query()->get() as $category) {
            foreach (Product::query()->where('primary_category_id', $category->id)->orderBy('id')->get() as $i => $product) {
                $pictures += $content->picture($product, $category->name, $product->id + $i);
            }
        }
        $content->dressLanguages((int) $owner?->id);
        $content->dressMerchandising((int) $owner?->id);
        $theme = $this->dress($store, (int) $owner?->id, $content);
        app(AuditLogger::class)->record('demo.store_refreshed', ['pictures' => $pictures, 'theme' => $theme], $store, $store->id);
        $this->info("Refreshed /shop/{$store->slug}: {$pictures} pictures added; theme {$theme}.");

        return self::SUCCESS;
    }
}
