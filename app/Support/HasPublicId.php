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

    /**
     * Phase B31 (gap G6): the API returns `public_id` as a row's `id`, but
     * routes bound the numeric key, so a page that used the id it had been
     * given got a 404 (the order "Cancel" and stock "Adjust" buttons did).
     * A ULID in the URL now resolves by public id; a numeric key still
     * resolves as before. The query keeps the model's global scopes, so a
     * row of another store stays a 404, and finding a row is never the
     * authorization: each controller's policy decides.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field === null && $this->getRouteKeyName() === $this->getKeyName()) {
            $value = (string) $value;

            if (Str::isUlid($value)) {
                return $this->resolveRouteBindingQuery($this, $value, 'public_id')->first();
            }

            // Anything else must be a whole number. MySQL would otherwise read
            // "12abc" as 12 and answer with row 12.
            if (! ctype_digit($value)) {
                return null;
            }
        }

        return parent::resolveRouteBinding($value, $field);
    }
}
