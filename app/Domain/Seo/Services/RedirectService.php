<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

use App\Domain\Seo\Exceptions\InvalidRedirectException;
use App\Domain\Seo\Models\Redirect;

/**
 * Module 16 §11-13 "Redirect Management / Redirect Security" —
 * Non-Negotiable: "prevent open redirect vulnerabilities... redirect
 * loops... cross-tenant destinations." Only internal, store-relative
 * paths are ever accepted — there is no code path anywhere that
 * builds a Redirect pointing at an absolute/external URL, which is
 * what makes cross-tenant/open-redirect structurally impossible here
 * rather than merely checked for.
 */
final class RedirectService
{
    private const MAX_CHAIN_LENGTH = 5;

    /**
     * @throws InvalidRedirectException
     */
    public function create(string $sourcePath, string $destinationPath, int $statusCode = 301, ?string $reason = null): Redirect
    {
        $source = $this->normalize($sourcePath);
        $destination = $this->normalize($destinationPath);

        if (! in_array($statusCode, [301, 302, 307, 308], true)) {
            throw new InvalidRedirectException('Unsupported redirect status code.');
        }

        if ($source === $destination) {
            throw new InvalidRedirectException('A redirect cannot point to itself.');
        }

        $this->assertInternalPath($destination);
        $this->assertNoLoop($source, $destination);

        return Redirect::query()->updateOrCreate(
            ['source_path' => $source],
            ['destination_path' => $destination, 'status_code' => $statusCode, 'is_active' => true, 'reason' => $reason],
        );
    }

    /**
     * Called additively from Product/Category/Brand's own slug-update
     * path (Module 16 §11 "Slug Changes") — never rewrites their
     * existing update logic, only records a redirect afterward.
     */
    public function recordSlugChange(string $entityType, int $entityId, string $oldPath, string $newPath): void
    {
        if ($oldPath === $newPath) {
            return;
        }

        try {
            $this->create($oldPath, $newPath, 301, "slug_changed:{$entityType}:{$entityId}");
        } catch (InvalidRedirectException) {
            // A malformed/looping redirect is silently skipped rather
            // than blocking the slug change itself — the entity's own
            // slug update (its actual purpose) must always succeed;
            // losing one historical redirect is a much smaller harm
            // than refusing a legitimate rename.
        }
    }

    /**
     * @throws InvalidRedirectException
     */
    private function assertInternalPath(string $path): void
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $path) || str_starts_with($path, '//')) {
            throw new InvalidRedirectException('Redirect destinations must be internal, relative paths — external URLs are not allowed.');
        }
    }

    /**
     * @throws InvalidRedirectException
     */
    private function assertNoLoop(string $source, string $destination): void
    {
        $visited = [$source];
        $current = $destination;

        for ($i = 0; $i < self::MAX_CHAIN_LENGTH; $i++) {
            if (in_array($current, $visited, true)) {
                throw new InvalidRedirectException('This redirect would create a loop.');
            }

            $visited[] = $current;
            $next = Redirect::query()->where('source_path', $current)->where('is_active', true)->value('destination_path');

            if ($next === null) {
                return; // chain terminates safely
            }

            $current = $next;
        }

        throw new InvalidRedirectException('This redirect chain is too long.');
    }

    private function normalize(string $path): string
    {
        return '/'.trim($path, '/');
    }
}
