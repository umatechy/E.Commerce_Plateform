<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

/**
 * Phase B51 (Module 03 §36–37; Module 29 §160; Module 30 §140; Bible
 * §112, §189) — what happens to each table holding a store's rows when the
 * store is permanently deleted.
 *
 * Every table with a store_id column must be in exactly one list; a test
 * (StoreDataMapTest) fails when a new table is added without a decision, so
 * no table is ever purged — or kept — by accident.
 *
 * RETAIN: legal, financial and accountability records that must outlive
 * the store (Module 29 §160: billing records stay reconcilable; the audit
 * trail is tamper-evident and append-only; the lifecycle history and the
 * deletion record prove what was done). Backups are governed by their own
 * retention policy (Module 23): the data leaves them as they expire.
 *
 * PURGE: everything else — catalog, customers and their personal data,
 * orders, payments taken by the store, settings, accounts with providers,
 * staff memberships, domains, logs. The merchant takes what they need with
 * the data export during the retention period.
 */
final class StoreDataMap
{
    public const RETAIN = [
        'audit_logs',
        'backups',
        'billing_credits',
        'credit_notes',
        'invoice_payments',
        'invoices',
        'payment_notices',
        'security_events', // Phase B52: security monitoring; removed by its own retention (security:prune)
        'store_deletions',
        'store_lifecycle_events',
        'subscriptions',
    ];

    public const PURGE = [
        'api_keys', 'api_request_logs', 'attribute_sets', 'attributes', 'badges', 'brands',
        'campaign_recipients', 'campaigns', 'cart_items', 'carts', 'categories', 'category_attributes',
        'collections', 'content_pages', 'content_translations', 'coupons', 'courier_accounts',
        'customer_addresses', 'customer_email_verifications', 'customer_groups', 'customer_merges',
        'customer_notes', 'customer_password_resets', 'customer_tag_assignments', 'customer_tags', 'customers',
        'developer_applications', 'domains', 'help_feedback', 'idempotency_keys', 'inventories', 'marketing_segments', 'messaging_accounts',
        'messaging_templates', 'notification_delivery_attempts', 'notification_messages',
        'notification_suppressions', 'notification_templates', 'order_items', 'order_number_sequences',
        'order_promotions', 'order_timeline_events', 'orders', 'outbox_events', 'payment_attempts',
        'payment_provider_accounts', 'payment_reconciliation_items', 'payment_transactions',
        'payment_webhook_events', 'payments', 'pickup_locations', 'product_attribute_values', 'product_images',
        'product_relations', 'product_reviews', 'product_variants', 'products', 'promotion_targets',
        'promotion_usages', 'promotions', 'redirects', 'report_exports', 'return_guest_links',
        'return_request_items', 'return_request_photos', 'return_requests', 'roles', 'seo_settings',
        'setting_revisions', 'shipment_items', 'shipment_tracking_events', 'shipment_webhook_events',
        'shipments', 'shipping_methods', 'shipping_rates', 'shipping_zones', 'starter_template_applications',
        'stock_movements', 'stock_reservations', 'store_credit_accounts', 'store_credit_entries',
        'store_credit_lots', 'store_health_snapshots', 'store_invitations', 'store_media', 'store_settings',
        'store_theme_publications', 'store_themes', 'store_user', 'support_messages', 'support_tickets',
        'tags', 'tax_classes', 'tax_rates', 'usage_counters', 'warehouses', 'webhook_delivery_attempts',
        'webhook_subscriptions', 'wishlist_items',
    ];

    /**
     * Files a row points to, removed before the row: table => [disk column or fixed disk, path column].
     * A null disk column means the fixed disk named in the third element.
     *
     * @var array<string, array{0: ?string, 1: string, 2?: string}>
     */
    public const FILES = [
        'product_images' => ['disk', 'path'],
        'store_media' => ['disk', 'path'],
        'return_request_photos' => ['disk', 'path'],
        'shipments' => [null, 'label_path', 'labels'],
        'report_exports' => [null, 'file_path', 'local'],
    ];

    /**
     * Folders that only ever hold one store's files (by public id or id),
     * swept after the rows' own files, so nothing written outside a row
     * (an interrupted upload) stays behind.
     *
     * @return list<string>
     */
    public static function folders(int $storeId, string $publicId): array
    {
        return ["stores/{$publicId}", "stores/{$storeId}", "shipping-labels/{$storeId}", "reports/{$storeId}"];
    }
}
