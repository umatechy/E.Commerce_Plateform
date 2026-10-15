<?php

declare(strict_types=1);

namespace App\Domain\Help\Support;

/**
 * Phase B54 (Module 34 §4–7, §10, §15) — the fixed vocabulary of the help
 * center: who an article is for, what kind of article it is, and where it
 * sits in the information architecture.
 *
 * Categories follow Module 34 §5. Areas the platform does not have yet
 * (hosting, PWA, AI, marketplace, affiliates) get no category: the help
 * center documents what exists (§2.1 "accurate").
 */
final class HelpCatalog
{
    /**
     * Audiences (Module 34 §3, §15). Visibility follows them on the server:
     * see HelpAudience.
     */
    public const AUDIENCES = [
        'customer' => 'Shoppers',
        'merchant' => 'Store owners and staff',
        'owner' => 'Store owners only',
        'developer' => 'Developers',
        'platform' => 'Umar Techy staff (internal)',
    ];

    /** Module 34 §7 "security classification", derived from the audience. */
    public const CLASSIFICATION = [
        'customer' => 'public',
        'merchant' => 'signed-in',
        'owner' => 'signed-in',
        'developer' => 'signed-in',
        'platform' => 'internal',
    ];

    /** Module 34 §4. */
    public const TYPES = [
        'guide' => 'Guide',
        'how_to' => 'How-to',
        'troubleshooting' => 'Troubleshooting',
        'faq' => 'Question and answer',
        'reference' => 'Reference',
        'runbook' => 'Runbook',
    ];

    /**
     * Troubleshooting articles follow one framework (Module 34 §24): these
     * headings, in this order. HelpContentChecker enforces it.
     */
    public const TROUBLESHOOTING_HEADINGS = [
        'Problem', 'Symptoms', 'Likely causes', 'Safe checks', 'Resolution steps', 'Verification', 'Prevention', 'When to contact support',
    ];

    /**
     * category => [English label, Urdu label or null, audiences it is used for, Module 34 §5 letter]
     */
    public const CATEGORIES = [
        'getting_started' => ['Getting started', null, ['merchant', 'owner', 'developer', 'platform'], 'A'],
        'store_setup' => ['Store setup', null, ['merchant', 'owner'], 'B'],
        'products' => ['Products & catalog', null, ['merchant'], 'C'],
        'inventory' => ['Inventory', null, ['merchant'], 'D'],
        'orders' => ['Orders', 'آرڈر', ['merchant', 'customer'], 'E'],
        'customers' => ['Customers', null, ['merchant'], 'F'],
        'checkout' => ['Cart & checkout', 'کارٹ اور چیک آؤٹ', ['merchant', 'customer'], 'G'],
        'payments' => ['Payments', 'ادائیگی', ['merchant', 'owner', 'customer'], 'H'],
        'shipping' => ['Shipping & delivery', 'ترسیل', ['merchant', 'customer'], 'I'],
        'returns' => ['Returns & refunds', 'واپسی اور رقم کی واپسی', ['merchant', 'customer'], 'E'],
        'promotions' => ['Discounts & promotions', null, ['merchant'], 'J'],
        'marketing' => ['Marketing & messages', null, ['merchant'], 'K'],
        'content' => ['SEO & content', null, ['merchant'], 'L'],
        'themes' => ['Themes & branding', null, ['merchant'], 'M'],
        'domains' => ['Domains', null, ['merchant', 'owner'], 'N'],
        'reports' => ['Reports & store health', null, ['merchant'], 'Q'],
        'backups' => ['Backup & recovery', null, ['merchant', 'owner', 'platform'], 'R'],
        'billing' => ['Billing & packages', null, ['merchant', 'owner'], 'X'],
        'developers' => ['API & developers', null, ['developer'], 'Y'],
        'security' => ['Security & privacy', 'رازداری اور حفاظت', ['merchant', 'owner', 'customer', 'platform'], 'Z'],
        'account' => ['Your account', 'آپ کا اکاؤنٹ', ['customer'], 'Z'],
        'troubleshooting' => ['Troubleshooting', null, ['merchant', 'owner', 'developer'], 'AA'],
        'support' => ['Support', 'مدد اور رابطہ', ['merchant', 'owner', 'customer', 'platform'], 'AC'],
    ];

    /** Why a reader found an article not helpful (Module 34 §28). */
    public const FEEDBACK_REASONS = [
        'missing' => 'Something is missing',
        'outdated' => 'It is out of date',
        'unclear' => 'It is hard to follow',
        'incorrect' => 'Something is wrong',
        'other' => 'Something else',
    ];

    /** Release note sections (Module 34 §31–32), in reading order. */
    public const RELEASE_SECTIONS = [
        'added' => 'New',
        'changed' => 'Improved',
        'fixed' => 'Fixed',
        'security' => 'Security',
        'deprecated' => 'Going away',
        'removed' => 'Removed',
        'breaking' => 'Breaking changes',
        'migration' => 'What you need to do',
        'known_issues' => 'Known issues',
    ];

    public const STATUSES = ['draft', 'in_review', 'published', 'deprecated', 'archived'];

    /** Statuses readers can see. A deprecated article stays readable, marked, with its replacement. */
    public const READABLE = ['published', 'deprecated'];

    public static function categoryLabel(string $category, string $locale = 'en'): string
    {
        $entry = self::CATEGORIES[$category] ?? null;
        if ($entry === null) {
            return $category;
        }

        return $locale === 'ur' && $entry[1] !== null ? $entry[1] : $entry[0];
    }

    /** The product version this code is (config/help.php), e.g. "1.1". */
    public static function productVersion(): string
    {
        return (string) config('help.product_version');
    }

    /** "1.1" <= "1.2", "1.10" > "1.9". */
    public static function versionAtMost(string $version, string $limit): bool
    {
        return version_compare(self::normalizeVersion($version), self::normalizeVersion($limit), '<=');
    }

    private static function normalizeVersion(string $version): string
    {
        return substr_count($version, '.') === 1 ? $version.'.0' : $version;
    }
}
