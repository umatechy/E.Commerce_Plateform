<?php

declare(strict_types=1);

namespace App\Domain\Help\Services;

use App\Domain\Help\Models\HelpArticle;
use App\Domain\Help\Models\HelpFeedback;
use App\Domain\Help\Models\HelpReleaseNote;
use App\Domain\Help\Support\HelpCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase B54 (Module 34 §6, §8, §11–12, §15, §28–29, §43) — reading the
 * help center: browse, search, page help and one article, always limited
 * to the audiences the reader may see (HelpAudiencePolicy) before anything
 * is matched or counted, so a restricted article never shows up in a
 * result, a count or a suggestion (§62).
 *
 * The library is small (hundreds of articles at most), so search scores
 * the reader's articles in PHP: exact and title matches first, then error
 * codes, tags and keywords, then the text; deprecated articles and
 * articles for another product version are ranked lower (§11.2–11.3).
 */
final class HelpLibrary
{
    public function __construct(private readonly HelpRenderer $renderer) {}

    /**
     * The articles a reader may open, one per key, in their language when
     * a translation exists (English otherwise).
     *
     * @param list<string> $audiences
     * @return Collection<int, HelpArticle>
     */
    public function readable(array $audiences, string $locale = 'en'): Collection
    {
        $articles = HelpArticle::query()->readableBy($audiences)->whereIn('locale', array_unique(['en', $locale]))->orderBy('title')->get();

        return $articles->groupBy('key')
            ->map(fn (Collection $versions) => $versions->firstWhere('locale', $locale) ?? $versions->firstWhere('locale', 'en'))
            ->filter()
            ->values();
    }

    /**
     * @param list<string> $audiences
     * @return array<string, mixed>
     */
    public function home(array $audiences, string $locale, bool $withRelease): array
    {
        $articles = $this->readable($audiences, $locale);
        $categories = [];
        foreach (HelpCatalog::CATEGORIES as $key => $entry) {
            $inCategory = $articles->where('category', $key);
            if ($inCategory->isNotEmpty()) {
                $categories[] = ['key' => $key, 'label' => HelpCatalog::categoryLabel($key, $locale), 'count' => $inCategory->count()];
            }
        }
        // "Frequently useful" (§11.2): most found helpful in the last 90 days.
        $helpful = HelpFeedback::query()
            ->where('helpful', true)->where('created_at', '>=', now()->subDays(90))
            ->whereIn('help_article_id', HelpArticle::query()->readableBy($audiences)->select('id'))
            ->select('help_article_id', DB::raw('count(*) as votes'))->groupBy('help_article_id')->orderByDesc('votes')->limit(20)
            ->pluck('votes', 'help_article_id');
        $keysById = HelpArticle::query()->whereIn('id', $helpful->keys())->pluck('key', 'id');
        $popular = $helpful->keys()->map(fn ($id) => $keysById[$id] ?? null)->filter()->unique()
            ->map(fn (string $key) => $articles->firstWhere('key', $key))->filter()->take(5)->values();

        return [
            'categories' => $categories,
            'getting_started' => $articles->where('category', 'getting_started')->where('status', 'published')->values()->map(fn ($a) => $this->summary($a, $locale))->all(),
            'popular' => $popular->map(fn ($a) => $this->summary($a, $locale))->all(),
            'latest_release' => $withRelease ? $this->latestRelease() : null,
            'product_version' => HelpCatalog::productVersion(),
        ];
    }

    /**
     * @param list<string> $audiences
     * @return list<array<string, mixed>>
     */
    public function category(string $category, array $audiences, string $locale): array
    {
        return $this->readable($audiences, $locale)->where('category', $category)
            ->sortBy(fn (HelpArticle $a) => [$a->status === 'deprecated' ? 1 : 0, $a->type === 'troubleshooting' ? 1 : 0, $a->title])
            ->values()->map(fn ($a) => $this->summary($a, $locale))->all();
    }

