<?php

declare(strict_types=1);

namespace App\Domain\Support\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message in a ticket. Plain text only: it is always shown escaped,
 * never as HTML. Internal notes are never shown to the requester.
 *
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property int $ticket_id
 * @property string $author_type requester | agent | system
 * @property ?int $author_id
 * @property string $author_name
 * @property string $body
 * @property bool $is_internal
 * @property Carbon $created_at
 */
final class SupportMessage extends Model
{
    use BelongsToTenant, HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'support_messages';

    protected $fillable = ['store_id', 'ticket_id', 'author_type', 'author_id', 'author_name', 'body', 'is_internal'];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }
}
