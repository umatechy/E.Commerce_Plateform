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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
    }

    public function boot(): void
    {
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

        // Seeds the default Owner/Manager/Staff roles for every new store —
        // see App\Domain\Tenancy\Observers\StoreObserver docblock.
        Store::observe(StoreObserver::class);

        // ADR-001 Layer 7 defense-in-depth: this Gate is checked by the
        // 'can:super-admin.impersonate' route middleware IN ADDITION TO
        // (not instead of) EnsureSuperAdminImpersonation's own check — two
        // independent enforcement points for the platform's highest-risk
        // capability, deliberately not collapsed into one.
        Gate::define('super-admin.impersonate', [SuperAdminAccessPolicy::class, 'impersonate']);
    }
}
