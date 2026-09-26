<?php

declare(strict_types=1);

namespace App\Domain\Seo\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** "Dumb" like every other core-state model — ContentPageService is the only writer of `status`. */
final class ContentPage extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'content_pages';

    protected $fillable = ['store_id', 'title', 'slug', 'body', 'status', 'published_at'];

    protected function casts(): array
    {
        return ['status' => ContentPageStatus::class, 'published_at' => 'datetime'];
    }
}
