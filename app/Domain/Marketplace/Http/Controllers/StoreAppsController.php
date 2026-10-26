<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Http\Controllers;

use App\Domain\DeveloperPlatform\Policies\DeveloperPlatformPolicy;
use App\Domain\Identity\Models\User;
use App\Domain\Marketplace\Models\AppInstallation;
use App\Domain\Marketplace\Models\MarketplaceApp;
use App\Domain\Marketplace\Models\MarketplaceAppVersion;
use App\Domain\Marketplace\Services\AppInstaller;
use App\Domain\Marketplace\Services\MarketplaceRefused;
use App\Domain\Marketplace\Support\MarketplaceCatalog;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase B56 (gap G19; Module 27 §10–12, §45, §56, §65–66, §89; SRS
 * APP-005/006) — Store → Apps: the marketplace as a store sees it, and its
 * installed apps.
 *
 * Looking needs developer_platform.view; installing, connecting,
 * changing settings, updating, pausing and removing need
 * developer_platform.manage, which only the Owner holds (apps receive the
 * store's data). Installing, connecting, updating and removing also ask
 * the Owner to confirm it is them (step-up on the routes).
 */
final class StoreAppsController
{
    public function __construct(private readonly AppInstaller $installer) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $data = $request->validate(['category' => ['nullable', Rule::in(array_keys(MarketplaceCatalog::CATEGORIES))], 'q' => ['nullable', 'string', 'max:80']]);
        $installed = AppInstallation::query()->where('status', '!=', 'uninstalled')->get()->keyBy('marketplace_app_id');
        $apps = MarketplaceApp::query()->with('currentVersion')
            ->where(fn ($q) => $q->where('status', 'published')->orWhereIn('id', $installed->keys()))
            ->when($data['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('tagline', 'like', "%{$s}%")->orWhere('publisher_name', 'like', "%{$s}%")))
            ->orderBy('name')->get();

        return response()->json([
            'data' => $apps->map(fn (MarketplaceApp $app) => [...$this->card($app), 'installed' => $installed->has($app->id) ? $installed[$app->id]->status : null])->all(),
            'meta' => ['categories' => MarketplaceCatalog::CATEGORIES, 'can_manage' => $this->canManage($request),
                // Phase P4c: installing needs integrations.advanced (Business and Premium).
                'included' => app(\App\Domain\Packages\Services\EntitlementService::class)->hasFeature('integrations.advanced')],
        ]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $this->authorizeView($request);
        $app = $this->app($slug);
        $installation = AppInstallation::query()->with('version')->where('marketplace_app_id', $app->id)->first();
        $installed = $installation !== null && $installation->status !== 'uninstalled' ? $installation : null;
        $version = $installed->version ?? $app->currentVersion;
        $target = $app->currentVersion;

        return response()->json(['data' => [
            ...$this->card($app),
            'description' => $app->description,
            'publisher' => ['name' => $app->publisher_name, 'website' => $app->publisher_website, 'support_email' => $app->support_email],
            'privacy_url' => $app->privacy_url,
            'terms_url' => $app->terms_url,
            'version' => $version?->version,
            'release_notes' => $target?->release_notes,
            'permissions' => $this->permissions((array) ($target->scopes ?? [])),
            'events' => array_values((array) ($target->events ?? [])),
            'data_use' => $target?->data_use,
            'problems' => $installed === null ? $this->installer->problems($app, $this->store()) : [],
            'can_manage' => $this->canManage($request),
            'installation' => $installed === null ? null : [
                'id' => $installed->public_id,
                'status' => $installed->status,
                'version' => $installed->version->version,
                'permissions' => $this->permissions((array) $installed->granted_scopes),
                'events' => (array) $installed->granted_events,
                'installed_at' => $installed->installed_at?->toIso8601String(),
                'connected_at' => $installed->connected_at?->toIso8601String(),
                'settings_form' => (array) $installed->version->config_schema,
                'settings' => $this->publicSettings($installed),
                'usage' => $this->installer->usage($installed),
                'update' => $this->updateOffer($installed, $target),
                'suspended_reason' => $installed->status === 'suspended' ? $app->suspended_reason : null,
            ],
        ]]);
    }

    public function install(Request $request, string $slug): JsonResponse
    {
        $user = $this->authorizeManage($request);
        $request->validate(['accept_permissions' => ['accepted']]);

        return $this->attempt(function () use ($user, $slug) {
            [$installation, $redirect] = $this->installer->install($user, $this->app($slug), $this->store());

            return response()->json(['data' => ['installation' => $installation->public_id, 'status' => $installation->status, 'redirect_url' => $redirect]], 201);
        });
    }

    /** A new connection code: the app connects (again), and the credentials it had stop working. */
    public function connect(Request $request, string $slug): JsonResponse
    {
        $this->authorizeManage($request);

        return $this->attempt(function () use ($slug) {
            $app = $this->app($slug);
            $installation = $this->installation($app);
            if (! in_array($installation->status, ['awaiting_connection', 'active'], true) || ! in_array($app->status, ['published', 'deprecated'], true)) {
                throw new MarketplaceRefused('not_connectable', 'This app cannot be connected now.');
            }

            return response()->json(['data' => ['redirect_url' => $this->installer->connectUrl($installation, $app)]]);
        });
    }

    public function settings(Request $request, string $slug): JsonResponse
    {
        $user = $this->authorizeManage($request);
        $data = $request->validate(['settings' => ['present', 'array']]);

        return $this->attempt(function () use ($user, $slug, $data) {
            $errors = $this->installer->configure($user, $this->installation($this->app($slug)), $data['settings']);
            if ($errors !== []) {
                return response()->json(['message' => 'Some settings are not valid.', 'errors' => collect($errors)->mapWithKeys(fn ($m, $k) => ["settings.{$k}" => [$m]])->all()], 422);
            }

            return response()->json(['message' => 'Settings saved.']);
        });
    }

    public function disable(Request $request, string $slug): JsonResponse
    {
        $user = $this->authorizeManage($request);

        return $this->attempt(function () use ($user, $slug) {
            $this->installer->disable($user, $this->installation($this->app($slug)));

            return response()->json(['message' => 'Paused. The app cannot read your store or receive events until you turn it back on.']);
        });
    }

    public function enable(Request $request, string $slug): JsonResponse
    {
        $user = $this->authorizeManage($request);

        return $this->attempt(function () use ($user, $slug) {
            $this->installer->enable($user, $this->installation($this->app($slug)));

            return response()->json(['message' => 'Turned back on.']);
        });
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $user = $this->authorizeManage($request);
        $data = $request->validate(['accept_permissions' => ['nullable', 'boolean']]);

        return $this->attempt(function () use ($user, $slug, $data) {
            $this->installer->update($user, $this->installation($this->app($slug)), (bool) ($data['accept_permissions'] ?? false));

            return response()->json(['message' => 'Updated.']);
        });
    }

    public function uninstall(Request $request, string $slug): JsonResponse
    {
        $user = $this->authorizeManage($request);

        return $this->attempt(function () use ($user, $slug) {
            $this->installer->uninstall($user, $this->installation($this->app($slug)));

            return response()->json(['message' => 'Removed. Its access to your store ended at once, and its settings were deleted.']);
        });
    }

    /** @return array<string, mixed> */
    private function card(MarketplaceApp $app): array
    {
        return [
            'slug' => $app->slug, 'name' => $app->name, 'tagline' => $app->tagline, 'category' => $app->category,
            'category_label' => MarketplaceCatalog::CATEGORIES[$app->category] ?? $app->category,
            'publisher' => $app->publisher_name, 'trust' => $app->trust, 'trust_label' => MarketplaceCatalog::TRUST[$app->trust] ?? $app->trust,
            'pricing' => $app->pricing, 'logo' => $app->logo_path, 'status' => $app->status,
            'high_risk' => collect((array) ($app->currentVersion->scopes ?? []))->contains(fn ($s) => (MarketplaceCatalog::SCOPES[$s][1] ?? 'high') === 'high'),
        ];
    }

    /**
     * @param list<string> $scopes
     * @return list<array{scope: string, label: string, risk: string}>
     */
    private function permissions(array $scopes): array
    {
        return array_map(fn (string $s) => ['scope' => $s, 'label' => MarketplaceCatalog::SCOPES[$s][0] ?? $s, 'risk' => MarketplaceCatalog::SCOPES[$s][1] ?? 'high'], $scopes);
    }

    /**
     * The settings as the admin may see them: secrets only as "saved".
     *
     * @return array<string, mixed>
     */
    private function publicSettings(AppInstallation $installation): array
    {
        $values = (array) $installation->config;
        $out = [];
        foreach ((array) $installation->version->config_schema as $field) {
            $key = (string) $field['key'];
            $out[$key] = ($field['type'] ?? '') === 'secret' ? ['saved' => isset($values[$key]) && $values[$key] !== ''] : ($values[$key] ?? null);
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function updateOffer(AppInstallation $installation, ?MarketplaceAppVersion $target): ?array
    {
        if ($target === null || $target->id === $installation->marketplace_app_version_id || $installation->app->status !== 'published') {
            return null;
        }

        return [
            'version' => $target->version,
            'release_notes' => $target->release_notes,
            'new_permissions' => $this->permissions(array_values(array_diff((array) $target->scopes, (array) $installation->granted_scopes))),
            'new_events' => array_values(array_diff((array) $target->events, (array) $installation->granted_events)),
        ];
    }

    private function app(string $slug): MarketplaceApp
    {
        return MarketplaceApp::query()->with('currentVersion')->where('slug', $slug)->whereNotIn('status', ['draft', 'in_review', 'archived'])->firstOrFail();
    }

    private function installation(MarketplaceApp $app): AppInstallation
    {
        return AppInstallation::query()->with(['version', 'app'])->where('marketplace_app_id', $app->id)->where('status', '!=', 'uninstalled')->firstOrFail();
    }

    private function store(): Store
    {
        return Store::query()->findOrFail(app(TenantContext::class)->storeId());
    }

    private function authorizeView(Request $request): void
    {
        abort_unless(app(DeveloperPlatformPolicy::class)->view($this->user($request)), 403);
    }

    private function authorizeManage(Request $request): User
    {
        $user = $this->user($request);
        abort_unless(app(DeveloperPlatformPolicy::class)->manage($user), 403, 'Only the store owner can install, change or remove apps.');

        return $user;
    }

    private function canManage(Request $request): bool
    {
        return app(DeveloperPlatformPolicy::class)->manage($this->user($request));
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
