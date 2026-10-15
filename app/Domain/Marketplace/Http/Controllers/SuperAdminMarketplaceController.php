<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Marketplace\Models\AppInstallation;
use App\Domain\Marketplace\Models\MarketplaceApp;
use App\Domain\Marketplace\Models\MarketplaceAppVersion;
use App\Domain\Marketplace\Services\AppInstaller;
use App\Domain\Marketplace\Services\MarketplaceRefused;
use App\Domain\Marketplace\Services\MarketplaceRegistry;
use App\Domain\Marketplace\Support\MarketplaceCatalog;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase B56 — Super Admin → Marketplace (Module 27 §8–9, §16, §56–59,
 * §68, §71, §85–86). Every platform role can look; changing needs the
 * "marketplace" capability (PlatformRoles::ROUTES), and the kill switch
 * and secret rotation ask for step-up.
 */
final class SuperAdminMarketplaceController
{
    public function __construct(private readonly MarketplaceRegistry $registry) {}

    public function index(): JsonResponse
    {
        $apps = MarketplaceApp::query()->with('currentVersion')->withCount(['installations as active_installations' => fn ($q) => $q->withoutGlobalScopes()->where('status', 'active')])
            ->orderBy('name')->get();
        $pending = MarketplaceAppVersion::query()->where('status', 'in_review')->pluck('marketplace_app_id')->all();

        return response()->json([
            'data' => $apps->map(fn (MarketplaceApp $a) => [
                'id' => $a->public_id, 'slug' => $a->slug, 'name' => $a->name, 'publisher' => $a->publisher_name, 'category' => $a->category,
                'status' => $a->status, 'trust' => $a->trust, 'version' => $a->currentVersion?->version, 'installations' => $a->active_installations,
                'waiting_for_review' => in_array($a->id, $pending, true),
            ])->all(),
            'meta' => $this->catalog(),
        ]);
    }

    public function show(Request $request, MarketplaceApp $app, AppInstaller $installer): JsonResponse
    {
        $installations = AppInstallation::query()->withoutGlobalScopes()->where('marketplace_app_id', $app->id)->orderByDesc('id')->limit(100)->get();
        $stores = Store::query()->withoutGlobalScopes()->whereIn('id', $installations->pluck('store_id'))->pluck('name', 'id');

        return response()->json(['data' => [
            'id' => $app->public_id, 'slug' => $app->slug, 'name' => $app->name, 'tagline' => $app->tagline, 'description' => $app->description,
            'category' => $app->category, 'publisher_name' => $app->publisher_name, 'publisher_website' => $app->publisher_website, 'support_email' => $app->support_email,
            'privacy_url' => $app->privacy_url, 'terms_url' => $app->terms_url, 'ownership' => $app->ownership, 'trust' => $app->trust, 'status' => $app->status,
            'pricing' => $app->pricing, 'client_id' => $app->client_id, 'redirect_uris' => $app->redirect_uris, 'webhook_url' => $app->webhook_url,
            'required_features' => $app->required_features, 'suspended_reason' => $app->suspended_reason, 'suspended_at' => $app->suspended_at?->toIso8601String(),
            'versions' => $app->versions()->get()->map(fn (MarketplaceAppVersion $v) => [
                'version' => $v->version, 'status' => $v->status, 'scopes' => $v->scopes, 'events' => $v->events, 'config_schema' => $v->config_schema,
                'data_use' => $v->data_use, 'release_notes' => $v->release_notes, 'min_platform_version' => $v->min_platform_version,
                'submitted_by_me' => $v->submitted_by === $request->user()?->id, 'review' => $v->review, 'published_at' => $v->published_at?->toIso8601String(),
            ])->all(),
            'installations' => $installations->map(fn (AppInstallation $i) => [
                'id' => $i->public_id, 'store' => $stores[$i->store_id] ?? null, 'status' => $i->status, 'installed_at' => $i->installed_at?->toIso8601String(),
                'health' => $installer->usage($i)['health'],
            ])->all(),
        ], 'meta' => $this->catalog()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['slug' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{2,59}$/', 'unique:marketplace_apps,slug'], ...$this->listingRules()]);

        return $this->attempt(function () use ($request, $data) {
            [$app, $secret] = $this->registry->create($this->user($request), $data);

            return response()->json(['data' => ['id' => $app->public_id, 'client_id' => $app->client_id, 'client_secret' => $secret],
                'message' => 'App created. Copy the client secret now: it is not shown again.'], 201);
        });
    }

    public function update(Request $request, MarketplaceApp $app): JsonResponse
    {
        $data = $request->validate($this->listingRules());

        return $this->attempt(function () use ($request, $app, $data) {
            $this->registry->update($this->user($request), $app, $data);

            return response()->json(['message' => 'Saved.']);
        });
    }

    public function rotateSecret(Request $request, MarketplaceApp $app): JsonResponse
    {
        $secret = $this->registry->rotateSecret($this->user($request), $app);

        return response()->json(['data' => ['client_secret' => $secret], 'message' => 'New client secret made. The old one stopped working.']);
    }

    public function saveVersion(Request $request, MarketplaceApp $app): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:20'],
            'scopes' => ['present', 'array'], 'scopes.*' => ['string', Rule::in(MarketplaceCatalog::scopes())],
            'events' => ['present', 'array'], 'events.*' => ['string', Rule::in(MarketplaceCatalog::events())],
            'config_schema' => ['present', 'array'],
            'data_use' => ['required', 'string', 'min:20', 'max:5000'],
            'release_notes' => ['nullable', 'string', 'max:5000'],
            'min_platform_version' => ['nullable', 'string', 'regex:/^\d+\.\d+(\.\d+)?$/'],
        ]);

        return $this->attempt(function () use ($request, $app, $data) {
            $version = $this->registry->saveVersion($this->user($request), $app, $data);

            return response()->json(['data' => ['version' => $version->version], 'message' => 'Draft version saved.']);
        });
    }

