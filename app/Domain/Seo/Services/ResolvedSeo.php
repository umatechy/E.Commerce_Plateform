<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

final class ResolvedSeo
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $metaDescription,
        public readonly string $canonicalUrl,
        public readonly string $ogTitle,
        public readonly ?string $ogDescription,
        public readonly ?string $ogImageUrl,
        public readonly string $robotsIndex,
        public readonly string $robotsFollow,
    ) {}

    public function robotsContent(): string
    {
        return "{$this->robotsIndex}, {$this->robotsFollow}";
    }
}
