<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One single-use MFA recovery code of a staff user (Module 32 §8.4).
 * Only a keyed hash is stored. Written by MfaService alone.
 *
 * @property int $user_id
 * @property string $code_hash
 * @property ?\Illuminate\Support\Carbon $used_at
 */
final class MfaRecoveryCode extends Model
{
    protected $table = 'user_mfa_recovery_codes';

    protected $fillable = ['user_id', 'code_hash', 'used_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }
}
