<?php

declare(strict_types=1);

namespace App\Domain\Settings\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** "Dumb" — ConfigService is the only writer. */
final class StoreSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'store_settings';

    protected $fillable = ['store_id', 'key', 'value', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
