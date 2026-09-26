<?php

declare(strict_types=1);

namespace App\Domain\Seo\Models;

/** Module 16 §23 "Content Lifecycle" — the module's own exact list, used verbatim. */
enum ContentPageStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Archived = 'archived';

    public function isPubliclyVisible(): bool
    {
        return $this === self::Published;
    }
}
