<?php

declare(strict_types=1);

namespace App\Domain\Help\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B54 (Module 34 §28): "Was this helpful?" for one version of an
 * article. No reader identity is kept; a store's staff feedback carries
 * the store, so it is removed with the store's data.
 */
final class HelpFeedback extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'help_feedback';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['helpful' => 'boolean', 'resolved_at' => 'datetime', 'revision' => 'integer'];
    }

    /** @return BelongsTo<HelpArticle, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(HelpArticle::class, 'help_article_id');
    }
}
