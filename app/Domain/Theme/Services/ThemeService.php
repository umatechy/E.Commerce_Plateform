<?php

declare(strict_types=1);

namespace App\Domain\Theme\Services;

use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Theme\Exceptions\StoreThemePublicationNotFoundException;
use App\Domain\Theme\Models\StoreTheme;
use App\Domain\Theme\Models\StoreThemePublication;
use App\Domain\Theme\Models\Theme;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY code path that creates a StoreTheme or mutates
 * draft_config/published_config — mirrors every other domain
 * service's "service-only writes" pattern.
 */
final class ThemeService
{
    public function __construct(private readonly ThemeConfigValidator $validator, private readonly CustomCssSanitizer $cssSanitizer, private readonly RecordsOutboxEvents $outbox) {}

    /** Called once, additively, from StoreObserver for every new store — see docs/development/b15-inspection-findings.md. */
    public function createDefaultForStore(Store $store): StoreTheme
    {
        // firstOrCreate (never firstOrFail) is deliberate: StoreObserver
        // runs on EVERY Store creation across the entire platform,
        // including every test's Store::factory()->create() call since
        // Phase B1 — this method must be self-healing and never depend
        // on ThemeSeeder having already run, or it would break the
        // Store-creation path for the whole existing test suite (500+
        // tests across B1-B14), not just B15's own tests.
        $theme = Theme::query()->firstOrCreate(['key' => 'default'], [
            'name' => 'Default Storefront Theme', 'version' => '1.0.0', 'status' => 'active',
        ]);

        $defaultConfig = [
            'tokens' => ['primary' => '#111827', 'secondary' => '#6B7280', 'background' => '#FFFFFF', 'text' => '#111827', 'radius' => 'md', 'font_family' => 'system-ui'],
            'branding' => [],
            'sections' => [
                ['type' => 'header', 'position' => 0, 'is_visible' => true, 'config' => []],
                ['type' => 'footer', 'position' => 99, 'is_visible' => true, 'config' => []],
            ],
        ];

        $validated = $this->validator->validate($defaultConfig);

        // Auto-published immediately (unlike a later staff-initiated
        // draft edit) — a brand-new store must have a real, resolvable
        // theme from the moment it exists, mirroring how B8's
        // StoreObserver seeds an ACTIVE (not draft) default shipping
        // zone for the same reason.
        //
        // The auto-publication is recorded in the publication history like
        // any other, so the original default stays a rollback target —
        // without that row a store could never return to it.
        return DB::transaction(function () use ($store, $theme, $validated) {
            $storeTheme = StoreTheme::query()->create([
                'store_id' => $store->id,
                'theme_id' => $theme->id,
                'draft_config' => $validated,
                'published_config' => $validated,
                'published_at' => now(),
            ]);

            StoreThemePublication::query()->create([
                'store_id' => $store->id, // explicit: no tenant context exists while a store is being created
                'store_theme_id' => $storeTheme->id,
                'config' => $validated,
                'published_by_user_id' => null,
            ]);

            return $storeTheme;
        });
    }

    /**
     * @throws \App\Domain\Theme\Exceptions\InvalidThemeConfigException
     */
    public function updateDraft(StoreTheme $storeTheme, array $config, ?string $customCss): StoreTheme
    {
        $validated = $this->validator->validate($config);

        $customCss = $this->cssSanitizer->sanitize($customCss);

        return DB::transaction(function () use ($storeTheme, $validated, $customCss) {
            $storeTheme->update([
                'draft_config' => $validated,
                'custom_css' => $customCss,
            ]);

            $this->outbox->recordEventFor(
                $storeTheme->store_id,
                eventType: 'theme.configuration_updated',
                payload: ['store_theme_id' => $storeTheme->id],
                idempotencyKey: "store_theme:{$storeTheme->id}:draft_updated:".Str::ulid(),
            );

            return $storeTheme->fresh();
        });
    }

    /** Module 17 §16/§44 "Theme Lifecycle / Theme Publishing" — atomic: the draft becomes the new published config, and a ledger snapshot is recorded, in one transaction. */
    public function publish(StoreTheme $storeTheme, ?int $publishedByUserId): StoreTheme
    {
        return DB::transaction(function () use ($storeTheme, $publishedByUserId) {
            $storeTheme->update(['published_config' => $storeTheme->draft_config, 'published_at' => now()]);

            StoreThemePublication::query()->create([
                'store_id' => $storeTheme->store_id,
                'store_theme_id' => $storeTheme->id,
                'config' => $storeTheme->draft_config,
                'published_by_user_id' => $publishedByUserId,
            ]);

            $this->outbox->recordEventFor(
                $storeTheme->store_id,
                eventType: 'theme.published',
                payload: ['store_theme_id' => $storeTheme->id],
                idempotencyKey: "store_theme:{$storeTheme->id}:published:".Str::ulid(),
            );

            return $storeTheme->fresh();
        });
    }

    /**
     * Module 17 §20 "Theme Rollback" — re-publishes an earlier ledger
     * snapshot. Does NOT touch products/orders/customers/domains/SEO/
     * marketing/notifications (Module 17 §73, Non-Negotiable) — only
     * this one row's own config fields ever change.
     *
     * @throws StoreThemePublicationNotFoundException
     */
    public function rollbackTo(StoreTheme $storeTheme, int $publicationId, ?int $publishedByUserId): StoreTheme
    {
        $publication = StoreThemePublication::query()->where('store_theme_id', $storeTheme->id)->find($publicationId);

        if ($publication === null) {
            throw new StoreThemePublicationNotFoundException();
        }

        return DB::transaction(function () use ($storeTheme, $publication, $publishedByUserId) {
            $storeTheme->update(['draft_config' => $publication->config, 'published_config' => $publication->config, 'published_at' => now()]);

            StoreThemePublication::query()->create([
                'store_id' => $storeTheme->store_id,
                'store_theme_id' => $storeTheme->id,
                'config' => $publication->config,
                'published_by_user_id' => $publishedByUserId,
            ]);

            $this->outbox->recordEventFor(
                $storeTheme->store_id,
                eventType: 'theme.rolled_back',
                payload: ['store_theme_id' => $storeTheme->id, 'restored_from_publication_id' => $publication->id],
                idempotencyKey: "store_theme:{$storeTheme->id}:rolled_back:".Str::ulid(),
            );

            return $storeTheme->fresh();
        });
    }
}
