<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $invoice_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_amount_minor
 * @property int $amount_minor
 * @property ?Carbon $period_start
 * @property ?Carbon $period_end
 */
final class InvoiceLine extends Model
{
    protected $table = 'invoice_lines';

    /** Phase B47: `charge` or `credit` (the unused part of the old plan on a proration invoice). */
    protected $fillable = ['invoice_id', 'kind', 'description', 'quantity', 'unit_amount_minor', 'amount_minor', 'period_start', 'period_end'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'amount_minor' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
