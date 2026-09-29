<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * ADR-003 §8: every externally-referenced row carries a ULID in its
 * indexed `public_id` column, "generated at creation time".
 *
 * Before this trait existed nothing generated it except three services
 * that set it by hand (ApplicationService, ApiKeyService, BackupService),
 * so every other insert — Store, User, Product, Order, ... — failed with
 * MySQL error 1364 ("Field 'public_id' doesn't have a default value") on
 * the first real run. A caller-supplied value is kept as-is, so those
 * explicit call sites keep working unchanged.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model): void {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }
}
