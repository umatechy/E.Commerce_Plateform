<?php

declare(strict_types=1);

namespace App\Domain\Settings\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only — never updated/deleted by application code (Module 33 §9-10/§31, rollback source). */
final class SettingRevision extends Model
{
    protected $table = 'setting_revisions';

    public $timestamps = false; // created_at only, see migration

    protected $fillable = ['scope', 'store_id', 'key', 'value', 'changed_by_user_id', 'reason'];

    protected function casts(): array
    {
        return ['scope' => SettingScope::class, 'value' => 'array', 'created_at' => 'datetime'];
    }
}
