<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Platform-level permission catalog (Module 02 §6 — centrally defined,
 * referenced by key, not hard-coded string checks scattered through
 * controllers). Deliberately minimal for B1 scope — only what
 * StoreObserver's default roles (Manager/Staff) actually reference.
 * More permissions are added alongside the modules that need them
 * (products.*, orders.*, etc. already exist here as forward-declared
 * keys so StoreObserver's Manager/Staff role seeding has real rows to
 * attach to; the endpoints those permissions gate are implemented in
 * later phases).
 *
 * Idempotent (updateOrCreate) — safe to re-run, never destructive, per
 * this milestone's "Seeders" requirements.
 */
final class PermissionSeeder extends Seeder
{
    private const PERMISSIONS = [
        ['key' => 'roles.view', 'group' => 'roles', 'description' => 'View roles within the store'],
        ['key' => 'roles.manage', 'group' => 'roles', 'description' => 'Create, update, delete roles'],
        ['key' => 'users.view', 'group' => 'users', 'description' => 'View store team members'],
        ['key' => 'users.invite', 'group' => 'users', 'description' => 'Invite new team members'],
        ['key' => 'products.view', 'group' => 'products', 'description' => 'View products'],
        ['key' => 'products.create', 'group' => 'products', 'description' => 'Create products'],
        ['key' => 'products.update', 'group' => 'products', 'description' => 'Update products'],
        ['key' => 'products.delete', 'group' => 'products', 'description' => 'Delete products (Phase B3)'],
        ['key' => 'products.view_cost', 'group' => 'products', 'description' => 'View product cost price — Module 06 §25, never customer-visible (Phase B3)'],
        ['key' => 'categories.manage', 'group' => 'categories', 'description' => 'Create, update, delete categories (Phase B3)'],
        ['key' => 'brands.manage', 'group' => 'brands', 'description' => 'Create, update, delete brands (Phase B3)'],
        ['key' => 'attributes.manage', 'group' => 'attributes', 'description' => 'Create, update, delete attributes (Phase B3)'],
        ['key' => 'inventory.view', 'group' => 'inventory', 'description' => 'View inventory, stock levels, and movement history (Phase B4)'],
        ['key' => 'inventory.adjust', 'group' => 'inventory', 'description' => 'Adjust stock, set opening stock, manage reservations (Phase B4)'],
        ['key' => 'warehouses.manage', 'group' => 'warehouses', 'description' => 'Create, update warehouses (Phase B4)'],
        ['key' => 'orders.view', 'group' => 'orders', 'description' => 'View orders (Module 09 — Phase B5)'],
        ['key' => 'orders.create', 'group' => 'orders', 'description' => 'Create orders on behalf of a customer, e.g. admin/POS orders (Phase B5)'],
        ['key' => 'orders.update', 'group' => 'orders', 'description' => 'Update order status (Module 09 — Phase B5)'],
        ['key' => 'orders.cancel', 'group' => 'orders', 'description' => 'Cancel orders (Phase B5)'],
        ['key' => 'payments.view', 'group' => 'payments', 'description' => 'View payments and transactions (Module 12 — Phase B7)'],
        ['key' => 'payments.manage', 'group' => 'payments', 'description' => 'Record manual payment confirmations, e.g. bank transfer/COD collection (Phase B7)'],
        ['key' => 'payments.refund', 'group' => 'payments', 'description' => 'Issue refunds — high-risk financial permission (Phase B7)'],
        ['key' => 'shipments.view', 'group' => 'shipments', 'description' => 'View shipments and tracking (Module 13 — Phase B8)'],
        ['key' => 'shipments.fulfill', 'group' => 'shipments', 'description' => 'Create shipments and update shipment status (Phase B8)'],
        ['key' => 'shipping_config.manage', 'group' => 'shipments', 'description' => 'Manage shipping zones, methods and rates (Phase B8)'],
        ['key' => 'promotions.view', 'group' => 'promotions', 'description' => 'View promotions, coupons and usage (Module 14 — Phase B9)'],
        ['key' => 'promotions.manage', 'group' => 'promotions', 'description' => 'Create/edit promotions and coupons (Phase B9)'],
        ['key' => 'marketing.view', 'group' => 'marketing', 'description' => 'View marketing campaigns and segments (Module 15 — Phase B10)'],
        ['key' => 'marketing.manage', 'group' => 'marketing', 'description' => 'Create/edit/activate marketing campaigns and segments (Phase B10)'],
        ['key' => 'notifications.view', 'group' => 'notifications', 'description' => 'View notification messages, delivery attempts and templates (Module 21 — Phase B11)'],
        ['key' => 'notifications.manage', 'group' => 'notifications', 'description' => 'Create/edit/publish notification templates (Phase B11)'],
        ['key' => 'analytics.view', 'group' => 'analytics', 'description' => 'View dashboard and non-financial reports (Module 22 — Phase B12)'],
        ['key' => 'analytics.financial', 'group' => 'analytics', 'description' => 'View revenue/payment/financial reports — sensitive (Phase B12)'],
        ['key' => 'analytics.export', 'group' => 'analytics', 'description' => 'Request and download report exports (Phase B12)'],
        ['key' => 'seo.view', 'group' => 'seo', 'description' => 'View SEO settings, content pages and redirects (Module 16 — Phase B13)'],
        ['key' => 'seo.manage', 'group' => 'seo', 'description' => 'Manage SEO settings, content pages and redirects (Phase B13)'],
        ['key' => 'domains.view', 'group' => 'domains', 'description' => 'View domains and verification status (Module 19 — Phase B14)'],
        ['key' => 'domains.manage', 'group' => 'domains', 'description' => 'Add/verify/activate/remove domains, change primary domain (Phase B14)'],
        ['key' => 'theme.view', 'group' => 'theme', 'description' => 'View storefront theme configuration and branding (Module 17 — Phase B15)'],
        ['key' => 'theme.manage', 'group' => 'theme', 'description' => 'Edit draft theme configuration and branding (Phase B15)'],
        ['key' => 'theme.publish', 'group' => 'theme', 'description' => 'Publish or roll back the live storefront theme (Phase B15)'],
        ['key' => 'settings.view', 'group' => 'settings', 'description' => 'View store-level configuration (Module 33 — Phase B17)'],
        ['key' => 'settings.manage', 'group' => 'settings', 'description' => 'Edit store-level configuration (Phase B17)'],
        ['key' => 'developer_platform.view', 'group' => 'developer_platform', 'description' => 'View developer applications, API keys, and webhooks (Module 31 — Phase B18)'],
        ['key' => 'developer_platform.manage', 'group' => 'developer_platform', 'description' => 'Create/revoke developer applications, API keys, and webhooks (Phase B18)'],
        ['key' => 'audit.view', 'group' => 'compliance', 'description' => 'View the store\'s audit trail and verify its integrity — sensitive (Module 32 — Phase B22)'],
        ['key' => 'privacy.manage', 'group' => 'compliance', 'description' => 'Export or erase a customer\'s personal data — irreversible (Module 32 — Phase B22)'],
        ['key' => 'store_health.view', 'group' => 'store_health', 'description' => 'View the store\'s health checks and resource usage (Module 24 — Phase B21)'],
        ['key' => 'backups.view', 'group' => 'backups', 'description' => 'View this store\'s own backup history and status (Module 23 — Phase B19)'],
        ['key' => 'backups.manage', 'group' => 'backups', 'description' => 'Request a manual backup for this store (Phase B19)'],
        ['key' => 'backups.restore', 'group' => 'backups', 'description' => 'Request a restore from a backup — a deliberately stronger permission than view/manage (Phase B19)'],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->updateOrCreate(['key' => $permission['key']], $permission);
        }
    }
}