    public function submit(Request $request, MarketplaceApp $app): JsonResponse
    {
        return $this->attempt(function () use ($request, $app) {
            $this->registry->submit($this->user($request), $app);

            return response()->json(['message' => 'Sent for review. Someone else with the marketplace role reviews it.']);
        });
    }

    public function publish(Request $request, MarketplaceApp $app): JsonResponse
    {
        $data = $request->validate(['checks' => ['required', 'array'], 'checks.*' => ['boolean'], 'note' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(function () use ($request, $app, $data) {
            $version = $this->registry->publish($this->user($request), $app, $data['checks'], $data['note'] ?? null);

            return response()->json(['message' => "Version {$version->version} is published. Stores can install it."]);
        });
    }

    public function suspend(Request $request, MarketplaceApp $app): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);

        return $this->attempt(function () use ($request, $app, $data) {
            $count = $this->registry->suspend($this->user($request), $app, $data['reason']);

            return response()->json(['message' => "Suspended. It stopped at once in {$count} store(s): keys and webhooks no longer work."]);
        });
    }

    public function resume(Request $request, MarketplaceApp $app): JsonResponse
    {
        return $this->attempt(function () use ($request, $app) {
            $count = $this->registry->resume($this->user($request), $app);

            return response()->json(['message' => "Resumed in {$count} store(s)."]);
        });
    }

    public function deprecate(Request $request, MarketplaceApp $app): JsonResponse
    {
        return $this->attempt(function () use ($request, $app) {
            $this->registry->deprecate($this->user($request), $app);

            return response()->json(['message' => 'Retired from the catalog. Stores that have it keep it.']);
        });
    }

    /** @return array<string, mixed> */
    private function catalog(): array
    {
        return [
            'categories' => MarketplaceCatalog::CATEGORIES,
            'scopes' => collect(MarketplaceCatalog::scopes())->map(fn ($s) => ['scope' => $s, 'label' => MarketplaceCatalog::SCOPES[$s][0], 'risk' => MarketplaceCatalog::SCOPES[$s][1]])->values(),
            'events' => MarketplaceCatalog::events(),
            'ownership' => MarketplaceCatalog::OWNERSHIP,
            'trust' => MarketplaceCatalog::TRUST,
            'field_types' => MarketplaceCatalog::FIELD_TYPES,
            'review_checks' => MarketplaceCatalog::REVIEW_CHECKS,
        ];
    }

    /** @return array<string, mixed> */
    private function listingRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'tagline' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::in(array_keys(MarketplaceCatalog::CATEGORIES))],
            'publisher_name' => ['required', 'string', 'max:120'],
            'publisher_website' => ['nullable', 'url:https', 'max:255'],
            'support_email' => ['nullable', 'email', 'max:190'],
            'privacy_url' => ['required', 'url:https', 'max:255'],
            'terms_url' => ['nullable', 'url:https', 'max:255'],
            'ownership' => ['required', Rule::in(array_keys(MarketplaceCatalog::OWNERSHIP))],
            'trust' => ['required', Rule::in(array_keys(MarketplaceCatalog::TRUST))],
            'redirect_uris' => ['present', 'array', 'max:5'], 'redirect_uris.*' => ['url:https', 'max:255'],
            'webhook_url' => ['nullable', 'url:https', 'max:255'],
            'required_features' => ['present', 'array'], 'required_features.*' => ['string', 'max:60', 'regex:/^[a-z0-9_.]+$/'],
        ];
    }

    /** @param \Closure(): JsonResponse $action */
    private function attempt(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (MarketplaceRefused $refused) {
            return response()->json(['message' => $refused->getMessage(), 'code' => $refused->errorCode], 422);
        }
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
