<?php

declare(strict_types=1);

namespace App\Domain\Help\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B54 (Module 34 §9, §72.4): one version of an article. Published
 * revisions are kept, so every version a reader saw can be traced.
 *
 * States: draft → in_review → published (→ superseded by the next one), or
 * rejected back to its author with a note.
 */
final class HelpArticleRevision extends Model
{
    protected $table = 'help_article_revisions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'array', 'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'revision' => 'integer'];
    }

    /** @return BelongsTo<HelpArticle, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(HelpArticle::class, 'help_article_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
