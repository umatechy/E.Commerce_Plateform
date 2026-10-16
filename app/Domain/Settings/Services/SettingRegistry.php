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
            // UI-1 (owner, 2026-10-16): the platform's name and logo on the public, sign-in and admin pages.
            // No logo address: the built-in Umar Techy mark is shown.
            'platform.brand_name' => new SettingDefinition(key: 'platform.brand_name', scope: SettingScope::Platform, type: SettingType::String, default: 'Umar Techy'),
            'platform.logo_url' => new SettingDefinition(key: 'platform.logo_url', scope: SettingScope::Platform, type: SettingType::String, default: null),
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
            // Phase B48 (Module 12 §29, §61, §43): payment gateways.
            // Which providers may take real (production) money: empty until Umar Techy has
            // checked a provider's integration in its sandbox.
            'payments.live_providers' => new SettingDefinition(key: 'payments.live_providers', scope: SettingScope::Platform, type: SettingType::StringArray, default: []),
            // Phase B50 (owner decision 3): SMS providers that may send real messages, after Umar Techy's
            // sender-ID, delivery and pricing checks. Empty until then.
            'messaging.live_providers' => new SettingDefinition(key: 'messaging.live_providers', scope: SettingScope::Platform, type: SettingType::StringArray, default: []),
            // Phase B51 (owner decision 5; Module 03 §35–37): closed and suspended stores are kept 90 days
            // (longer only when law or contract asks — never shorter); a permanent deletion waits a cooling
            // period in which it can still be cancelled; owners are reminded before their data is archived.
            'stores.closure_retention_days' => new SettingDefinition(key: 'stores.closure_retention_days', scope: SettingScope::Platform, type: SettingType::Integer, default: 90),
            'stores.deletion_cooling_hours' => new SettingDefinition(key: 'stores.deletion_cooling_hours', scope: SettingScope::Platform, type: SettingType::Integer, default: 72),
            'stores.closure_reminder_days' => new SettingDefinition(key: 'stores.closure_reminder_days', scope: SettingScope::Platform, type: SettingType::Integer, default: 14),
            'payments.webhook_tolerance_seconds' => new SettingDefinition(key: 'payments.webhook_tolerance_seconds', scope: SettingScope::Platform, type: SettingType::Integer, default: 300),
            'payments.order_payment_minutes' => new SettingDefinition(key: 'payments.order_payment_minutes', scope: SettingScope::Platform, type: SettingType::Integer, default: 15),
            'platform.trial_days' => new SettingDefinition(
                key: 'platform.trial_days', scope: SettingScope::Platform, type: SettingType::Integer,
                default: 14,
            ),
            // Phase B44 (Module 03 §14, §24): the store's business information.
            'store.legal_name' => new SettingDefinition(key: 'store.legal_name', scope: SettingScope::Store, type: SettingType::String, default: null),
            'store.contact_email' => new SettingDefinition(key: 'store.contact_email', scope: SettingScope::Store, type: SettingType::String, default: null),
            'store.contact_phone' => new SettingDefinition(key: 'store.contact_phone', scope: SettingScope::Store, type: SettingType::String, default: null),
            'store.country' => new SettingDefinition(key: 'store.country', scope: SettingScope::Store, type: SettingType::String, default: 'PK'),
            // Phase B52 (Module 30 §61 kill switch): every store's API keys, on or off (Security → Emergency controls).
            'platform.developer_api_enabled' => new SettingDefinition(key: 'platform.developer_api_enabled', scope: SettingScope::Platform, type: SettingType::Boolean, default: true),
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
            // Phase B48 (gap G9; Module 12 §16, §18–19, §36–37): how shoppers may pay. Changed only on the
            // Payments page (payments.provider_config). Bank transfer is offered once the account is filled in.
            // Phase B50 (gap G10; Module 21 §11, §23, §46): which order updates go by SMS and by WhatsApp,
            // and the most messages a store sends per channel per day. Changed on the Messages page only.
            'messaging.sms_events' => new SettingDefinition(key: 'messaging.sms_events', scope: SettingScope::Store, type: SettingType::StringArray, default: []),
            'messaging.whatsapp_events' => new SettingDefinition(key: 'messaging.whatsapp_events', scope: SettingScope::Store, type: SettingType::StringArray, default: []),
            'messaging.daily_limit' => new SettingDefinition(key: 'messaging.daily_limit', scope: SettingScope::Store, type: SettingType::Integer, default: 500),
            // Phase B55 (gap G16; Module 25 §5–8, §55–59): the store's installable app. Changed on the Mobile app page only.
            'pwa.enabled' => new SettingDefinition(key: 'pwa.enabled', scope: SettingScope::Store, type: SettingType::Boolean, default: true),
            'pwa.name' => new SettingDefinition(key: 'pwa.name', scope: SettingScope::Store, type: SettingType::String, default: null),
            'pwa.short_name' => new SettingDefinition(key: 'pwa.short_name', scope: SettingScope::Store, type: SettingType::String, default: null),
            'pwa.theme_color' => new SettingDefinition(key: 'pwa.theme_color', scope: SettingScope::Store, type: SettingType::String, default: null),
            'pwa.background_color' => new SettingDefinition(key: 'pwa.background_color', scope: SettingScope::Store, type: SettingType::String, default: null),
            'pwa.display' => new SettingDefinition(key: 'pwa.display', scope: SettingScope::Store, type: SettingType::String, default: 'standalone', allowedValues: ['standalone', 'minimal-ui', 'browser']),
            'pwa.icon' => new SettingDefinition(key: 'pwa.icon', scope: SettingScope::Store, type: SettingType::String, default: null),
            'pwa.install_prompt' => new SettingDefinition(key: 'pwa.install_prompt', scope: SettingScope::Store, type: SettingType::Boolean, default: true),
            'pwa.mobile_nav' => new SettingDefinition(key: 'pwa.mobile_nav', scope: SettingScope::Store, type: SettingType::String, default: 'installed', allowedValues: ['installed', 'always', 'off']),
            'pwa.push' => new SettingDefinition(key: 'pwa.push', scope: SettingScope::Store, type: SettingType::Boolean, default: false),
            'pwa.version' => new SettingDefinition(key: 'pwa.version', scope: SettingScope::Store, type: SettingType::Integer, default: 1),
            // Phase B49 (Module 13 §57, §74): couriers. Changed on the Shipping setup page only.
            'shipping.default_weight_grams' => new SettingDefinition(key: 'shipping.default_weight_grams', scope: SettingScope::Store, type: SettingType::Integer, default: 500),
            // A link customers follow to the courier's own tracking page, with {tracking} for the number. Empty: none.
            'shipping.tracking_url_template' => new SettingDefinition(key: 'shipping.tracking_url_template', scope: SettingScope::Store, type: SettingType::String, default: null),
            'payments.cod.enabled' => new SettingDefinition(key: 'payments.cod.enabled', scope: SettingScope::Store, type: SettingType::Boolean, default: true),
            'payments.cod.min_order_minor' => new SettingDefinition(key: 'payments.cod.min_order_minor', scope: SettingScope::Store, type: SettingType::Integer, default: 0), // 0: no minimum
            'payments.cod.max_order_minor' => new SettingDefinition(key: 'payments.cod.max_order_minor', scope: SettingScope::Store, type: SettingType::Integer, default: 0), // 0: no maximum
            'payments.bank_transfer.enabled' => new SettingDefinition(key: 'payments.bank_transfer.enabled', scope: SettingScope::Store, type: SettingType::Boolean, default: true),
            'payments.bank.account_title' => new SettingDefinition(key: 'payments.bank.account_title', scope: SettingScope::Store, type: SettingType::String, default: null),
            'payments.bank.bank_name' => new SettingDefinition(key: 'payments.bank.bank_name', scope: SettingScope::Store, type: SettingType::String, default: null),
            'payments.bank.account_number' => new SettingDefinition(key: 'payments.bank.account_number', scope: SettingScope::Store, type: SettingType::String, default: null), // account number or IBAN
            'payments.bank.instructions' => new SettingDefinition(key: 'payments.bank.instructions', scope: SettingScope::Store, type: SettingType::String, default: null),
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