    /**
     * @param list<string> $audiences
     * @return list<array<string, mixed>>
     */
    public function search(string $query, array $audiences, string $locale, string $surface): array
    {
        $query = self::normalize($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $tokens = array_values(array_filter(preg_split('/[\s,]+/u', $query) ?: [], fn (string $t) => mb_strlen($t) >= 2));
        $scored = [];
        foreach ($this->readable($audiences, $locale) as $article) {
            $score = $this->score($article, $query, $tokens, $locale);
            if ($score > 0) {
                $scored[] = [$score, $article];
            }
        }
        usort($scored, fn (array $a, array $b) => [$b[0], $a[1]->title] <=> [$a[0], $b[1]->title]);
        $results = array_map(fn (array $s) => $this->summary($s[1], $locale), array_slice($scored, 0, 20));

        // Search analytics (§29): the words and whether anything was found —
        // never who searched. Numbers that could be an order or a phone are hidden.
        DB::table('help_searches')->insert([
            'query' => mb_substr((string) preg_replace(['/\S+@\S+/', '/\d{5,}/'], ['[email]', '#'], $query), 0, 100),
            'surface' => $surface, 'locale' => $locale, 'results' => count($results), 'created_at' => now(),
        ]);

        return $results;
    }

    /**
     * Page help (§12): the articles about the admin page at $path.
     *
     * @param list<string> $audiences
     * @return list<array<string, mixed>>
     */
    public function forPage(string $path, array $audiences): array
    {
        $path = '/'.trim((string) preg_replace('/[?#].*$/', '', $path), '/');
        $matches = [];
        foreach ($this->readable($audiences) as $article) {
            foreach ((array) $article->contexts as $context) {
                $pattern = '#^'.str_replace('\*', '[^/]+', preg_quote('/'.trim((string) $context, '/'), '#')).'$#';
                if (preg_match($pattern, $path) === 1) {
                    // An exact page beats a pattern; a how-to beats a troubleshooting article.
                    $matches[$article->key] = [str_contains((string) $context, '*') ? 1 : 0, $article->type === 'troubleshooting' ? 1 : 0, $article->status === 'deprecated' ? 1 : 0, $article];
                    break;
                }
            }
        }
        uasort($matches, fn (array $a, array $b) => [$a[0], $a[1], $a[2], $a[3]->title] <=> [$b[0], $b[1], $b[2], $b[3]->title]);

        return array_values(array_map(fn (array $m) => $this->summary($m[3], 'en'), array_slice($matches, 0, 6)));
    }

    /**
     * One article for reading, or null when this reader may not open it
     * (answered as "not found", so its existence is not revealed).
     *
     * @param list<string> $audiences
     * @return array<string, mixed>|null
     */
    public function article(string $key, array $audiences, string $locale, string $helpBase, ?string $storeBase): ?array
    {
        $article = $this->readable($audiences, $locale)->firstWhere('key', $key);
        if ($article === null) {
            return null;
        }
        $english = $article->locale === 'en' ? $article : HelpArticle::query()->where('key', $key)->where('locale', 'en')->first();
        $related = $this->readable($audiences, $locale)->whereIn('key', (array) $article->related)->values();
        $replacement = $article->replaced_by === null ? null : $this->readable($audiences, $locale)->firstWhere('key', $article->replaced_by);

        return [
            ...$this->summary($article, $locale),
            'html' => $this->renderer->html((string) $article->body, $helpBase, $storeBase),
            'revision' => $article->revision,
            'owner' => $article->owner,
            'module' => $article->module,
            'tags' => $article->tags,
            'error_codes' => $article->error_codes,
            'applies_until' => $article->applies_until,
            'deprecated_at' => $article->deprecated_at?->toIso8601String(),
            'replaced_by' => $replacement === null ? null : $this->summary($replacement, $locale),
            // A translation made from an older English version is marked (§60.3).
            'translation_outdated' => $article->locale !== 'en' && $english !== null && ($article->translation_source_revision ?? 0) < $english->revision,
            'related' => $related->map(fn ($a) => $this->summary($a, $locale))->all(),
        ];
    }

    /**
     * "Was this helpful?" (§28) for the version the reader saw.
     *
     * @param list<string> $audiences
     */
    public function feedback(string $key, array $audiences, string $locale, bool $helpful, ?string $reason, ?string $comment, string $surface, ?int $storeId): bool
    {
        $article = $this->readable($audiences, $locale)->firstWhere('key', $key);
        if ($article === null) {
            return false;
        }
        $comment = $comment === null ? null : trim((string) preg_replace(['/\S+@\S+\.\S+/', '/(\+?\d[\d\s-]{8,}\d)/'], ['[email removed]', '[number removed]'], $comment));
        HelpFeedback::query()->create([
            'help_article_id' => $article->id, 'revision' => $article->revision, 'locale' => $article->locale,
            'helpful' => $helpful, 'reason' => $helpful ? null : $reason, 'comment' => $comment === '' ? null : mb_substr((string) $comment, 0, 500),
            'surface' => $surface, 'store_id' => $storeId,
        ]);

        return true;
    }

    /** @return array<string, mixed>|null */
    public function latestRelease(): ?array
    {
        $note = HelpReleaseNote::query()->where('status', 'published')->get()->sortByDesc(fn (HelpReleaseNote $n) => self::sortableVersion($n->version))->first();

        return $note === null ? null : $this->release($note);
    }

    /** @return list<array<string, mixed>> */
    public function releases(): array
    {
        return HelpReleaseNote::query()->where('status', 'published')->get()
            ->sortByDesc(fn (HelpReleaseNote $n) => self::sortableVersion($n->version))->values()
            ->map(fn (HelpReleaseNote $n) => $this->release($n))->all();
    }

    /** @return array<string, mixed> */
    public function release(HelpReleaseNote $note): array
    {
        $sections = [];
        foreach (HelpCatalog::RELEASE_SECTIONS as $key => $label) {
            $items = $note->sections[$key] ?? [];
            if ($items !== []) {
                $sections[] = ['key' => $key, 'label' => $label, 'items' => array_map(fn (string $item) => $this->inline($item), $items)];
            }
        }

        return [
            'version' => $note->version, 'title' => $note->title, 'summary' => $note->summary, 'status' => $note->status,
            'released_on' => $note->released_on?->toDateString(), 'sections' => $sections,
        ];
    }

    /** @return array<string, mixed> */
    public function summary(HelpArticle $article, string $locale): array
    {
        return [
            'key' => $article->key,
            'locale' => $article->locale,
            'title' => $article->title,
            'summary' => $article->summary,
            'category' => $article->category,
            'category_label' => HelpCatalog::categoryLabel($article->category, $locale),
            'type' => $article->type,
            'type_label' => HelpCatalog::TYPES[$article->type] ?? $article->type,
            'audience' => $article->audience,
            'status' => $article->status,
            'updated_at' => $article->published_at?->toIso8601String(),
            'applies_from' => $article->applies_from,
            'current_version' => $article->appliesToCurrentVersion(),
        ];
    }

    /** One release-note line: Markdown inline, as safe HTML without the paragraph. */
    private function inline(string $markdown): string
    {
        return (string) preg_replace('#^<p>(.*)</p>$#s', '$1', $this->renderer->html($markdown, '/help/articles', null));
    }

    public static function normalize(string $query): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', mb_substr($query, 0, 200))));
    }

