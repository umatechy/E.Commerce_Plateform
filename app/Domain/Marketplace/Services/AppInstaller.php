<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\DeveloperPlatform\Exceptions\InvalidWebhookUrlException;
use App\Domain\DeveloperPlatform\Models\ApiKey;
use App\Domain\DeveloperPlatform\Models\ApiKeyStatus;
use App\Domain\DeveloperPlatform\Models\ApplicationStatus;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Models\WebhookSubscription;
use App\Domain\DeveloperPlatform\Models\WebhookSubscriptionStatus;
use App\Domain\DeveloperPlatform\Services\ApiKeyService;
use App\Domain\DeveloperPlatform\Services\ApplicationService;
use App\Domain\DeveloperPlatform\Services\WebhookService;
use App\Domain\Help\Support\HelpCatalog;
use App\Domain\Identity\Models\User;
use App\Domain\Marketplace\Models\AppInstallation;
use App\Domain\Marketplace\Models\MarketplaceApp;
use App\Domain\Marketplace\Support\MarketplaceCatalog;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase B56 (gap G19; Module 27 §10–12, §19–25, §34–36, §52, §56, §64;
 * SRS APP-002/003/005/006/007) — a store's apps.
 *
 * Installing: the Owner sees what the app may read and receive and agrees
 * (with step-up). The installation then waits for the app to connect:
 * the browser goes to the app's registered address with a one-time code
 * (10 minutes), and the app's server exchanges it, with its client secret,
 * at POST /api/dev/v1/oauth/token. Only then are the store's credentials
 * for it made — a developer application with an API key of exactly the
 * granted scopes and a webhook subscription for the granted events — and
 * handed over once; the platform keeps only their hashes.
 *
 * Uninstalling revokes the key and stops the webhooks at once and removes
 * the store's settings for the app (§23, §79). Nothing of the app ever
 * reaches another store (§12): every query is scoped to the store.
 */
