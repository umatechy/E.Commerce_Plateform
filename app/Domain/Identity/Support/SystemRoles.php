<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

/**
 * The predefined store roles every store gets (Module 02 §6). Seeded per
 * store by StoreObserver; `is_system` roles cannot be edited or deleted.
 *
 * Owner holds every permission implicitly (BaseTenantPolicy::isOwner) and
 * therefore has no permission rows. Owner-only by design: role definitions
 * (roles.manage), billing changes, API keys, backups and restores, privacy
 * requests and contacting the platform's support.
 *
 * Phase G1 added Administrator, Order Manager, Inventory Manager and
 * Content & Marketing (the blueprint lists seven roles; B1 seeded three).
 * Existing stores receive them through the
 * 2028_02_01_000004 data migration.
 */
final class SystemRoles
{
    public const OWNER = 'owner';

    /** @var list<string> */
    private const MANAGER = [
        'roles.view', 'users.view', 'users.invite',
        'products.view', 'products.create', 'products.update',
        'categories.manage', 'brands.manage', 'attributes.manage', 'collections.manage',
        'inventory.view', 'inventory.adjust', 'warehouses.manage',
        'orders.view', 'orders.create', 'orders.update', 'orders.cancel',
        'payments.view', 'payments.manage',
        'shipments.view', 'shipments.fulfill', 'shipping_config.manage',
        'promotions.view', 'promotions.manage',
        'marketing.view', 'marketing.manage',
        'notifications.view', 'notifications.manage',
        'analytics.view', 'analytics.export',
        'seo.view', 'seo.manage',
        'domains.view',
        'theme.view', 'theme.manage', 'theme.publish',
        'settings.view', 'settings.manage',
        'developer_platform.view', // developer_platform.manage withheld — API key issuance is Owner-only (Phase B18)
        'store_health.view', // Module 24 (Phase B21)
        'backups.view', // backups.manage/restore withheld — Owner-only (Phase B19)
        'support.view', 'support.reply', 'support.manage', // Module 34 (Phase B26)
        'customers.view', 'customers.manage', // Module 10 (Phase B32)
        'returns.view', 'returns.manage', 'returns.approve', // Module 09 §65 (Phase B33); refunds stay with payments.refund
    ];

    /** @return array<string, array{name: string, permissions: list<string>}> slug => definition, Owner first */
    public static function definitions(): array
    {
        return [
            self::OWNER => ['name' => 'Owner', 'permissions' => []],
            // Broad store management without the Owner-only controls listed above.
            'administrator' => ['name' => 'Administrator', 'permissions' => [
                ...self::MANAGER,
                'users.manage', 'products.delete', 'products.view_cost', 'payments.refund',
                'analytics.financial', 'domains.manage', 'storefront.manage', 'audit.view', 'billing.view',
                'customers.export', 'customers.import', 'store_credit.manage', // creating store credit by hand is a financial act (Phase B34)
                'tax.manage', // Phase B46: tax set-up is a financial and legal setting
            ]],
            'manager' => ['name' => 'Manager', 'permissions' => self::MANAGER],
            'staff' => ['name' => 'Staff', 'permissions' => [
                'products.view', 'orders.view', 'returns.view',
                'support.view', 'support.reply', // Module 34 (Phase B26): front-line support
            ]],
            'order-manager' => ['name' => 'Order Manager', 'permissions' => [
                'orders.view', 'orders.create', 'orders.update', 'orders.cancel',
                'payments.view', 'payments.manage', 'shipments.view', 'shipments.fulfill',
                'products.view', 'inventory.view', 'support.view', 'support.reply',
                'customers.view', 'returns.view', 'returns.manage', // receives and inspects; approval and refunds are for managers
            ]],
            'inventory-manager' => ['name' => 'Inventory Manager', 'permissions' => [
                'products.view', 'inventory.view', 'inventory.adjust', 'warehouses.manage',
                'orders.view', 'shipments.view',
            ]],
            'content-marketing' => ['name' => 'Content & Marketing', 'permissions' => [
                'products.view', 'products.create', 'products.update',
                'categories.manage', 'brands.manage', 'attributes.manage', 'collections.manage',
                'promotions.view', 'promotions.manage', 'marketing.view', 'marketing.manage',
                'seo.view', 'seo.manage', 'theme.view', 'theme.manage', 'analytics.view',
                'customers.view',
            ]],
        ];
    }
}
