<?php

declare(strict_types=1);

namespace App\Domain\Settings\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-global — NOT tenant-scoped (no BelongsToTenant), mirrors Package/Theme (Phase B2/B15) exactly. "Dumb" — ConfigService is the only writer. */
final class PlatformSetting extends Model
{
    protected $table = 'platform_settings';

    protected $fillable = ['key', 'value', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
