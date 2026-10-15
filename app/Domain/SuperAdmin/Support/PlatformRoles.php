<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Support;

/**
 * Phase B52 (Module 30 §3 "Super Admin Role Model", §16–17; Module 32 §65,
 * §67 least privilege for support staff) — what each kind of Umar Techy
 * staff member may do on the platform.
 *
 * Until B52 every platform staff member could do everything (users.platform_role
 * was only "is staff or not"). Now a role grants capabilities, and every
 * Super Admin route needs one (ROUTES; a test fails for a route without
 * one). Reading the Super Admin is open to every role ("view"): what
 * changes things is split by area.
 *
 * Roles of the blueprint not used yet (finance, content, marketplace,
 * partner, analytics, compliance, technical) have no module of their own
 * here; they can be added as their modules are built.
 */
final class PlatformRoles
{
    public const CAPABILITIES = [
        'view' => 'See the Super Admin pages',
        'stores' => 'Create stores, invite owners, suspend, close, archive and restore stores, suspend domains',
        'impersonate' => 'Open a store as its staff for support',
        'store_deletion' => 'Permanently delete store data, set legal holds',
        'catalog' => 'Packages, themes and starter templates',
        'billing' => 'Invoices, payments, credit notes, prices and stores’ packages and subscriptions',
        'providers' => 'Payment, SMS and WhatsApp providers and Umar Techy’s own accounts',
        'settings' => 'Platform settings',
        'backups' => 'Take, check and rehearse backups, authorise restores',
        'support' => 'Answer support requests from stores',
        'users' => 'Deactivate and reactivate user accounts',
        'developer' => 'Suspend developer applications',
        'security' => 'Platform staff roles, sessions, incidents and emergency controls',
        'help' => 'Write, review and publish help articles; publish release notes', // Phase B54
        'marketplace' => 'Marketplace apps: list, review and publish, suspend (kill switch)', // Phase B56
    ];

    /** role => [label, what it is for, capabilities] */
    public const ROLES = [
        'super_admin' => ['Super admin', 'Everything, including staff roles and permanent deletion. Keep this to very few people.', ['*']],
        'platform_admin' => ['Platform admin', 'Runs the platform day to day: stores, packages, billing, providers, settings, backups, support.',
            ['view', 'stores', 'impersonate', 'catalog', 'billing', 'providers', 'settings', 'backups', 'support', 'users', 'developer', 'help', 'marketplace']],
        'operations_admin' => ['Operations admin', 'Stores, backups and support.', ['view', 'stores', 'impersonate', 'backups', 'support', 'developer']],
        'support_admin' => ['Support admin', 'Answers stores, opens a store to help and writes help articles. Changes nothing else.', ['view', 'impersonate', 'support', 'help']],
        'billing_admin' => ['Billing admin', 'Invoices, payments, credit notes and prices.', ['view', 'billing']],
        'security_admin' => ['Security admin', 'Staff roles, sessions, incidents, emergency controls and user accounts.', ['view', 'security', 'users', 'developer']],
        'read_only_auditor' => ['Read-only auditor', 'Sees everything, changes nothing.', ['view']],
    ];

    /** Values from before B52, read as the closest role (fail closed for anything else). */
    private const LEGACY = ['support_agent' => 'support_admin', 'catalog_manager' => 'read_only_auditor'];

    /**
     * Every Super Admin route that changes something, by the start of its
     * path under api/v1/super-admin/ (most specific first). Reads (GET)
     * need "view", except opening a store as its staff.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const ROUTES = [
        ['stores/{store}/impersonate', 'impersonate'],
        ['stores/{store}/lifecycle/deletion', 'store_deletion'],
        ['stores/{store}/lifecycle/legal-hold', 'store_deletion'],
        ['stores/{store}/starter-template', 'catalog'],
        ['stores/{store}/subscription', 'billing'], // a store's package and subscription are billing (Module 30 §29)
        ['stores', 'stores'],
        ['packages', 'catalog'],
        ['themes', 'catalog'],
        ['starter-templates', 'catalog'],
        ['billing', 'billing'],
        ['payment-providers', 'providers'],
        ['messaging', 'providers'],
        ['settings', 'settings'],
        ['backups', 'backups'],
        ['restore-jobs', 'backups'],
        ['support', 'support'],
        ['users/{user}/sessions', 'security'],
        ['users/{user}/platform-role', 'security'],
        ['users', 'users'],
        ['developer', 'developer'],
        ['security', 'security'],
        ['help', 'help'], // Phase B54
        ['marketplace', 'marketplace'], // Phase B56
    ];

    public static function normalize(?string $role): ?string
    {
        if ($role === null || $role === '') {
            return null;
        }

        return isset(self::ROLES[$role]) ? $role : (self::LEGACY[$role] ?? 'read_only_auditor');
    }

    /** @return list<string> */
    public static function capabilitiesOf(?string $role): array
    {
        $role = self::normalize($role);
        if ($role === null) {
            return [];
        }
        $caps = self::ROLES[$role][2];

        return $caps === ['*'] ? array_keys(self::CAPABILITIES) : $caps;
    }

    public static function can(?string $role, string $capability): bool
    {
        return in_array($capability, self::capabilitiesOf($role), true);
    }

    public static function label(?string $role): string
    {
        $role = self::normalize($role);

        return $role === null ? 'Not platform staff' : self::ROLES[$role][0];
    }

    /**
     * The capability a Super Admin request needs; null when the route is
     * not covered (refused — every change must be covered).
     *
     * @param string $uri the route's own pattern, e.g. "api/v1/super-admin/stores/{store}/lifecycle/suspend"
     */
    public static function capabilityFor(string $method, string $uri): ?string
    {
        $path = (string) preg_replace('#^api/v1/super-admin/?#', '', $uri);
        $reading = in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($reading && $path !== 'stores/{store}/impersonate') {
            return 'view';
        }
        foreach (self::ROUTES as [$prefix, $capability]) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $capability;
            }
        }

        return null;
    }
}
