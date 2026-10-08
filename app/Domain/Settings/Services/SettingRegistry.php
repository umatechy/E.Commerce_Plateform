<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Models\SettingType;

/**
 * Module 33 §5 "Configuration Registry" / Non-Negotiable §12 "No
 * Arbitrary Key/Value Admin" — the ONE fixed, server-authoritative
 * list of every setting key this platform recognizes. There is no
 * code path anywhere in this domain that accepts a key not present
 * here — ConfigService::get()/set() both reject an unknown key before
 * touching the database at all.
 *
 * Deliberately minimal — see docs/development/b17-inspection-findings.md
 * "Architectural Decision — Minimal, Named-Example-Only Seeded
 * Settings": every key below is drawn directly from Module 33's own
 * named examples, nothing invented.
 */
final class SettingRegistry
{
    /** @return array<string, SettingDefinition> */
    public static function all(): array
    {
        return [
            'platform.supported_currencies' => new SettingDefinition(
                key: 'platform.supported_currencies', scope: SettingScope::Platform, type: SettingType::StringArray,
                default: Currencies::SUPPORTED, // owner decision 2026-10-03: PKR first, then USD, EUR, GBP, AED, SAR
            ),
            'platform.default_locale' => new SettingDefinition(
                key: 'platform.default_locale', scope: SettingScope::Platform, type: SettingType::String,
                default: 'en',
            ),
            // Phase B44 (owner decision 13, Module 03 §5, Module 04 §14): how stores come to exist and go live.
            'platform.self_signup_enabled' => new SettingDefinition(
                key: 'platform.self_signup_enabled', scope: SettingScope::Platform, type: SettingType::Boolean,
                default: true,
            ),
            'platform.launch_requires_payment' => new SettingDefinition(
                key: 'platform.launch_requires_payment', scope: SettingScope::Platform, type: SettingType::Boolean,
                default: false,
            ),
            'platform.trial_package' => new SettingDefinition(
                key: 'platform.trial_package', scope: SettingScope::Platform, type: SettingType::String,
                default: 'basic', // a package code; checked to exist and be active
            ),
            // Phase B47 (Module 29 §57, §75, §92, §47): Umar Techy's own billing to stores.
            // No tax rate is built in: the env value (default 0) is only the starting point.
            'billing.tax_rate_bps' => new SettingDefinition(key: 'billing.tax_rate_bps', scope: SettingScope::Platform, type: SettingType::Integer, default: max(0, (int) config('billing.tax_rate_bps'))),
            'billing.tax_label' => new SettingDefinition(key: 'billing.tax_label', scope: SettingScope::Platform, type: SettingType::String, default: (string) config('billing.tax_label', 'Tax')),
            'billing.approval_threshold_minor' => new SettingDefinition(key: 'billing.approval_threshold_minor', scope: SettingScope::Platform, type: SettingType::Integer, default: 0),
            'billing.upgrade_timing' => new SettingDefinition(key: 'billing.upgrade_timing', scope: SettingScope::Platform, type: SettingType::String, default: 'immediate_prorated', allowedValues: ['immediate_prorated', 'next_period']),
            'billing.issuer_name' => new SettingDefinition(key: 'billing.issuer_name', scope: SettingScope::Platform, type: SettingType::String, default: 'Umar Techy'),
            'billing.issuer_details' => new SettingDefinition(key: 'billing.issuer_details', scope: SettingScope::Platform, type: SettingType::String, default: null),
            'platform.trial_days' => new SettingDefinition(
                key: 'platform.trial_days', scope: SettingScope::Platform, type: SettingType::Integer,
                default: 14,
            ),
            // Phase B44 (Module 03 §14, §24): the store's business information.
            'store.legal_name' => new SettingDefinition(key: 'store.legal_name', scope: SettingScope::Store, type: SettingType::String, default: null),
            'store.contact_email' => new SettingDefinition(key: 'store.contact_email', scope: SettingScope::Store, type: SettingType::String, default: null),
            'store.contact_phone' => new SettingDefinition(key: 'store.contact_phone', scope: SettingScope::Store, type: SettingType::String, default: null),
            'store.country' => new SettingDefinition(key: 'store.country', scope: SettingScope::Store, type: SettingType::String, default: 'PK'),
            'platform.maintenance_mode' => new SettingDefinition(
                key: 'platform.maintenance_mode', scope: SettingScope::Platform, type: SettingType::Boolean,
                default: false,
            ),
            'store.default_currency' => new SettingDefinition(
                key: 'store.default_currency', scope: SettingScope::Store, type: SettingType::String,
                default: Currencies::DEFAULT, // owner decision 2026-10-03 (Pakistan first). Module 33 §4 hierarchy: cross-validated against platform.supported_currencies at write time, see SettingValidator
            ),
            // Module 09 §42/§45 (Phase B33): "Customers may request returns where
            // the store allows them"; "the exact rules must be configurable".
            // Off until the store decides; staff can always record a return.
            'returns.customer_requests_enabled' => new SettingDefinition(
                key: 'returns.customer_requests_enabled', scope: SettingScope::Store, type: SettingType::Boolean,
                default: false,
            ),
            // Days after delivery in which a customer may ask. A starting value
            // the store changes to its own policy; staff are not bound by it.
            'returns.window_days' => new SettingDefinition(
                key: 'returns.window_days', scope: SettingScope::Store, type: SettingType::Integer,
                default: 7,
            ),
            // Module 09 §52 (Phase B35): store credit does not expire unless the
            // store turns it on — the policy is the owner's. When on, credit
            // given from then on lapses after the number of days below (a
            // starting value the store sets to its own policy).
            'store_credit.expires' => new SettingDefinition(
                key: 'store_credit.expires', scope: SettingScope::Store, type: SettingType::Boolean,
                default: false,
            ),
            'store_credit.expiry_days' => new SettingDefinition(
                key: 'store_credit.expiry_days', scope: SettingScope::Store, type: SettingType::Integer,
                default: 365,
            ),
            'store.timezone' => new SettingDefinition(
                key: 'store.timezone', scope: SettingScope::Store, type: SettingType::String,
                default: 'UTC', // closes the multiply-documented gap from B10/B12/B13 — see inspection findings
            ),
            'store.default_locale' => new SettingDefinition(
                key: 'store.default_locale', scope: SettingScope::Store, type: SettingType::String,
                default: null, allowedValues: array_keys(Locales::SUPPORTED), fallsBackToPlatformKey: 'platform.default_locale',
            ),
            // Phase B38 (Module 05 §41): the languages the storefront offers;
            // the default language is always one of them.
            'store.languages' => new SettingDefinition(
                key: 'store.languages', scope: SettingScope::Store, type: SettingType::StringArray,
                default: [Locales::DEFAULT],
            ),
            // Phase B43 (Module 05 §19, Module 06 §37): how listings are ordered.
            'catalog.default_sort' => new SettingDefinition(
                key: 'catalog.default_sort', scope: SettingScope::Store, type: SettingType::String,
                default: 'newest', allowedValues: ['featured', 'newest', 'best_selling', 'price_asc', 'price_desc', 'name'],
            ),
            'catalog.featured_in_search' => new SettingDefinition(
                key: 'catalog.featured_in_search', scope: SettingScope::Store, type: SettingType::Boolean,
                default: true,
            ),
            // Phase B43 (Module 06 §36): automatic badges, each on or off, and their thresholds.
            'badges.new' => new SettingDefinition(key: 'badges.new', scope: SettingScope::Store, type: SettingType::Boolean, default: true),
            'badges.new_days' => new SettingDefinition(key: 'badges.new_days', scope: SettingScope::Store, type: SettingType::Integer, default: 14),
            'badges.sale' => new SettingDefinition(key: 'badges.sale', scope: SettingScope::Store, type: SettingType::Boolean, default: true),
            'badges.sale_percent' => new SettingDefinition(key: 'badges.sale_percent', scope: SettingScope::Store, type: SettingType::Boolean, default: true),
            'badges.bestseller' => new SettingDefinition(key: 'badges.bestseller', scope: SettingScope::Store, type: SettingType::Boolean, default: true),
            'badges.bestseller_count' => new SettingDefinition(key: 'badges.bestseller_count', scope: SettingScope::Store, type: SettingType::Integer, default: 10),
            'badges.bestseller_days' => new SettingDefinition(key: 'badges.bestseller_days', scope: SettingScope::Store, type: SettingType::Integer, default: 30),
            'badges.low_stock' => new SettingDefinition(key: 'badges.low_stock', scope: SettingScope::Store, type: SettingType::Boolean, default: false),
            'badges.low_stock_threshold' => new SettingDefinition(key: 'badges.low_stock_threshold', scope: SettingScope::Store, type: SettingType::Integer, default: 5),
            'badges.featured' => new SettingDefinition(key: 'badges.featured', scope: SettingScope::Store, type: SettingType::Boolean, default: true),
            'badges.out_of_stock' => new SettingDefinition(key: 'badges.out_of_stock', scope: SettingScope::Store, type: SettingType::Boolean, default: true),
            'badges.max_per_product' => new SettingDefinition(key: 'badges.max_per_product', scope: SettingScope::Store, type: SettingType::Integer, default: 2),
            // Owner decision 15 (2026-10-07; Module 05 §27): reviews and units sold on Business/Premium storefronts.
            'reviews.enabled' => new SettingDefinition(key: 'reviews.enabled', scope: SettingScope::Store, type: SettingType::Boolean, default: true),
            'reviews.auto_approve' => new SettingDefinition(key: 'reviews.auto_approve', scope: SettingScope::Store, type: SettingType::Boolean, default: false),
            'storefront.show_units_sold' => new SettingDefinition(key: 'storefront.show_units_sold', scope: SettingScope::Store, type: SettingType::Boolean, default: true),
            'storefront.units_sold_minimum' => new SettingDefinition(key: 'storefront.units_sold_minimum', scope: SettingScope::Store, type: SettingType::Integer, default: 1),
            // Phase B46 (gap G3, owner decision 1; Module 33 §29, Module 14 §36, Module 13 §62, Module 29 §95–96):
            // how the store charges tax. Off until the store sets it up; no rate is built in.
            'tax.enabled' => new SettingDefinition(key: 'tax.enabled', scope: SettingScope::Store, type: SettingType::Boolean, default: false),
            'tax.prices_include_tax' => new SettingDefinition(key: 'tax.prices_include_tax', scope: SettingScope::Store, type: SettingType::Boolean, default: false),
            'tax.based_on' => new SettingDefinition(key: 'tax.based_on', scope: SettingScope::Store, type: SettingType::String, default: 'shipping', allowedValues: ['shipping', 'billing', 'store']),
            'tax.shipping_taxable' => new SettingDefinition(key: 'tax.shipping_taxable', scope: SettingScope::Store, type: SettingType::Boolean, default: false),
            'tax.discount_basis' => new SettingDefinition(key: 'tax.discount_basis', scope: SettingScope::Store, type: SettingType::String, default: 'before_tax', allowedValues: ['before_tax', 'after_tax']),
            'tax.rounding' => new SettingDefinition(key: 'tax.rounding', scope: SettingScope::Store, type: SettingType::String, default: 'line', allowedValues: ['line', 'order']),
            'tax.label' => new SettingDefinition(key: 'tax.label', scope: SettingScope::Store, type: SettingType::String, default: 'Tax'),
            'api.default_rate_limit_per_minute' => new SettingDefinition(
                key: 'api.default_rate_limit_per_minute', scope: SettingScope::Platform, type: SettingType::Integer,
                default: 60, // Module 31 §17/§43 "Rate Limiting / Configuration" — Phase B18's own integration point
            ),
            'backup.retention_days' => new SettingDefinition(
                key: 'backup.retention_days', scope: SettingScope::Platform, type: SettingType::Integer,
                default: 30, // Module 23 §9/§14 "Backup Frequency / Retention" — Phase B19's own integration point
            ),
            'backup.automated_backups_enabled' => new SettingDefinition(
                key: 'backup.automated_backups_enabled', scope: SettingScope::Platform, type: SettingType::Boolean,
                default: true,
            ),
            // Phase B30 (gap G4). Owner decision 2026-09-30 §5: daily backups
            // 30 days (backup.retention_days above), monthly 12 months.
            'backup.monthly_retention_months' => new SettingDefinition(
                key: 'backup.monthly_retention_months', scope: SettingScope::Platform, type: SettingType::Integer,
                default: 12,
            ),
            'backup.rehearsal_enabled' => new SettingDefinition(
                key: 'backup.rehearsal_enabled', scope: SettingScope::Platform, type: SettingType::Boolean,
                default: true, // Module 23 §47 "Restore Testing": the weekly restore rehearsal
            ),
            // Owner decision 2026-09-30 §4: email is mandatory for critical
            // alerts, WhatsApp configurable. No address is invented: while
            // this list is empty, alerts go to every active platform staff
            // account.
            'alerts.critical_email_recipients' => new SettingDefinition(
                key: 'alerts.critical_email_recipients', scope: SettingScope::Platform, type: SettingType::StringArray,
                default: [],
            ),
            'alerts.whatsapp_enabled' => new SettingDefinition(
                key: 'alerts.whatsapp_enabled', scope: SettingScope::Platform, type: SettingType::Boolean,
                default: false,
            ),
            'alerts.whatsapp_recipients' => new SettingDefinition(
                key: 'alerts.whatsapp_recipients', scope: SettingScope::Platform, type: SettingType::StringArray,
                default: [],
            ),        ];
    }

    public static function find(string $key): ?SettingDefinition
    {
        return self::all()[$key] ?? null;
    }
}
