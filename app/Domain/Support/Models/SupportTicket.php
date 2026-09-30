<?php

declare(strict_types=1);

namespace App\Domain\Support\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Module 34 — one support conversation. Tenant-scoped: a store sees only
 * its own tickets on either desk; platform staff reach every store's
 * platform-desk tickets from platform context. Only SupportDeskService
 * changes a ticket.
 *
 * @property int $id
 * @property string $public_id
 * @property int $store_id
 * @property SupportDesk $desk
 * @property string $number
 * @property string $subject
 * @property SupportCategory $category
 * @property SupportPriority $priority
 * @property SupportStatus $status
 * @property string $channel
 * @property string $requester_type
 * @property ?int $requester_id
 * @property string $requester_name
 * @property string $requester_email
 * @property ?int $order_id
 * @property ?int $assignee_id
 * @property ?string $guest_token_hash
 * @property ?Carbon $first_response_due_at
 * @property ?Carbon $first_responded_at
 * @property ?Carbon $resolution_due_at
 * @property ?Carbon $sla_breached_at
 * @property ?Carbon $last_requester_activity_at
 * @property ?Carbon $last_agent_activity_at
 * @property ?Carbon $resolved_at
 * @property ?Carbon $closed_at
 * @property ?int $satisfaction_rating
 * @property ?string $satisfaction_comment
 */
final class SupportTicket extends Model
{
    use BelongsToTenant, HasPublicId;

    public const REQUESTER_CUSTOMER = 'customer';
    public const REQUESTER_GUEST = 'guest';
    public const REQUESTER_USER = 'user';

    protected $table = 'support_tickets';

    protected $fillable = [
        'store_id', 'desk', 'number', 'subject', 'category', 'priority', 'status', 'channel',
        'requester_type', 'requester_id', 'requester_name', 'requester_email', 'order_id', 'assignee_id',
        'guest_token_hash', 'first_response_due_at', 'first_responded_at', 'resolution_due_at', 'sla_breached_at',
        'last_requester_activity_at', 'last_agent_activity_at', 'resolved_at', 'closed_at',
        'satisfaction_rating', 'satisfaction_comment',
    ];

    protected $hidden = ['guest_token_hash'];

    protected function casts(): array
    {
        return [
            'desk' => SupportDesk::class,
            'category' => SupportCategory::class,
            'priority' => SupportPriority::class,
            'status' => SupportStatus::class,
            'first_response_due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'sla_breached_at' => 'datetime',
            'last_requester_activity_at' => 'datetime',
            'last_agent_activity_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'satisfaction_rating' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return HasMany<SupportMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
