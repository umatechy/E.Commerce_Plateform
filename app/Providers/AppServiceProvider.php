<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Models\Attribute;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Policies\AttributePolicy;
use App\Domain\Catalog\Policies\BrandPolicy;
use App\Domain\Catalog\Policies\CategoryPolicy;
use App\Domain\Catalog\Policies\ProductPolicy;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Policies\RolePolicy;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Policies\InventoryPolicy;
use App\Domain\Inventory\Policies\WarehousePolicy;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Policies\OrderPolicy;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Policies\PackagePolicy;
use App\Domain\Packages\Policies\SubscriptionPolicy;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Policies\PaymentPolicy;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Policies\ShipmentPolicy;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Promotions\Policies\PromotionPolicy;
use App\Domain\SuperAdmin\Policies\SuperAdminAccessPolicy;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Observers\StoreObserver;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // CRITICAL (see docs/development/b1-inspection-findings.md item C):
        // TenantContext is stateful — ResolveTenantContext middleware resolves it
        // ONCE per request, and every downstream global scope/service/controller
        // must read that SAME resolved instance. scoped() (Laravel's container
        // lifetime that resets per request AND per queue job) is required here —
        // a plain, unbound class or a true singleton() would either lose state
        // between calls or leak state across queue-worker job boundaries.
        $this->app->scoped(TenantContext::class);
        // Phase B18 — same reasoning as TenantContext directly above:
        // scoped(), never singleton(), so it resets per request/job.
        $this->app->scoped(\App\Domain\DeveloperPlatform\Support\ApiKeyContext::class);

        // Phase B19 — interface bindings so BackupService/RunBackupJob
        // depend only on the abstraction (Module 23 Phase 4/10), never
        // a concrete provider. Swapping to a real S3 adapter later is a
        // one-line change here, not a change to any business logic.
        $this->app->bind(
            \App\Domain\DataProtection\Services\Storage\BackupStorageAdapter::class,
            \App\Domain\DataProtection\Services\Storage\LocalBackupStorageAdapter::class,
        );
        $this->app->bind(
            \App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy::class,
            \App\Domain\DataProtection\Services\DumpStrategies\MysqldumpStrategy::class,
        );
        $this->app->bind(
            \App\Domain\DataProtection\Services\DumpStrategies\DatabaseRestoreStrategy::class,
            \App\Domain\DataProtection\Services\DumpStrategies\MysqlRestoreStrategy::class,
        );
        // Phase B30: where a restore rehearsal is carried out (Module 23 §39).
        $this->app->bind(
            \App\Domain\DataProtection\Services\Rehearsal\RehearsalTarget::class,
            \App\Domain\DataProtection\Services\Rehearsal\MysqlRehearsalTarget::class,
        );
    }

    public function boot(): void
    {
        // App\Domain\* models live outside App\Models, so Laravel's default
        // factory guess (Database\Factories\Domain\...\StoreFactory) never
        // matches the flat database/factories/ layout. Every Model::factory()
        // call failed with "class not found" until this resolver was added
        // (found on the first real test run).
        // Module 32 (Phase B22): staff authentication outcomes are audited.
        Event::subscribe(\App\Domain\Compliance\Listeners\RecordAuthenticationEvents::class);

        // Module 32 password policy for staff and customer accounts
        // (Password::defaults() is what both registration requests use).
        // The breached-password check calls an external API, so it runs in
        // production only.
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->letters()->uncompromised()
            : Password::min(10)->letters());

        Factory::guessFactoryNamesUsing(
            static fn (string $modelName): string => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        // See PersonalAccessToken's docblock: token -> owner resolution
        // must not depend on a TenantContext that cannot exist yet.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // App\Domain\* models/policies do not follow Laravel's default
        // App\Models / App\Policies convention, so auto-discovery does not apply
        // to them — every Policy MUST be registered here explicitly. A Policy
        // added under app/Domain/**/Policies/ that is NOT listed below will
        // silently never be enforced (Laravel treats "no policy found" as
        // "no policy applies", not as an error) — this is a fail-open risk
        // flagged in docs/security/b1-security-review.md. Any code review of a
        // new Policy MUST include a check that it was added to this map.
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Package::class, PackagePolicy::class);
        Gate::policy(Subscription::class, SubscriptionPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Brand::class, BrandPolicy::class);
        Gate::policy(Attribute::class, AttributePolicy::class);
        Gate::policy(Inventory::class, InventoryPolicy::class);
        Gate::policy(Warehouse::class, WarehousePolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Shipment::class, ShipmentPolicy::class);
        Gate::policy(Promotion::class, PromotionPolicy::class);

        // Module 05 (Phase B24): any change shoppers can see invalidates
        // that store's storefront cache (see StorefrontCache).
        foreach ([
            \App\Domain\Catalog\Models\Product::class, \App\Domain\Catalog\Models\ProductVariant::class,
            \App\Domain\Catalog\Models\ProductImage::class, \App\Domain\Catalog\Models\Category::class,
            \App\Domain\Catalog\Models\Brand::class, \App\Domain\Inventory\Models\Inventory::class,
            \App\Domain\Inventory\Models\Warehouse::class, \App\Domain\Seo\Models\ContentPage::class,
            \App\Domain\Seo\Models\SeoSetting::class, \App\Domain\Theme\Models\StoreTheme::class,
            \App\Domain\Settings\Models\StoreSetting::class, \App\Domain\Domains\Models\Domain::class,
            Store::class,
            // Phase B36: a package change changes what the theme may show.
            \App\Domain\Packages\Models\Subscription::class,
        ] as $model) {
            $model::observe(\App\Domain\Storefront\Observers\StorefrontCacheObserver::class);
        }

        // Seeds the default Owner/Manager/Staff roles for every new store —
        // see App\Domain\Tenancy\Observers\StoreObserver docblock.
        Store::observe(StoreObserver::class);

        // Module 16 §11 "Slug Changes" (Phase B13) — additive
        // observers that record a redirect when a public-facing slug
        // changes; never touch each domain's own update logic.
        \App\Domain\Catalog\Models\Product::observe(\App\Domain\Seo\Observers\ProductSlugObserver::class);
        \App\Domain\Catalog\Models\Category::observe(\App\Domain\Seo\Observers\CategorySlugObserver::class);
        \App\Domain\Catalog\Models\Brand::observe(\App\Domain\Seo\Observers\BrandSlugObserver::class);

        // ADR-001 Layer 7 defense-in-depth: this Gate is checked by the
        // 'can:super-admin.impersonate' route middleware IN ADDITION TO
        // (not instead of) EnsureSuperAdminImpersonation's own check — two
        // independent enforcement points for the platform's highest-risk
        // capability, deliberately not collapsed into one.
        Gate::define('super-admin.impersonate', [SuperAdminAccessPolicy::class, 'impersonate']);
        // Phase B16 fix — see docs/development/b16-inspection-findings.md
        // "Critical Bug Found": a SEPARATE ability for genuinely platform-
        // global Super Admin routes (no target store at all), so they are
        // never forced through EnsureSuperAdminImpersonation's per-store
        // logic.
        Gate::define('super-admin.platform', [SuperAdminAccessPolicy::class, 'platformAction']);

        // Phase G1 (Module 02 §18–19): the store team abilities.
        Gate::define('team.view', [\App\Domain\Identity\Policies\TeamPolicy::class, 'view']);
        Gate::define('team.invite', [\App\Domain\Identity\Policies\TeamPolicy::class, 'invite']);
        Gate::define('team.manage', [\App\Domain\Identity\Policies\TeamPolicy::class, 'manage']);

        // Module 31 §17-19 "Rate Limiting" (Phase B18) — keyed by the
        // authenticated ApiKey's id (never by IP alone, which would
        // wrongly share one bucket across every developer behind the
        // same NAT/proxy), limit sourced from B17's ConfigService
        // rather than a hardcoded number (§43: "integrate with B17,
        // don't invent a second config mechanism"). Uses Laravel's own
        // real, distributed-cache-backed limiter — never an in-memory
        // process-local counter.
        \Illuminate\Support\Facades\RateLimiter::for('developer_api', function (\Illuminate\Http\Request $request) {
            $context = app(\App\Domain\DeveloperPlatform\Support\ApiKeyContext::class);

            if (! $context->isSet()) {
                return \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by($request->ip()); // pre-authentication requests (e.g. a malformed header) still get a conservative floor
            }

            $perMinute = app(\App\Domain\Settings\Services\ConfigService::class)->get('api.default_rate_limit_per_minute');

            return \Illuminate\Cache\RateLimiting\Limit::perMinute($perMinute)->by((string) $context->get()->id);
        });
    }
}