    private static function sortableVersion(string $version): string
    {
        return implode('.', array_map(fn ($p) => str_pad($p, 5, '0', STR_PAD_LEFT), explode('.', $version)));
    }

    /**
     * A word of $text starts with $token ("refund" finds "refunds"); a short
     * token must be the whole word, so "cod" does not find "code".
     */
    private static function hasWord(string $text, string $token): bool
    {
        $end = mb_strlen($token) <= 3 ? '(?![\p{L}\p{N}])' : '';

        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($token, '/').$end.'/u', $text) === 1;
    }

    /** @param list<string> $tokens */
    private function score(HelpArticle $article, string $query, array $tokens, string $locale): float
    {
        $title = mb_strtolower($article->title);
        $summary = mb_strtolower($article->summary);
        $body = mb_strtolower((string) $article->body);
        $terms = array_map('mb_strtolower', [...(array) $article->tags, ...(array) $article->keywords]);
        $codes = array_map('mb_strtolower', (array) $article->error_codes);
        $category = mb_strtolower(HelpCatalog::categoryLabel($article->category, $locale));

        // The whole phrase first.
        $score = 0.0;
        if ($title === $query) {
            $score += 100;
        } elseif (self::hasWord($title, $query)) {
            $score += 60;
        }
        if (in_array($query, $codes, true)) {
            $score += 90; // an error code (§11.1, §25)
        }
        if (in_array($query, $terms, true)) {
            $score += 40;
        }
        if (self::hasWord($summary, $query)) {
            $score += 20;
        }
        $phrase = $score;

        // Then each word.
        $matched = 0;
        foreach ($tokens as $token) {
            $hit = false;
            if (self::hasWord($title, $token)) {
                $score += 12;
                $hit = true;
            }
            foreach ($terms as $term) {
                if (self::hasWord($term, $token)) {
                    $score += 8;
                    $hit = true;
                    break;
                }
            }
            if (self::hasWord($summary, $token)) {
                $score += 5;
                $hit = true;
            }
            if (self::hasWord($category, $token)) {
                $score += 3;
                $hit = true;
            }
            if (self::hasWord($body, $token)) {
                $score += 2;
                $hit = true;
            }
            $matched += $hit ? 1 : 0;
        }
        // Every word must appear somewhere, unless the whole phrase matched.
        if ($phrase === 0.0 && $matched < count($tokens)) {
            return 0;
        }
        if ($article->status === 'deprecated') {
            $score *= 0.3;
        }
        if (! $article->appliesToCurrentVersion()) {
            $score *= 0.5;
        }

        return $score;
    }
}
