<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Domain\Seo\Exceptions\InvalidContentPageTransitionException;
use App\Domain\Seo\Models\ContentPageStatus;
use App\Domain\Seo\Services\ContentPageService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B13 — Content page lifecycle, mandatory sanitization on
 * create/update (Module 16 §23-26, Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ContentPageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_page_starts_as_draft(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $page = app(ContentPageService::class)->create(['title' => 'About', 'slug' => 'about', 'body' => '<p>Hi</p>']);

        $this->assertSame('draft', $page->status->value);
    }

    public function test_body_is_sanitized_on_create(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $page = app(ContentPageService::class)->create(['title' => 'About', 'slug' => 'about', 'body' => '<p>Hi</p><script>bad()</script>']);

        $this->assertStringNotContainsString('<script', $page->body);
    }

    public function test_draft_can_transition_to_published(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $page = app(ContentPageService::class)->create(['title' => 'About', 'slug' => 'about', 'body' => '<p>Hi</p>']);

        $updated = app(ContentPageService::class)->transitionTo($page, ContentPageStatus::Published);

        $this->assertSame('published', $updated->status->value);
        $this->assertNotNull($updated->published_at);
    }

    public function test_archived_is_terminal(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $page = app(ContentPageService::class)->create(['title' => 'About', 'slug' => 'about', 'body' => '<p>Hi</p>']);
        $page = app(ContentPageService::class)->transitionTo($page, ContentPageStatus::Archived);

        $this->expectException(InvalidContentPageTransitionException::class);
        app(ContentPageService::class)->transitionTo($page, ContentPageStatus::Draft);
    }

    public function test_slug_change_via_update_records_a_redirect(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $page = app(ContentPageService::class)->create(['title' => 'About', 'slug' => 'old-about', 'body' => '<p>Hi</p>']);

        app(ContentPageService::class)->update($page, ['title' => 'About', 'slug' => 'new-about', 'body' => '<p>Hi</p>']);

        $this->assertDatabaseHas('redirects', ['source_path' => '/pages/old-about', 'destination_path' => '/pages/new-about']);
    }
}
