<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase B47 — Module 29 §45–46: one movement of a store's account credit
 * with Umar Techy (+ added, − used on an invoice). Append-only: the balance
 * is the sum of the rows; a correction is a new row.
 *
 * @property int $id
 * @property int $store_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $source credit_note | proration | invoice
 * @property ?int $source_id
 * @property ?int $invoice_id
 * @property ?string $note
 * @property \Illuminate\Support\Carbon $created_at
 */
final class BillingCredit extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['store_id', 'amount_minor', 'currency', 'source', 'source_id', 'invoice_id', 'note', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'created_at' => 'datetime'];
    }
}
