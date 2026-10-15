<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * Phase B53 (SRS API-012; Module 31 §2.24, §51, §65) — the OpenAPI 3.1
 * description of the developer API (/api/dev/v1) and its webhooks.
 *
 * Built from the routes themselves plus the documentation below: a route
 * under /api/dev/v1 without an entry in ENDPOINTS stops the build (and the
 * test), so the reference can never silently fall behind. `php artisan
 * api:openapi` writes docs/api/openapi-developer-v1.json; CI fails when the
 * committed file differs from what the code produces.
 */
final class DeveloperApiSpec
{
    public const PREFIX = 'api/dev/v1';

    /** "METHOD /path" => [summary, description, scope, schema, list] */
    public const ENDPOINTS = [
        'GET /products' => ['List products', 'The store’s products, ordered by id. Includes every status (draft, active, archived).', 'products:read', 'Product', true],
        'GET /products/{product}' => ['Get a product', 'One product by its id.', 'products:read', 'Product', false],
        'GET /orders' => ['List orders', 'The store’s orders, newest first.', 'orders:read', 'Order', true],
        'GET /orders/{order}' => ['Get an order', 'One order by its id.', 'orders:read', 'Order', false],
        'GET /customers' => ['List customers', 'The store’s customers, ordered by id. Contains personal data: keep it as safe as the store does.', 'customers:read', 'Customer', true],
        'GET /customers/{customer}' => ['Get a customer', 'One customer by its id.', 'customers:read', 'Customer', false],
        'GET /inventory' => ['List stock levels', 'Stock per product and warehouse, ordered by id. `available` is on hand minus reserved for open orders.', 'inventory:read', 'Inventory', true],
    ];

    /**
     * Phase B56 (Module 27 §34): the calls a marketplace app's server makes to
     * connect and to read its own installation. "METHOD /path" => [summary, description].
     */
    public const APP_ENDPOINTS = [
        'POST /oauth/token' => ['Exchange a connection code', 'When a store installs your marketplace app, its browser is sent to your redirect address with `code`, `store` and `installation`. Within 10 minutes, exchange the code from your server, with your client id and secret. The answer holds the store’s API key for your app (only the scopes the store granted) and your webhook signing secret. Both are shown once: keep them on your server. A code works once. Every failure answers 400 `invalid_grant`.'],
        'GET /installation' => ['Get your installation', 'Your app’s installation on the key’s store: the granted scopes and events, and the store’s settings for your app (as defined by your settings form). Any key of the installation works; no scope is needed.'],
    ];

    /** schema => property => [type, description, example, nullable] */
    public const SCHEMAS = [
        'Product' => [
            'id' => ['integer', 'Product id.', 1042, false], 'name' => ['string', 'Product name.', 'Rose Attar 12ml', false],
            'slug' => ['string', 'Address part on the storefront.', 'rose-attar-12ml', false], 'sku' => ['string', 'Stock keeping unit.', 'ATR-001', true],
            'price_minor' => ['integer', 'Price in minor units (paisa for PKR).', 250000, false], 'currency' => ['string', 'ISO 4217 code.', 'PKR', false],
            'status' => ['string', 'draft, active or archived.', 'active', false],
        ],
        'Order' => [
            'id' => ['integer', 'Order id.', 5581, false], 'order_number' => ['string', 'The number customers see.', 'ORD-000123', false],
            'status' => ['string', 'Order status, e.g. confirmed, shipped, delivered, cancelled.', 'confirmed', false],
            'grand_total_minor' => ['integer', 'Total in minor units.', 177000, false], 'currency' => ['string', 'ISO 4217 code.', 'PKR', false],
            'created_at' => ['string', 'When it was placed (ISO 8601, UTC).', '2026-10-14T10:30:00+00:00', false],
        ],
        'Customer' => [
            'id' => ['integer', 'Customer id.', 311, false], 'name' => ['string', 'Name.', 'Ayesha Khan', false],
            'email' => ['string', 'Email address.', 'ayesha@example.com', true], 'created_at' => ['string', 'When the customer was created (ISO 8601).', '2026-09-01T08:00:00+00:00', false],
        ],
        'Inventory' => [
            'product_id' => ['integer', 'Product id.', 1042, false], 'warehouse_id' => ['integer', 'Warehouse id.', 3, false],
            'on_hand' => ['integer', 'Units in the warehouse.', 40, false], 'reserved' => ['integer', 'Units held for open orders.', 2, false],
            'available' => ['integer', 'on_hand − reserved.', 38, false],
        ],
    ];

