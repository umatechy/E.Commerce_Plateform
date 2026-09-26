<?php

declare(strict_types=1);

namespace App\Domain\Seo\Http\Resources;

use App\Domain\Seo\Services\ResolvedSeo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps a plain ResolvedSeo value object (not an Eloquent model) — the resolved, client-ready SEO contract (Module 16 §8). */
final class ResolvedSeoResource extends JsonResource
{
    public function __construct(private readonly ResolvedSeo $seo)
    {
        parent::__construct($seo);
    }

    public function toArray(Request $request): array
    {
        return [
            'title' => $this->seo->title,
            'meta_description' => $this->seo->metaDescription,
            'canonical_url' => $this->seo->canonicalUrl,
            'robots' => $this->seo->robotsContent(),
            'open_graph' => [
                'title' => $this->seo->ogTitle,
                'description' => $this->seo->ogDescription,
                'image_url' => $this->seo->ogImageUrl,
                'url' => $this->seo->canonicalUrl,
                'type' => 'website',
            ],
        ];
    }
}
