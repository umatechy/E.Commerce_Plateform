<?php

declare(strict_types=1);

namespace App\Domain\Help\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase B54 (SRS SUP-006; Module 34 §31–34, §52): the notes of one
 * platform release, sectioned like a changelog. Written in the repository
 * (resources/help/releases) and published from the Super Admin, which sets
 * the release date: a date is never guessed.
 */
final class HelpReleaseNote extends Model
{
    protected $table = 'help_release_notes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sections' => 'array', 'released_on' => 'date', 'published_at' => 'datetime'];
    }
}
