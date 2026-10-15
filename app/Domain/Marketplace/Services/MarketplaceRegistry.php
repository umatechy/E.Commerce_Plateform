<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\DeveloperPlatform\Models\ApplicationStatus;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Services\ApplicationService;
use App\Domain\Help\Support\HelpCatalog;
use App\Domain\Identity\Models\User;
use App\Domain\Marketplace\Models\AppInstallation;
use App\Domain\Marketplace\Models\MarketplaceApp;
use App\Domain\Marketplace\Models\MarketplaceAppVersion;
use App\Domain\Marketplace\Support\MarketplaceCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase B56 (gap G19; Module 27 §8–9, §16–19, §58–59, §71, §85–86) — the
 * Super Admin's side of the marketplace: the registry of apps, their
 * versions, review and publication, and the kill switch.
 *
 * - A version is reviewed against a checklist by someone other than the
 *   person who submitted it, then published; stores install the published
 *   version.
 * - Suspending an app (the kill switch) stops it in every store at once:
 *   each installation's developer application is suspended, so its API
 *   key and webhooks stop (B56 fixed that suspension really does that).
 *   Resuming puts back exactly the installations that were active.
 */
final class MarketplaceRegistry
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ApplicationService $applications,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @return array{0: MarketplaceApp, 1: string} the app and its client secret, shown once
     */
    public function create(User $by, array $data): array
    {
        $secret = 'utas_'.Str::random(48);
        $app = MarketplaceApp::query()->create([
            ...$this->listing($data),
            'slug' => $data['slug'],
            'status' => 'draft',
            'pricing' => 'free',
            'client_id' => 'uta_'.Str::lower(Str::random(24)),
            'client_secret_hash' => hash('sha256', $secret),
            'created_by' => $by->id,
        ]);
        $this->audit->record('marketplace.app.created', ['slug' => $app->slug], $app, actor: $by, platform: true);

        return [$app, $secret];
    }

    /** @param array<string, mixed> $data */
    public function update(User $by, MarketplaceApp $app, array $data): MarketplaceApp
    {
        $app->update($this->listing($data));
        $this->audit->record('marketplace.app.updated', ['slug' => $app->slug, 'fields' => array_keys($data)], $app, actor: $by, platform: true);

        return $app;
    }

    /** A new client secret; the old one stops working at once (§36 rotatable, revocable). */
    public function rotateSecret(User $by, MarketplaceApp $app): string
    {
        $secret = 'utas_'.Str::random(48);
        $app->update(['client_secret_hash' => hash('sha256', $secret)]);
        $this->audit->record('marketplace.app.secret_rotated', ['slug' => $app->slug], $app, actor: $by, platform: true);

        return $secret;
    }

    /**
     * Write or change a draft version (§17).
     *
     * @param array<string, mixed> $data
     */
    public function saveVersion(User $by, MarketplaceApp $app, array $data): MarketplaceAppVersion
    {
        $this->refuseIf(! MarketplaceCatalog::semver((string) $data['version']), 'version_invalid', 'A version is MAJOR.MINOR.PATCH, such as 1.0.0.');
        $problems = MarketplaceCatalog::schemaProblems($data['config_schema'] ?? []);
        $this->refuseIf($problems !== [], 'settings_form_invalid', implode(' ', $problems));
        $latest = $app->versions()->where('status', 'published')->first();
        $this->refuseIf($latest !== null && version_compare((string) $data['version'], $latest->version, '<='), 'version_not_newer', "The new version must be higher than {$latest?->version}.");
        $needsConnection = ((array) ($data['scopes'] ?? [])) !== [] || ((array) ($data['events'] ?? [])) !== [];
        $this->refuseIf($needsConnection && $app->redirect_uris === [], 'redirect_required', 'An app that reads data or receives events needs a redirect address to connect.');
        $this->refuseIf(((array) ($data['events'] ?? [])) !== [] && $app->webhook_url === null, 'webhook_required', 'An app that receives events needs a webhook address.');

        $version = $app->versions()->whereIn('status', ['draft', 'in_review'])->first() ?? new MarketplaceAppVersion(['marketplace_app_id' => $app->id]);
        $version->fill([
            'version' => $data['version'],
            'scopes' => array_values(array_intersect(MarketplaceCatalog::scopes(), (array) ($data['scopes'] ?? []))),
            'events' => array_values(array_intersect(MarketplaceCatalog::events(), (array) ($data['events'] ?? []))),
            'config_schema' => array_values((array) ($data['config_schema'] ?? [])),
            'data_use' => $data['data_use'],
            'release_notes' => $data['release_notes'] ?? null,
            'min_platform_version' => $data['min_platform_version'] ?? HelpCatalog::productVersion(),
            'status' => 'draft',
            'review' => null,
            'submitted_by' => $by->id,
        ])->save();
        $this->audit->record('marketplace.version.saved', ['slug' => $app->slug, 'version' => $version->version], $app, actor: $by, platform: true);

        return $version;
    }

    public function submit(User $by, MarketplaceApp $app): MarketplaceAppVersion
    {
        $version = $app->versions()->where('status', 'draft')->first();
        $this->refuseIf($version === null, 'nothing_to_submit', 'There is no draft version to send for review.');
        $version->update(['status' => 'in_review', 'submitted_by' => $by->id]);
        if ($app->status === 'draft') {
            $app->update(['status' => 'in_review']);
        }
        $this->audit->record('marketplace.version.submitted', ['slug' => $app->slug, 'version' => $version->version], $app, actor: $by, platform: true);

        return $version;
    }

    /**
     * Approve and publish the version in review (§16, §71). Every check
     * must be confirmed, by someone other than its submitter.
     *
     * @param array<string, bool> $checks
     */
    public function publish(User $reviewer, MarketplaceApp $app, array $checks, ?string $note): MarketplaceAppVersion
    {
        $version = $app->versions()->where('status', 'in_review')->first();
        $this->refuseIf($version === null, 'not_in_review', 'There is no version waiting for review.');
        $this->refuseIf($version->submitted_by === $reviewer->id, 'self_review', 'Someone else must review what you submitted.');
        $missing = array_keys(array_filter(MarketplaceCatalog::REVIEW_CHECKS, fn ($label, $key) => ($checks[$key] ?? false) !== true, ARRAY_FILTER_USE_BOTH));
        $this->refuseIf($missing !== [], 'review_incomplete', 'Confirm every review check first.');
        $this->refuseIf($app->status === 'suspended', 'suspended', 'Resume the app before publishing a version.');

        return DB::transaction(function () use ($reviewer, $app, $version, $note) {
            $app->versions()->where('status', 'published')->update(['status' => 'superseded']);
            $version->update([
                'status' => 'published', 'reviewed_by' => $reviewer->id, 'published_at' => now(),
                'review' => ['checks' => array_keys(MarketplaceCatalog::REVIEW_CHECKS), 'note' => $note, 'at' => now()->toIso8601String()],
            ]);
            $app->update(['status' => 'published', 'current_version_id' => $version->id]);
            $this->audit->record('marketplace.version.published', ['slug' => $app->slug, 'version' => $version->version, 'submitted_by' => $version->submitted_by], $app, actor: $reviewer, platform: true);

            return $version;
        });
    }

    /** The kill switch (§58–59, §85–86): the app stops in every store at once. */
    public function suspend(User $by, MarketplaceApp $app, string $reason): int
    {
        $this->refuseIf($app->status === 'suspended', 'suspended', 'This app is already suspended.');

        return DB::transaction(function () use ($by, $app, $reason) {
            $installations = AppInstallation::query()->withoutTenantScope()->where('marketplace_app_id', $app->id)
                ->whereIn('status', ['active', 'awaiting_connection', 'disabled'])->lockForUpdate()->get();
            foreach ($installations as $installation) {
                if ($installation->developer_application_id !== null) {
                    $application = DeveloperApplication::query()->withoutTenantScope()->find($installation->developer_application_id);
                    if ($application !== null && $application->status === ApplicationStatus::Active) {
                        $this->applications->suspend($application);
                    }
                }
                $installation->update(['status_before_suspension' => $installation->status, 'status' => 'suspended']);
            }
            $app->update(['status' => 'suspended', 'suspended_reason' => $reason, 'suspended_at' => now()]);
            $this->audit->record('marketplace.app.suspended', ['slug' => $app->slug, 'reason' => $reason, 'installations' => $installations->count()], $app, actor: $by, platform: true);

            return $installations->count();
        });
    }

    public function resume(User $by, MarketplaceApp $app): int
    {
        $this->refuseIf($app->status !== 'suspended', 'not_suspended', 'This app is not suspended.');

        return DB::transaction(function () use ($by, $app) {
            $installations = AppInstallation::query()->withoutTenantScope()->where('marketplace_app_id', $app->id)->where('status', 'suspended')->get();
            foreach ($installations as $installation) {
                $before = $installation->status_before_suspension ?? 'disabled';
                if ($before === 'active' && $installation->developer_application_id !== null) {
                    $application = DeveloperApplication::query()->withoutTenantScope()->find($installation->developer_application_id);
                    if ($application?->status === ApplicationStatus::Suspended) {
                        $this->applications->reactivate($application);
                    }
                }
                $installation->update(['status' => $before, 'status_before_suspension' => null]);
            }
            $app->update(['status' => $app->current_version_id === null ? 'draft' : 'published', 'suspended_reason' => null, 'suspended_at' => null]);
            $this->audit->record('marketplace.app.resumed', ['slug' => $app->slug, 'installations' => $installations->count()], $app, actor: $by, platform: true);

            return $installations->count();
        });
    }

    /** No new installations; stores that have it keep it (§9 deprecated). */
    public function deprecate(User $by, MarketplaceApp $app): MarketplaceApp
    {
        $this->refuseIf($app->status !== 'published', 'not_published', 'Only a published app can be retired from the catalog.');
        $app->update(['status' => 'deprecated']);
        $this->audit->record('marketplace.app.deprecated', ['slug' => $app->slug], $app, actor: $by, platform: true);

        return $app;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function listing(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'name', 'tagline', 'description', 'category', 'publisher_name', 'publisher_website', 'support_email',
            'privacy_url', 'terms_url', 'ownership', 'trust', 'redirect_uris', 'webhook_url', 'required_features', 'logo_path',
        ]));
    }

    private function refuseIf(bool $condition, string $code, string $message): void
    {
        if ($condition) {
            throw new MarketplaceRefused($code, $message);
        }
    }
}
