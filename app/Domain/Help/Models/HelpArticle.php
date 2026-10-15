<?php

declare(strict_types=1);

namespace App\Domain\Help\Models;

use App\Domain\Help\Support\HelpCatalog;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase B54 (Module 34 §6–9, §71–72): one help article in one language.
 * Its columns hold the live, published content; changes wait as
 * revisions until a reviewer approves them (HelpArticleWorkflow).
 *
 * @property string $key
 * @property string $locale
 * @property string $status
 * @property string $audience
 * @property int $revision
 * @property int $helpful_count from withCount() in the Super Admin list
 * @property int $not_helpful_count
 * @property int $open_feedback_count
 */
final class HelpArticle extends Model
{
    /** The fields a revision carries (Module 34 §7 article metadata). */
    public const CONTENT_FIELDS = [
        'title', 'summary', 'body', 'category', 'type', 'audience', 'module', 'tags', 'keywords', 'error_codes',
        'contexts', 'related', 'applies_from', 'applies_until', 'owner', 'review_due_on',
    ];

    protected $table = 'help_articles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tags' => 'array', 'keywords' => 'array', 'error_codes' => 'array', 'contexts' => 'array', 'related' => 'array',
            'review_due_on' => 'date', 'published_at' => 'datetime', 'deprecated_at' => 'datetime', 'archived_at' => 'datetime',
            'bundle_update_available' => 'boolean', 'revision' => 'integer', 'translation_source_revision' => 'integer',
        ];
    }

    /** @return HasMany<HelpArticleRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(HelpArticleRevision::class)->orderByDesc('revision');
    }

    /** @return HasMany<HelpFeedback, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(HelpFeedback::class);
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * Articles a reader with these audiences may see (Module 34 §6.3–6.4,
     * §11.4, §15): published or deprecated, for this product version.
     *
     * @param Builder<HelpArticle> $query
     * @param list<string> $audiences
     */
    public function scopeReadableBy(Builder $query, array $audiences): void
    {
        $query->whereIn('status', HelpCatalog::READABLE)->whereIn('audience', $audiences);
    }

    public function isReadable(): bool
    {
        return in_array($this->status, HelpCatalog::READABLE, true);
    }

    /** Written for an older or newer product version than this one (Module 34 §8.6). */
    public function appliesToCurrentVersion(): bool
    {
        $current = HelpCatalog::productVersion();

        return HelpCatalog::versionAtMost($this->applies_from, $current)
            && ($this->applies_until === null || HelpCatalog::versionAtMost($current, $this->applies_until));
    }

    /** @return array<string, mixed> the content as a revision stores it */
    public function content(): array
    {
        $content = [];
        foreach (self::CONTENT_FIELDS as $field) {
            $content[$field] = $field === 'review_due_on' ? $this->review_due_on?->toDateString() : $this->getAttribute($field);
        }

        return $content;
    }
}
