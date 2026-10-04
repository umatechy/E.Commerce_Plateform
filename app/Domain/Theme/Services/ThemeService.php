<?php

declare(strict_types=1);

namespace App\Domain\Theme\Services;

use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Theme\Exceptions\StoreThemePublicationNotFoundException;
use App\Domain\Theme\Exceptions\ThemeNotEntitledException;
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
    public function __construct(
        private readonly ThemeConfigValidator $validator,
        private readonly CustomCssSanitizer $cssSanitizer,
        private readonly RecordsOutboxEvents $outbox,
        private readonly ThemeEntitlements $entitlements,
        private readonly StoreMediaService $media,
    ) {}

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
            // Phase B36: a new store starts on Classic with the theme's own
            // colours, fonts, layout and motion (ThemeCatalog); the store's
            // own values are added on top later.
            'theme' => \App\Domain\Theme\Support\ThemeCatalog::DEFAULT,
            'tokens' => [],
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
     * Phase B36: what the package does not include is refused (Module 17
     * §41); a store that may not reorder gets the fixed section order.
     *
     * @throws \App\Domain\Theme\Exceptions\InvalidThemeConfigException
     * @throws ThemeNotEntitledException
     */
    public function updateDraft(StoreTheme $storeTheme, array $config, ?string $customCss, ?int $actorUserId = null): StoreTheme
    {
        $validated = $this->validator->validate($config);
        $validated['theme'] ??= $storeTheme->draft_config['theme'] ?? $storeTheme->theme->key;
        $this->assertEntitled($validated);
        $this->assertOwnMedia($storeTheme, $validated);
        $validated['sections'] = $this->entitlements->normalizeOrder($validated['sections']);

        $customCss = $this->cssSanitizer->sanitize($customCss);

        return DB::transaction(function () use ($storeTheme, $validated, $customCss, $actorUserId) {
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
            $this->audit('theme.draft_updated', $storeTheme, ['theme' => $validated['theme'], 'sections' => count($validated['sections']), 'custom_css_bytes' => strlen((string) $customCss)], $actorUserId);

            return $storeTheme->fresh();
        });
    }

    /** Module 17 §16/§44 "Theme Lifecycle / Theme Publishing" — atomic: the draft becomes the new published config, and a ledger snapshot is recorded, in one transaction. */
    public function publish(StoreTheme $storeTheme, ?int $publishedByUserId): StoreTheme
    {
        // Phase B36: checked again — the package may have changed since the draft was saved.
        $this->assertEntitled($storeTheme->draft_config);

        return DB::transaction(function () use ($storeTheme, $publishedByUserId) {
            $storeTheme->update(['published_config' => $storeTheme->draft_config, 'published_at' => now(), ...$this->themeColumn($storeTheme->draft_config)]);

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
            $this->audit('theme.published', $storeTheme, ['theme' => $storeTheme->draft_config['theme'] ?? null], $publishedByUserId);

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

        // Phase B36: only to a configuration the package still includes (Module 17 §20 "compatible").
        $this->assertEntitled($publication->config);

        return DB::transaction(function () use ($storeTheme, $publication, $publishedByUserId) {
            $storeTheme->update(['draft_config' => $publication->config, 'published_config' => $publication->config, 'published_at' => now(), ...$this->themeColumn($publication->config)]);

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
            $this->audit('theme.rolled_back', $storeTheme, ['restored_from_publication_id' => $publication->id], $publishedByUserId);

            return $storeTheme->fresh();
        });
    }

    /**
     * Module 17 §18 "Theme Selection" (Phase B36): the draft takes the chosen
     * theme with its own colours, fonts, layout and motion; the store's
     * branding and home page sections stay. Publishing makes it live;
     * choosing the current theme again resets it to that theme's defaults.
     *
     * @throws ThemeNotEntitledException
     */
    public function selectTheme(StoreTheme $storeTheme, string $themeKey, ?int $actorUserId): StoreTheme
    {
        $config = $this->validator->validate([
            'theme' => $themeKey,
            'branding' => $storeTheme->draft_config['branding'] ?? [],
            'sections' => $storeTheme->draft_config['sections'] ?? [],
        ]);
        $this->assertEntitled($config);
        $config['sections'] = $this->entitlements->normalizeOrder($config['sections']);

        return DB::transaction(function () use ($storeTheme, $config, $themeKey, $actorUserId) {
            $storeTheme->update(['draft_config' => $config]);
            $this->outbox->recordEventFor(
                $storeTheme->store_id,
                eventType: 'theme.selected',
                payload: ['store_theme_id' => $storeTheme->id, 'theme' => $themeKey],
                idempotencyKey: "store_theme:{$storeTheme->id}:selected:".Str::ulid(),
            );
            $this->audit('theme.selected', $storeTheme, ['theme' => $themeKey], $actorUserId);

            return $storeTheme->fresh();
        });
    }

    /**
     * Phase B37 (Module 17 §7): an uploaded image the theme points to must be
     * one of this store's uploads — never another store's file.
     *
     * @param array<string, mixed> $config
     *
     * @throws \App\Domain\Theme\Exceptions\InvalidThemeConfigException
     */
    private function assertOwnMedia(StoreTheme $storeTheme, array $config): void
    {
        $addresses = [$config['branding']['logo_url'] ?? null, $config['branding']['favicon_url'] ?? null, ...array_map(fn (array $s) => $s['config']['image_url'] ?? null, $config['sections'] ?? [])];
        $store = \App\Domain\Tenancy\Models\Store::query()->findOrFail($storeTheme->store_id);
        foreach (array_filter($addresses, fn ($a) => is_string($a) && str_starts_with($a, '/storage/')) as $address) {
            if (! $this->media->ownsPath($store, $address)) {
                throw new \App\Domain\Theme\Exceptions\InvalidThemeConfigException('An image is not one of your store\x27s uploads: '.$address);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws ThemeNotEntitledException
     */
    private function assertEntitled(array $config): void
    {
        $violations = $this->entitlements->violations($config);
        if ($violations !== []) {
            throw new ThemeNotEntitledException($violations);
        }
    }

    /**
     * The store's theme row follows the published configuration.
     *
     * @param array<string, mixed> $config
     * @return array<string, int>
     */
    private function themeColumn(array $config): array
    {
        $id = isset($config['theme']) ? Theme::query()->where('key', $config['theme'])->value('id') : null;

        return $id === null ? [] : ['theme_id' => (int) $id];
    }

    /** @param array<string, mixed> $context */
    private function audit(string $action, StoreTheme $storeTheme, array $context, ?int $actorUserId): void
    {
        $actor = $actorUserId === null ? null : \App\Domain\Identity\Models\User::query()->find($actorUserId);
        app(\App\Domain\Compliance\Services\AuditLogger::class)->record($action, $context, $storeTheme, $storeTheme->store_id, $actor);
    }
}
