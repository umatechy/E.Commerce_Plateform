<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tag;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase B39 — Module 06 §35: tags are given by name; a name the store does
 * not have yet becomes a tag. Names are trimmed, 1–60 characters, at most 20
 * per product, matched without regard to case.
 */
final class ProductTagService
{
    public const MAX_PER_PRODUCT = 20;

    /**
     * @param list<string> $names
     * @return list<int> tag ids
     */
    public function idsFor(array $names): array
    {
        $ids = [];
        foreach ($this->normalize($names) as $name) {
            $slug = Str::slug($name) ?: substr(sha1($name), 0, 12); // e.g. an Urdu tag
            $ids[] = Tag::query()->firstOrCreate(['slug' => $slug], ['name' => $name])->id;
        }

        return array_values(array_unique($ids));
    }

    /** @param list<string> $names the product's tags from now on */
    public function sync(Product $product, array $names): void
    {
        $product->tags()->sync($this->idsFor($names));
        $product->touch();
    }

    /** @param list<string> $names */
    public function add(Product $product, array $names): void
    {
        $product->tags()->syncWithoutDetaching($this->idsFor($names));
        if ($product->tags()->count() > self::MAX_PER_PRODUCT) {
            throw ValidationException::withMessages(['tags' => 'A product has at most '.self::MAX_PER_PRODUCT.' tags.']);
        }
        $product->touch();
    }

    /** @param list<string> $names */
    public function remove(Product $product, array $names): void
    {
        $slugs = array_map(fn (string $name) => Str::slug($name) ?: substr(sha1($name), 0, 12), $this->normalize($names));
        $product->tags()->detach(Tag::query()->whereIn('slug', $slugs)->pluck('id')->all());
        $product->touch();
    }

    /**
     * @param list<mixed> $names
     * @return list<string>
     */
    private function normalize(array $names): array
    {
        $clean = [];
        foreach ($names as $name) {
            if (! is_string($name) || ($name = trim(preg_replace('/\s+/u', ' ', $name) ?? '')) === '' || mb_strlen($name) > 60) {
                throw ValidationException::withMessages(['tags' => 'Each tag is 1 to 60 characters.']);
            }
            $clean[mb_strtolower($name)] ??= $name; // the first spelling wins
        }
        if (count($clean) > self::MAX_PER_PRODUCT) {
            throw ValidationException::withMessages(['tags' => 'A product has at most '.self::MAX_PER_PRODUCT.' tags.']);
        }

        return array_values($clean);
    }
}
