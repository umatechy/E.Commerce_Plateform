<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

use App\Domain\Seo\Exceptions\InvalidContentPageTransitionException;
use App\Domain\Seo\Models\ContentPage;
use App\Domain\Seo\Models\ContentPageStatus;

/**
 * The ONLY code path that creates/updates a ContentPage or transitions
 * its status — mirrors every other domain service's "service-only
 * writes" pattern. `body` is ALWAYS sanitized here before storage
 * (Module 16 §26, Non-Negotiable) — never trusted raw from a
 * controller.
 */
final class ContentPageService
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'draft' => ['scheduled', 'published', 'archived'],
        'scheduled' => ['published', 'draft', 'archived'],
        'published' => ['unpublished', 'archived'],
        'unpublished' => ['published', 'draft', 'archived'],
        // Archived is terminal — no outgoing transitions registered.
    ];

    public function __construct(private readonly ContentSanitizer $sanitizer, private readonly RedirectService $redirects) {}

    public function create(array $data): ContentPage
    {
        return ContentPage::query()->create([...$data, 'body' => $this->sanitizer->sanitize($data['body']), 'status' => ContentPageStatus::Draft]);
    }

    public function update(ContentPage $page, array $data): ContentPage
    {
        $oldSlug = $page->slug;

        if (isset($data['body'])) {
            $data['body'] = $this->sanitizer->sanitize($data['body']);
        }

        $page->update($data);

        // Module 16 §11 "Slug Changes" — additive: never blocks the
        // update itself, just records history afterward.
        if (isset($data['slug']) && $data['slug'] !== $oldSlug) {
            $this->redirects->recordSlugChange('content_page', $page->id, "pages/{$oldSlug}", "pages/{$data['slug']}");
        }

        return $page->fresh();
    }

    /**
     * @throws InvalidContentPageTransitionException
     */
    public function transitionTo(ContentPage $page, ContentPageStatus $to): ContentPage
    {
        $allowed = self::TRANSITIONS[$page->status->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw new InvalidContentPageTransitionException($page->status, $to);
        }

        $extra = $to === ContentPageStatus::Published && $page->published_at === null ? ['published_at' => now()] : [];
        $page->update(['status' => $to, ...$extra]);

        return $page->fresh();
    }
}
