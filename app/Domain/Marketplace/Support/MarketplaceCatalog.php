<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Support;

use App\Domain\DeveloperPlatform\Support\WebhookEventCatalog;

/**
 * Phase B56 (Module 27 §6–7, §13–16, §45, §71–73) — the fixed vocabulary
 * of the app marketplace.
 *
 * Apps act only through the developer API and webhooks, so the
 * permissions an app can ask for are the developer API scopes that have
 * endpoints (read-only today) and the webhook events of the catalog.
 * Writing permissions (§13 *.write) come with write endpoints, not before.
 */
final class MarketplaceCatalog
{
    /** Module 27 §6, the kinds that make sense for the platform today. */
    public const CATEGORIES = [
        'accounting' => 'Accounting',
        'analytics' => 'Analytics & reports',
        'communication' => 'Messages & chat',
        'crm' => 'Customer management',
        'marketing' => 'Marketing',
        'shipping' => 'Shipping & delivery',
        'inventory' => 'Inventory & suppliers',
        'productivity' => 'Productivity',
        'social_commerce' => 'Social selling',
        'customer_support' => 'Customer support',
        'developer_tool' => 'Developer tools',
    ];

    /**
     * Permission => [what it lets the app see, risk] (§13, §15). Customer
     * data and orders (names, addresses, phone numbers) are personal data:
     * high risk.
     */
    public const SCOPES = [
        'products:read' => ['See your products, prices and pictures', 'low'],
        'inventory:read' => ['See your stock levels', 'low'],
        'orders:read' => ['See your orders, including customers’ names, addresses and phone numbers', 'high'],
        'customers:read' => ['See your customers’ names, email addresses and phone numbers', 'high'],
    ];

    public const OWNERSHIP = ['umar_techy' => 'Umar Techy', 'partner' => 'Partner', 'third_party' => 'Third party'];

    /** §73: shown to stores; never a reason to skip a permission check. */
    public const TRUST = [
        'platform' => 'Made by Umar Techy',
        'verified' => 'Verified publisher',
        'reviewed' => 'Reviewed by Umar Techy',
        'unverified' => 'Not verified',
    ];

    /** §45: the kinds of field a settings form may have. */
    public const FIELD_TYPES = ['text', 'number', 'boolean', 'select', 'url', 'secret'];

    /** §16, §71–72: what a reviewer confirms before a version is published. */
    public const REVIEW_CHECKS = [
        'permissions' => 'It asks only for the permissions its features need.',
        'data_use' => 'Its data use statement is complete and matches what it does.',
        'privacy' => 'Its privacy policy is published at the address given.',
        'security' => 'Its addresses use https and it handles credentials and webhook signatures safely.',
        'tested' => 'It was installed and tried on a test store.',
        'support' => 'Stores can reach the publisher for support.',
    ];

    /**
     * The scopes an app may ask for: those of SCOPES, each a developer API
     * scope with endpoints (a test checks they are ApiScope values).
     *
     * @return list<string>
     */
    public static function scopes(): array
    {
        return array_keys(self::SCOPES);
    }

    /** @return list<string> */
    public static function events(): array
    {
        return array_keys(WebhookEventCatalog::EVENTS);
    }

    /**
     * Problems with a settings form definition (§45–46).
     *
     * @param mixed $schema
     * @return list<string>
     */
    public static function schemaProblems(mixed $schema): array
    {
        if (! is_array($schema) || ! array_is_list($schema)) {
            return ['The settings form must be a list of fields.'];
        }
        $problems = [];
        $keys = [];
        foreach ($schema as $i => $field) {
            $n = $i + 1;
            if (! is_array($field)) {
                $problems[] = "Field {$n} is not an object.";
                continue;
            }
            $key = (string) ($field['key'] ?? '');
            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1) {
                $problems[] = "Field {$n}: key must be small letters, digits and _ (up to 40).";
            } elseif (in_array($key, $keys, true)) {
                $problems[] = "Field {$n}: key \"{$key}\" is used twice.";
            }
            $keys[] = $key;
            if (trim((string) ($field['label'] ?? '')) === '' || mb_strlen((string) $field['label']) > 80) {
                $problems[] = "Field {$n}: a label of up to 80 characters is required.";
            }
            if (! in_array($field['type'] ?? null, self::FIELD_TYPES, true)) {
                $problems[] = "Field {$n}: type must be one of ".implode(', ', self::FIELD_TYPES).'.';
            }
            if (($field['type'] ?? null) === 'select' && (! is_array($field['options'] ?? null) || $field['options'] === [] || count($field['options']) > 50)) {
                $problems[] = "Field {$n}: a select needs 1–50 options.";
            }
            foreach (array_keys($field) as $attribute) {
                if (! in_array($attribute, ['key', 'label', 'type', 'required', 'options', 'help', 'max'], true)) {
                    $problems[] = "Field {$n}: unknown attribute \"{$attribute}\".";
                }
            }
        }
        if (count($schema) > 30) {
            $problems[] = 'A settings form has at most 30 fields.';
        }

        return $problems;
    }

    /**
     * The store's values for a settings form, checked against it (§46).
     * A secret left empty keeps the value saved before.
     *
     * @param list<array<string, mixed>> $schema
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array{0: array<string, mixed>, 1: array<string, string>} values, errors by key
     */
    public static function validateConfig(array $schema, array $input, array $current): array
    {
        $values = [];
        $errors = [];
        foreach ($schema as $field) {
            $key = (string) $field['key'];
            $raw = $input[$key] ?? null;
            $type = (string) $field['type'];
            if ($type === 'secret' && ($raw === null || $raw === '')) {
                $raw = $current[$key] ?? null;
            }
            if (($raw === null || $raw === '') && ! empty($field['required'])) {
                $errors[$key] = "{$field['label']} is required.";
                continue;
            }
            if ($raw === null || $raw === '') {
                continue;
            }
            $max = (int) ($field['max'] ?? 500);
            $value = match ($type) {
                'boolean' => is_bool($raw) ? $raw : null,
                'number' => is_numeric($raw) ? $raw + 0 : null,
                'select' => in_array($raw, (array) ($field['options'] ?? []), true) ? $raw : null,
                'url' => is_string($raw) && filter_var($raw, FILTER_VALIDATE_URL) !== false && str_starts_with($raw, 'https://') && mb_strlen($raw) <= 500 ? $raw : null,
                default => is_string($raw) && mb_strlen($raw) <= $max ? $raw : null,
            };
            if ($value === null) {
                $errors[$key] = match ($type) {
                    'url' => "{$field['label']} must be an https:// address.",
                    'number' => "{$field['label']} must be a number.",
                    'select' => "Choose one of the offered values for {$field['label']}.",
                    'boolean' => "{$field['label']} must be on or off.",
                    default => "{$field['label']} is too long (at most {$max} characters).",
                };
                continue;
            }
            $values[$key] = $value;
        }

        return [$values, $errors];
    }

    public static function semver(string $version): bool
    {
        return preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $version) === 1;
    }
}