final class AppInstaller
{
    public const CODE_MINUTES = 10;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ApplicationService $applications,
        private readonly ApiKeyService $keys,
        private readonly WebhookService $webhooks,
    ) {}

    /**
     * Why this store cannot install the app now (§19, §21); empty when it can.
     *
     * @return list<array{code: string, message: string}>
     */
    public function problems(MarketplaceApp $app, Store $store): array
    {
        $problems = [];
        $version = $app->currentVersion;
        if ($app->status !== 'published' || $version === null) {
            return [['code' => 'not_available', 'message' => 'This app is not available to install.']];
        }
        if (! HelpCatalog::versionAtMost($version->min_platform_version, HelpCatalog::productVersion())) {
            $problems[] = ['code' => 'platform_version', 'message' => "This app needs Umar Techy {$version->min_platform_version} or later."];
        }
        $entitlements = app(EntitlementService::class);
        // Phase P4c (Module 04 §54–55): apps from the marketplace are the advanced integrations of the
        // Business and Premium packages; core payment and delivery stay with every package.
        $features = [...(array) $app->required_features, 'integrations.advanced'];
        if ((array) $version->scopes !== []) {
            $features[] = 'integrations.api'; // reading store data is API access (Module 04 §8)
        }
        foreach (array_unique($features) as $feature) {
            if (! $entitlements->hasFeature($feature)) {
                $problems[] = ['code' => 'package', 'message' => 'Your package does not include what this app needs. See Billing to change your plan.'];
                break;
            }
        }
        if (! $store->isOperational()) {
            $problems[] = ['code' => 'store_not_operational', 'message' => 'Apps can be installed while your store is open.'];
        }

        return $problems;
    }

    /** @return array{0: AppInstallation, 1: ?string} the installation and where to send the browser to connect it */
    public function install(User $owner, MarketplaceApp $app, Store $store): array
    {
        $problems = $this->problems($app, $store);
        $this->refuseIf($problems !== [], $problems[0]['code'] ?? 'not_installable', $problems[0]['message'] ?? 'This app cannot be installed.');
        $existing = AppInstallation::query()->where('marketplace_app_id', $app->id)->first();
        $this->refuseIf($existing !== null && ! in_array($existing->status, ['uninstalled'], true), 'already_installed', 'This app is already installed.');
        $version = $app->currentVersion;
        $needsConnection = (array) $version->scopes !== [] || (array) $version->events !== [];

        return DB::transaction(function () use ($owner, $app, $store, $existing, $version, $needsConnection) {
            $installation = $existing ?? new AppInstallation(['store_id' => $store->id, 'marketplace_app_id' => $app->id]);
            $installation->fill([
                'marketplace_app_version_id' => $version->id,
                'status' => $needsConnection ? 'awaiting_connection' : 'active',
                'status_before_suspension' => null,
                'granted_scopes' => (array) $version->scopes,
                'granted_events' => (array) $version->events,
                'config' => [],
                'developer_application_id' => null,
                'installed_by' => $owner->id,
                'installed_at' => now(),
                'connected_at' => $needsConnection ? null : now(),
                'uninstalled_at' => null,
            ])->save();
            $this->audit->record('marketplace.app.installed', ['app' => $app->slug, 'version' => $version->version, 'scopes' => $version->scopes, 'events' => $version->events], $installation, $store->id, $owner);

            return [$installation, $needsConnection ? $this->connectUrl($installation, $app) : null];
        });
    }

    /** A fresh one-time code and the app's address to send the browser to. */
    public function connectUrl(AppInstallation $installation, MarketplaceApp $app): string
    {
        $redirect = (string) (((array) $app->redirect_uris)[0] ?? '');
        $this->refuseIf($redirect === '', 'no_redirect', 'This app has no address to connect to.');
        $code = Str::random(48);
        DB::table('app_authorization_codes')->insert([
            'store_id' => $installation->store_id, 'app_installation_id' => $installation->id, 'code_hash' => hash('sha256', $code),
            'redirect_uri' => $redirect, 'expires_at' => now()->addMinutes(self::CODE_MINUTES), 'created_at' => now(),
        ]);
        $store = Store::query()->withoutGlobalScopes()->findOrFail($installation->store_id);

        return $redirect.(str_contains($redirect, '?') ? '&' : '?').http_build_query(['code' => $code, 'store' => $store->public_id, 'installation' => $installation->public_id]);
    }

    /**
     * The app's server exchanges a code for the store's credentials (§34).
     * Every failure answers the same, so nothing can be learned by guessing.
     *
     * @return array<string, mixed>|null the token answer, or null when the exchange is refused
     */
    public function exchange(string $clientId, string $clientSecret, string $code, string $redirectUri): ?array
    {
        return DB::transaction(function () use ($clientId, $clientSecret, $code, $redirectUri) {
            $row = DB::table('app_authorization_codes')->where('code_hash', hash('sha256', $code))->lockForUpdate()->first();
            if ($row === null || $row->used_at !== null || now()->greaterThan($row->expires_at) || ! hash_equals((string) $row->redirect_uri, $redirectUri)) {
                return null;
            }
            $installation = AppInstallation::query()->withoutTenantScope()->lockForUpdate()->find($row->app_installation_id);
            $app = $installation === null ? null : MarketplaceApp::query()->find($installation->marketplace_app_id);
            if ($installation === null || $app === null || ! hash_equals($app->client_id, $clientId) || ! hash_equals($app->client_secret_hash, hash('sha256', $clientSecret))
                || ! in_array($app->status, ['published', 'deprecated'], true) || ! in_array($installation->status, ['awaiting_connection', 'active'], true)) {
                return null;
            }
            DB::table('app_authorization_codes')->where('id', $row->id)->update(['used_at' => now()]);
            $store = Store::query()->withoutGlobalScopes()->findOrFail($installation->store_id);
            app(TenantContext::class)->resolveToStore($store->id);

            // Connecting again replaces the credentials given before.
            $this->revokeCredentials($installation);
            $application = $this->applications->create($store, mb_substr('App: '.$app->name, 0, 100), $installation->installed_by);
            $token = null;
            if ((array) $installation->granted_scopes !== []) {
                $token = $this->keys->issue($application, (array) $installation->granted_scopes)['plaintext'];
            }
            $secret = null;
            if ((array) $installation->granted_events !== [] && $app->webhook_url !== null) {
                try {
                    $secret = $this->webhooks->subscribe($application, $app->webhook_url, (array) $installation->granted_events)['plaintextSecret'];
                } catch (InvalidWebhookUrlException) {
                    $secret = null; // the app keeps its API access; its webhook address must be fixed
                }
            }
            $installation->update(['developer_application_id' => $application->id, 'status' => 'active', 'connected_at' => now()]);
            $this->audit->record('marketplace.app.connected', ['app' => $app->slug, 'scopes' => $installation->granted_scopes, 'events' => $installation->granted_events], $installation, $store->id);

            return [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'scope' => implode(' ', (array) $installation->granted_scopes),
                'webhook_signing_secret' => $secret,
                'installation' => $installation->public_id,
                'store' => ['id' => $store->public_id, 'name' => $store->name, 'slug' => $store->slug],
            ];
        });
    }

    /**
     * The store's settings for the app (§45–47). Secret fields are never sent
     * back to the admin; empty keeps the saved one.
     *
     * @param array<string, mixed> $input
     * @return array<string, string> errors by field
     */
    public function configure(User $by, AppInstallation $installation, array $input): array
    {
        $this->refuseIf($installation->status === 'uninstalled', 'uninstalled', 'This app is not installed.');
        [$values, $errors] = MarketplaceCatalog::validateConfig((array) $installation->version->config_schema, $input, (array) $installation->config);
        if ($errors !== []) {
            return $errors;
        }
        $installation->update(['config' => $values]);
        $secretKeys = collect((array) $installation->version->config_schema)->where('type', 'secret')->pluck('key')->all();
        $this->audit->record('marketplace.app.configured', ['app' => $installation->app->slug, 'fields' => array_values(array_diff(array_keys($values), $secretKeys)), 'secrets_changed' => array_values(array_intersect(array_keys($input), $secretKeys))], $installation, $installation->store_id, $by);

        return [];
    }

    public function disable(User $by, AppInstallation $installation): void
    {
        $this->refuseIf($installation->status !== 'active', 'not_active', 'Only an active app can be paused.');
        $application = $this->application($installation);
        if ($application?->status === ApplicationStatus::Active) {
            $this->applications->suspend($application);
        }
        $installation->update(['status' => 'disabled']);
        $this->audit->record('marketplace.app.disabled', ['app' => $installation->app->slug], $installation, $installation->store_id, $by);
    }

    public function enable(User $by, AppInstallation $installation): void
    {
        $this->refuseIf($installation->status !== 'disabled', 'not_disabled', 'This app is not paused.');
        $application = $this->application($installation);
        if ($application?->status === ApplicationStatus::Suspended) {
            $this->applications->reactivate($application);
        }
        $installation->update(['status' => $installation->connected_at === null ? 'awaiting_connection' : 'active']);
        $this->audit->record('marketplace.app.enabled', ['app' => $installation->app->slug], $installation, $installation->store_id, $by);
    }

    /**
     * Move to the app's published version (§76). New permissions need the
     * Owner's agreement ($accepted); the key and webhooks follow at once.
     */
    public function update(User $by, AppInstallation $installation, bool $accepted): void
    {
        $app = $installation->app;
        $target = $app->currentVersion;
        $this->refuseIf($target === null || $target->id === $installation->marketplace_app_version_id || $app->status !== 'published', 'no_update', 'There is no newer version.');
        $this->refuseIf(! in_array($installation->status, ['active', 'disabled', 'awaiting_connection'], true), 'not_installed', 'This app cannot be updated now.');
        $newScopes = array_values(array_diff((array) $target->scopes, (array) $installation->granted_scopes));
        $newEvents = array_values(array_diff((array) $target->events, (array) $installation->granted_events));
        $this->refuseIf(($newScopes !== [] || $newEvents !== []) && ! $accepted, 'consent_required', 'This version asks for more. Review and accept its permissions to update.');
        $this->refuseIf($newScopes !== [] && ! app(EntitlementService::class)->hasFeature('integrations.api'), 'package', 'Your package does not include what this version needs.');

        DB::transaction(function () use ($by, $installation, $app, $target) {
            $from = $installation->version->version;
            $installation->update(['marketplace_app_version_id' => $target->id, 'granted_scopes' => (array) $target->scopes, 'granted_events' => (array) $target->events]);
            $application = $this->application($installation);
            if ($application !== null) {
                ApiKey::query()->where('developer_application_id', $application->id)->where('status', ApiKeyStatus::Active)->update(['scopes' => json_encode(array_values((array) $target->scopes))]);
                WebhookSubscription::query()->where('developer_application_id', $application->id)->where('status', WebhookSubscriptionStatus::Active)->update(['subscribed_events' => json_encode(array_values((array) $target->events))]);
            }
            $this->audit->record('marketplace.app.updated', ['app' => $app->slug, 'from' => $from, 'to' => $target->version, 'scopes' => $target->scopes, 'events' => $target->events], $installation, $installation->store_id, $by);
        });
    }

    public function uninstall(User $by, AppInstallation $installation): void
    {
        $this->refuseIf($installation->status === 'uninstalled', 'uninstalled', 'This app is not installed.');
        DB::transaction(function () use ($by, $installation) {
            $this->revokeCredentials($installation);
            DB::table('app_authorization_codes')->where('app_installation_id', $installation->id)->whereNull('used_at')->update(['used_at' => now()]);
            $installation->update(['status' => 'uninstalled', 'config' => [], 'uninstalled_at' => now(), 'status_before_suspension' => null]);
            $this->audit->record('marketplace.app.uninstalled', ['app' => $installation->app->slug], $installation, $installation->store_id, $by);
        });
    }

    /**
     * Usage and health for the last 30 days (§56–57, §64).
     *
     * @return array{api_calls: int, api_errors: int, webhooks_sent: int, webhooks_failed: int, last_used_at: ?string, health: string}
     */
    public function usage(AppInstallation $installation): array
    {
        $application = $this->application($installation);
        if ($application === null) {
            return ['api_calls' => 0, 'api_errors' => 0, 'webhooks_sent' => 0, 'webhooks_failed' => 0, 'last_used_at' => null, 'health' => $installation->status === 'active' ? 'unknown' : 'disabled'];
        }
        $since = now()->subDays(30);
        $keyIds = ApiKey::query()->where('developer_application_id', $application->id)->pluck('id');
        $calls = DB::table('api_request_logs')->whereIn('api_key_id', $keyIds)->where('created_at', '>=', $since);
        $subscriptionIds = WebhookSubscription::query()->where('developer_application_id', $application->id)->pluck('id');
        $attempts = DB::table('webhook_delivery_attempts')->whereIn('webhook_subscription_id', $subscriptionIds)->where('occurred_at', '>=', $since);
        $recentFailed = (clone $attempts)->where('occurred_at', '>=', now()->subDay())->where('result', 'failed')->count();
        $recentAll = (clone $attempts)->where('occurred_at', '>=', now()->subDay())->count();
        $result = [
            'api_calls' => (clone $calls)->count(),
            'api_errors' => (clone $calls)->where('status_code', '>=', 500)->count(),
            'webhooks_sent' => (clone $attempts)->where('result', 'succeeded')->count(),
            'webhooks_failed' => (clone $attempts)->where('result', 'failed')->count(),
            'last_used_at' => ApiKey::query()->where('developer_application_id', $application->id)->max('last_used_at'),
        ];
        $health = match (true) {
            $installation->status !== 'active' => 'disabled',
            $recentAll >= 5 && $recentFailed / $recentAll >= 0.5 => 'critical',
            $recentFailed > 0 || $result['api_errors'] > 0 => 'degraded',
            $result['api_calls'] === 0 && $result['webhooks_sent'] === 0 => 'unknown',
            default => 'healthy',
        };

        return [...$result, 'last_used_at' => $result['last_used_at'] === null ? null : (string) $result['last_used_at'], 'health' => $health];
    }

    private function revokeCredentials(AppInstallation $installation): void
    {
        $application = $this->application($installation);
        if ($application === null) {
            return;
        }
        if ($application->status !== ApplicationStatus::Revoked) {
            $this->applications->revoke($application);
        }
        WebhookSubscription::query()->withoutTenantScope()->where('developer_application_id', $application->id)
            ->where('status', WebhookSubscriptionStatus::Active)->update(['status' => WebhookSubscriptionStatus::Disabled]);
    }

    private function application(AppInstallation $installation): ?DeveloperApplication
    {
        return $installation->developer_application_id === null ? null
            : DeveloperApplication::query()->withoutTenantScope()->find($installation->developer_application_id);
    }

    private function refuseIf(bool $condition, string $code, string $message): void
    {
        if ($condition) {
            throw new MarketplaceRefused($code, $message);
        }
    }
}
