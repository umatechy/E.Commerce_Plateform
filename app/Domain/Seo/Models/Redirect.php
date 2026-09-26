<?php

declare(strict_types=1);

namespace App\Domain\Seo\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Redirect extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'redirects';

    protected $fillable = ['store_id', 'source_path', 'destination_path', 'status_code', 'is_active', 'reason'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