    /** @return array<string, mixed> */
    public static function build(): array
    {
        $paths = [];
        foreach (self::routes() as $route) {
            $path = '/'.substr($route->uri(), strlen(self::PREFIX) + 1);
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = "{$method} {$path}";
                if (isset(self::APP_ENDPOINTS[$key])) {
                    $paths[$path][strtolower($method)] = self::appOperation($key);
                    continue;
                }
                if (! isset(self::ENDPOINTS[$key])) {
                    throw new \LogicException("{$key} is a developer API route without documentation in DeveloperApiSpec::ENDPOINTS.");
                }
                $paths[$path][strtolower($method)] = self::operation($key, $path);
            }
        }
        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Umar Techy Developer API',
                'version' => 'v1',
                'description' => implode("\n\n", [
                    'Read a store’s products, orders, customers and stock, and receive its events by webhook.',
                    '**Authentication.** Create an application and an API key under Settings → Developer. Send it as `Authorization: Bearer <key>`. A key belongs to one store and carries scopes; a call outside its scopes gets 403.',
                    '**Rate limits.** 60 requests a minute per key by default (Umar Techy may set another limit). Over the limit you get 429 with `Retry-After`.',
                    '**Errors.** Every error has the same body: `code` (stable), `message`, `details` and `requestId`. Validation errors list the fields in `details.fields`. Send the `requestId` (also in the `X-Request-Id` header) when you contact support.',
                    '**Retries.** Do not retry 4xx answers unchanged. Retry 429, 503 and network failures with back-off.',
                    '**Versioning.** The version is in the path. Fields may be added within v1; nothing is removed or renamed without a new version.',
                    '**Availability.** Keys stop working when their store is suspended or closed (423) and when Umar Techy switches the developer API off during an incident (503).',
                    '**Marketplace apps.** An app listed in the Umar Techy marketplace gets its keys when a store installs it: the store’s browser brings a one-time code to the app’s redirect address, and the app exchanges it at `POST /oauth/token`. Uninstalling, or Umar Techy suspending the app, stops its keys and webhooks at once.',
                ]),
            ],
            'servers' => [['url' => '/api/dev/v1', 'description' => 'Relative to the platform address']],
            'security' => [['apiKey' => []]],
            'paths' => $paths,
            'webhooks' => self::webhooks(),
            'components' => [
                'securitySchemes' => ['apiKey' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'An API key from Settings → Developer.']],
                'schemas' => [...self::schemas(), 'Error' => self::errorSchema(), 'PageLinks' => self::linksSchema(), 'PageMeta' => self::metaSchema()],
                'responses' => [
                    'Unauthenticated' => self::errorResponse('No or an invalid API key.', 'unauthenticated'),
                    'Forbidden' => self::errorResponse('The key does not have the scope this call needs.', 'insufficient_scope'),
                    'NotFound' => self::errorResponse('No such record in this store.', 'not_found'),
                    'Locked' => self::errorResponse('The store is suspended, closed or archived.', 'store_not_operational'),
                    'TooManyRequests' => self::errorResponse('Rate limit reached. Wait for Retry-After seconds.', 'too_many_requests'),
                    'Unavailable' => self::errorResponse('The developer API is switched off for now.', 'developer_api_disabled'),
                ],
            ],
        ];
    }

    public static function json(): string
    {
        return json_encode(self::build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /** @return list<Route> */
    public static function routes(): array
    {
        return array_values(array_filter(Router::getRoutes()->getRoutes(), fn (Route $r) => str_starts_with($r->uri(), self::PREFIX.'/') && $r->uri() !== self::PREFIX.'/openapi.json'));
    }

    /** @return array<string, mixed> */
    private static function operation(string $key, string $path): array
    {
        [$summary, $description, $scope, $schema, $list] = self::ENDPOINTS[$key];
        $parameters = [];
        if (preg_match('/\{(\w+)\}/', $path, $m) === 1) {
            $parameters[] = ['name' => $m[1], 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => "The {$m[1]} id."];
        }
        if ($list) {
            $parameters[] = ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]];
            $parameters[] = ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]];
        }
        $data = ['$ref' => "#/components/schemas/{$schema}"];
        $body = $list
            ? ['type' => 'object', 'required' => ['data', 'links', 'meta'], 'properties' => ['data' => ['type' => 'array', 'items' => $data], 'links' => ['$ref' => '#/components/schemas/PageLinks'], 'meta' => ['$ref' => '#/components/schemas/PageMeta']]]
            : ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => $data]];

        return array_filter([
            'operationId' => lcfirst(str_replace(' ', '', ucwords(strtolower($summary)))),
            'summary' => $summary,
            'description' => $description."\n\nScope: `{$scope}`.",
            'tags' => [$schema],
            'parameters' => $parameters === [] ? null : $parameters,
            'responses' => array_filter([
                '200' => ['description' => 'OK', 'headers' => ['X-Request-Id' => ['schema' => ['type' => 'string']]], 'content' => ['application/json' => ['schema' => $body]]],
                '401' => ['$ref' => '#/components/responses/Unauthenticated'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
                '404' => $list ? null : ['$ref' => '#/components/responses/NotFound'],
                '423' => ['$ref' => '#/components/responses/Locked'],
                '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                '503' => ['$ref' => '#/components/responses/Unavailable'],
            ]),
            'x-scope' => $scope,
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    private static function appOperation(string $key): array
    {
        [$summary, $description] = self::APP_ENDPOINTS[$key];
        $error = ['$ref' => '#/components/schemas/Error'];
        $text = ['type' => 'string'];
        if ($key === 'POST /oauth/token') {
            return [
                'operationId' => 'exchangeConnectionCode', 'summary' => $summary, 'description' => $description, 'tags' => ['Marketplace apps'], 'security' => [],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['grant_type', 'code', 'client_id', 'client_secret', 'redirect_uri'], 'properties' => [
                    'grant_type' => ['type' => 'string', 'enum' => ['authorization_code']], 'code' => $text, 'client_id' => $text, 'client_secret' => $text,
                    'redirect_uri' => ['type' => 'string', 'description' => 'The registered redirect address the code was sent to.'],
                ]]]]],
                'responses' => [
                    '200' => ['description' => 'Connected', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['token_type', 'scope', 'installation', 'store'], 'properties' => [
                        'access_token' => ['type' => ['string', 'null'], 'description' => 'The store’s API key for your app; null when it asked for no scope.'],
                        'token_type' => ['type' => 'string', 'examples' => ['Bearer']], 'scope' => ['type' => 'string', 'description' => 'Granted scopes, space-separated.'],
                        'webhook_signing_secret' => ['type' => ['string', 'null'], 'description' => 'Signs the webhooks sent to your webhook address; null without events.'],
                        'installation' => $text, 'store' => ['type' => 'object', 'properties' => ['id' => $text, 'name' => $text, 'slug' => $text]],
                    ]]]]],
                    '400' => ['description' => 'invalid_grant', 'content' => ['application/json' => ['schema' => $error]]],
                    '422' => ['description' => 'A field is missing or malformed.', 'content' => ['application/json' => ['schema' => $error]]],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ];
        }

        return [
            'operationId' => 'getInstallation', 'summary' => $summary, 'description' => $description, 'tags' => ['Marketplace apps'],
            'responses' => [
                '200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'object', 'properties' => [
                    'installation' => $text, 'app' => ['type' => 'object'], 'store' => ['type' => 'object'],
                    'scopes' => ['type' => 'array', 'items' => $text], 'events' => ['type' => 'array', 'items' => $text],
                    'settings' => ['type' => 'object', 'description' => 'The store’s values for your settings form.'],
                ]]]]]]],
                '401' => ['$ref' => '#/components/responses/Unauthenticated'],
                '404' => ['$ref' => '#/components/responses/NotFound'],
                '423' => ['$ref' => '#/components/responses/Locked'],
                '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                '503' => ['$ref' => '#/components/responses/Unavailable'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function schemas(): array
    {
        $out = [];
        foreach (self::SCHEMAS as $name => $properties) {
            $out[$name] = [
                'type' => 'object',
                'required' => array_keys($properties),
                'properties' => array_map(fn (array $p) => [
                    'type' => $p[3] ? [$p[0], 'null'] : $p[0], 'description' => $p[1], 'examples' => [$p[2]],
                ], $properties),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function webhooks(): array
    {
        $out = [];
        foreach (WebhookEventCatalog::EVENTS as $event => [$description, $fields]) {
            $out[$event] = ['post' => [
                'operationId' => 'webhook'.str_replace(' ', '', ucwords(str_replace(['.', '_'], ' ', $event))),
                'summary' => $event,
                'description' => $description."\n\nSent as POST to your webhook address. Check `X-Umartechy-Signature`: the HMAC-SHA256 (hex) of the raw body with your webhook signing secret. Answer 2xx within 10 seconds; otherwise the delivery is retried after 10 s, 30 s, 1 min, 5 min and 15 min. Use `idempotency_key` to ignore a delivery you already handled.",
                'parameters' => [
                    ['name' => 'X-Umartechy-Signature', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'X-Umartechy-Event', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'const' => $event]],
                ],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'required' => ['event', 'data', 'idempotency_key'],
                    'properties' => [
                        'event' => ['type' => 'string', 'const' => $event],
                        'data' => ['type' => 'object', 'properties' => array_map(fn (array $f) => ['type' => $f[0], 'description' => $f[1]], $fields)],
                        'idempotency_key' => ['type' => 'string', 'description' => 'The same for every retry of this delivery.'],
                    ],
                ]]]],
                'responses' => ['2XX' => ['description' => 'Received.']],
            ]];
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, mixed> */
    private static function errorSchema(): array
    {
        return ['type' => 'object', 'required' => ['code', 'message', 'details', 'requestId'], 'properties' => [
            'code' => ['type' => 'string', 'description' => 'Stable, lowercase code to act on, e.g. `unauthenticated`, `insufficient_scope`, `not_found`, `too_many_requests`.'],
            'message' => ['type' => 'string', 'description' => 'Safe, human-readable text.'],
            'details' => ['type' => 'object', 'description' => 'More about the error; `fields` lists invalid fields on 422.'],
            'requestId' => ['type' => ['string', 'null'], 'description' => 'Equals the X-Request-Id header.'],
        ]];
    }

    /** @return array<string, mixed> */
    private static function errorResponse(string $description, string $code): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error'], 'example' => ['code' => $code, 'message' => $description, 'details' => new \stdClass(), 'requestId' => '5b0f6a3e-2c3d-4c1e-9a77-2f1d0e6c8b11']]]];
    }

    /** @return array<string, mixed> */
    private static function linksSchema(): array
    {
        return ['type' => 'object', 'properties' => ['first' => ['type' => ['string', 'null']], 'last' => ['type' => ['string', 'null']], 'prev' => ['type' => ['string', 'null']], 'next' => ['type' => ['string', 'null']]]];
    }

    /** @return array<string, mixed> */
    private static function metaSchema(): array
    {
        return ['type' => 'object', 'properties' => ['current_page' => ['type' => 'integer'], 'last_page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'total' => ['type' => 'integer'], 'from' => ['type' => ['integer', 'null']], 'to' => ['type' => ['integer', 'null']]]];
    }
}
