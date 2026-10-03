<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Observers;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Support\SystemRoles;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a new store's default role set at creation time (Module 02 §5
 * "Configurable Role System" — predefined roles without hard-coding
 * permissions throughout the app). Deliberately per-store, event-driven
 * (not a one-off global seeder run) so it is safe, deterministic, and
 * non-destructive for every store created over the platform's lifetime
 * — exactly the "seeders must be ... repeatable ... non-destructive"
 * requirement.
 *
 * `store_id` is set EXPLICITLY on each Role below — this deliberately
 * bypasses BelongsToTenant's TenantContext auto-fill, because this
 * observer legitimately runs before any tenant context exists (the
 * store is being created right now). This is the one documented,
 * reviewed exception to "always resolve store_id from TenantContext" —
 * see ADR-001 §9 Detailed Implementation Rules.
 */
final class StoreObserver
{
    public function created(Store $store): void
    {
        // The seven predefined roles (Module 02 §6) — see SystemRoles.
        foreach (SystemRoles::definitions() as $slug => $definition) {
            $role = Role::query()->withoutTenantScope()->create([
                'store_id' => $store->id,
                'name' => $definition['name'],
                'slug' => $slug,
                'is_system' => true, // seeded default roles are not deletable — enforced in RolePolicy::delete()
            ]);

            // Owner has no rows: it is checked via its slug in BaseTenantPolicy::isOwner().
            $permissionIds = Permission::query()->whereIn('key', $definition['permissions'])->pluck('id');
            $role->permissions()->attach($permissionIds);
        }

        // Module 08 §14 "Default Warehouse" — every store gets exactly
        // one warehouse at creation, matching "Basic stores without
        // multi-warehouse management" (Phase B4). store_id is explicit
        // for the same reason as Role above (no TenantContext exists
        // yet during store creation).
        Warehouse::query()->withoutTenantScope()->create([
            'store_id' => $store->id,
            'name' => 'Main Warehouse',
            'code' => 'main',
            'status' => 'active',
            'is_default' => true,
        ]);

        // Module 09 §5/§22 "Order Number Generation" (Phase B5) — seeded
        // at 0 ("no orders issued yet") so the first real call to
        // OrderNumberGenerator::next() correctly returns sequence 1, not
        // 2. See that class's docblock for why this row must exist
        // BEFORE the first generation call (MySQL's LAST_INSERT_ID(expr)
        // idiom requires the ON DUPLICATE KEY UPDATE branch to fire
        // every time, which requires the row to already exist).
        DB::table('order_number_sequences')->insert([
            'store_id' => $store->id,
            'next_number' => 0,
        ]);

        // Module 12 §31 "Provider Configuration" (Phase B7) — every
        // store gets its OWN random webhook-signing secret at creation,
        // never a shared/default value (see the migration backfill's
        // docblock for why per-row uniqueness matters here).
        $store->update([
            'payment_webhook_secret' => \Illuminate\Support\Str::random(64),
            'shipment_webhook_secret' => \Illuminate\Support\Str::random(64),
            'notification_signing_secret' => \Illuminate\Support\Str::random(64),
        ]);

        // Module 13 §8/§15 (Phase B8) — every store gets one working,
        // serviceable default configuration out of the box (same
        // "default Warehouse/Roles" precedent): a catch-all Default
        // Zone (matches every destination, since no geo field is set)
        // and a free Store Pickup method — the only method type that
        // needs zero real-world configuration (no carrier account, no
        // rate table) to be immediately usable. store_id is explicit
        // for the same reason as Role/Warehouse above.
        $defaultZone = \App\Domain\Shipping\Models\ShippingZone::query()->withoutTenantScope()->create([
            'store_id' => $store->id,
            'name' => 'Default Zone',
            'is_default' => true,
            'is_active' => true,
        ]);

        $pickupMethod = \App\Domain\Shipping\Models\ShippingMethod::query()->withoutTenantScope()->create([
            'store_id' => $store->id,
            'name' => 'Store Pickup',
            'type' => 'store_pickup',
            'is_active' => true,
        ]);

        \App\Domain\Shipping\Models\ShippingRate::query()->withoutTenantScope()->create([
            'store_id' => $store->id,
            'shipping_zone_id' => $defaultZone->id,
            'shipping_method_id' => $pickupMethod->id,
            // A new store's own currency (owner decision 2026-10-03: PKR); this
            // free pickup rate must match the store's carts to be offered.
            'currency' => \App\Domain\Settings\Services\Currencies::DEFAULT,
            'base_cost_minor' => 0,
        ]);

        // Module 19 §8 "Platform Subdomain System" (Phase B14) — every
        // new store automatically gets one auto-Active, auto-primary
        // domain the platform itself controls (no external verification
        // needed or possible).
        app(\App\Domain\Domains\Services\DomainService::class)->createPlatformSubdomain($store);

        // Module 17 §16 "Theme Lifecycle" (Phase B15) — every new store
        // automatically gets a real, resolvable, auto-published default
        // theme configuration (see docs/development/b15-inspection-findings.md
        // "Bug Found and Fixed" — auto-published, not left as a draft
        // with nothing yet resolvable).
        app(\App\Domain\Theme\Services\ThemeService::class)->createDefaultForStore($store);
    }
}
