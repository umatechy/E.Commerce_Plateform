<?php

declare(strict_types=1);

namespace App\Domain\Support\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Identity\Models\User;
use App\Domain\Support\Models\SupportCategory;
use App\Domain\Support\Models\SupportDesk;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Support\Models\SupportPriority;
use App\Domain\Support\Models\SupportStatus;
use App\Domain\Support\Models\SupportTicket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 34 — the only writer of support tickets and messages.
 *
 * Every change locks the ticket row and runs in one transaction with its
 * outbox event (ADR-004), so a reply, the status it implies and the
 * notification it triggers never disagree. Emails are sent by
 * NotificationEventRouter from those events; the one exception is a
 * guest's acknowledgement, which carries the guest's access link and so
 * is sent directly (SupportNotifier) instead of being put in an event
 * payload.
 */
final class SupportDeskService
{
    public function __construct(
        private readonly TicketNumberGenerator $numbers,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /**
     * @param array{subject: string, category: SupportCategory, message: string, order_id?: ?int, priority?: ?SupportPriority} $data
     * @return array{0: SupportTicket, 1: ?string} the ticket, and a guest's access token (shown once)
     */
    public function open(SupportDesk $desk, int $storeId, SupportRequester $requester, array $data, string $channel = 'web'): array
    {
        if ($requester->id !== null) {
            $active = SupportTicket::query()->withoutTenantScope()
                ->where('store_id', $storeId)->where('desk', $desk->value)
                ->where('requester_type', $requester->type)->where('requester_id', $requester->id)
                ->whereIn('status', [SupportStatus::Open->value, SupportStatus::AwaitingCustomer->value, SupportStatus::OnHold->value])
                ->count();

            if ($active >= (int) config('support.max_open_tickets_per_requester')) {
                throw ValidationException::withMessages(['subject' => 'You have too many open requests. Please reply to an existing one.']);
            }
        }

        $priority = $data['priority'] ?? SupportPriority::Normal;
        $guestToken = $requester->type === SupportTicket::REQUESTER_GUEST ? Str::random(48) : null;

        $ticket = DB::transaction(function () use ($desk, $storeId, $requester, $data, $channel, $priority, $guestToken) {
            $now = now();
            $ticket = SupportTicket::query()->withoutTenantScope()->create([
                'store_id' => $storeId,
                'desk' => $desk,
                'number' => $this->numbers->next($desk, $storeId),
                'subject' => trim($data['subject']),
                'category' => $data['category'],
                'priority' => $priority,
                'status' => SupportStatus::Open,
                'channel' => $channel,
                'requester_type' => $requester->type,
                'requester_id' => $requester->id,
                'requester_name' => $requester->name,
                'requester_email' => $requester->email,
                'order_id' => $data['order_id'] ?? null,
                'guest_token_hash' => $guestToken !== null ? hash('sha256', $guestToken) : null,
                'first_response_due_at' => $now->copy()->addHours($priority->firstResponseHours()),
                'resolution_due_at' => $now->copy()->addHours($priority->resolutionHours()),
                'last_requester_activity_at' => $now,
            ]);

            $this->addMessage($ticket, 'requester', $requester->id, $requester->name, $data['message'], false);
            $this->event($ticket, 'support.ticket_created');

            return $ticket;
        });

        return [$ticket, $guestToken];
    }

    /** @throws SupportActionRefusedException */
    public function replyAsRequester(SupportTicket $ticket, string $authorName, ?int $authorId, string $body): SupportMessage
    {
        return DB::transaction(function () use ($ticket, $authorName, $authorId, $body) {
            $ticket = $this->lock($ticket);
            $this->assertRequesterMayReply($ticket);

            $message = $this->addMessage($ticket, 'requester', $authorId, $authorName, $body, false);
            $ticket->update([
                // A reply from the requester always puts the ball back with the team.
                'status' => SupportStatus::Open,
                'resolved_at' => null,
                'last_requester_activity_at' => now(),
            ]);
            $this->event($ticket, 'support.requester_replied', ['message_id' => $message->id]);

            return $message;
        });
    }

    /**
     * An agent's reply or internal note. A public reply without an
     * explicit status waits for the customer; the first public reply
     * stops the first-response clock; an unassigned ticket is taken by
     * the replying agent.
     *
     * @throws SupportActionRefusedException
     */
    public function replyAsAgent(SupportTicket $ticket, User $agent, string $body, bool $internal, ?SupportStatus $status): SupportMessage
    {
        return DB::transaction(function () use ($ticket, $agent, $body, $internal, $status) {
            $ticket = $this->lock($ticket);

            if ($ticket->status === SupportStatus::Closed) {
                throw new SupportActionRefusedException('ticket_closed', 'This ticket is closed. Reopen it before replying.');
            }

            $message = $this->addMessage($ticket, 'agent', $agent->id, $agent->name, $body, $internal);
            $changes = ['assignee_id' => $ticket->assignee_id ?? $agent->id];

            if (! $internal) {
                $changes += [
                    'first_responded_at' => $ticket->first_responded_at ?? now(),
                    'last_agent_activity_at' => now(),
                ];
            }

            $newStatus = $status ?? ($internal ? null : SupportStatus::AwaitingCustomer);
            if ($newStatus !== null) {
                $changes += $this->statusChanges($newStatus);
            }

            $ticket->update($changes);

            if (! $internal) {
                $this->event($ticket, 'support.agent_replied', ['message_id' => $message->id]);
            }

            return $message;
        });
    }

    /**
     * Team-side changes: status, priority, category, assignee. A new
     * priority re-times the SLA clocks that are still running.
     *
     * @param array{status?: SupportStatus, priority?: SupportPriority, category?: SupportCategory, assignee_id?: ?int} $changes
     */
    public function update(SupportTicket $ticket, User $agent, array $changes): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $agent, $changes) {
            $ticket = $this->lock($ticket);
            $before = $this->snapshot($ticket);
            $attributes = [];

            if (isset($changes['status']) && $changes['status'] !== $ticket->status) {
                $attributes += $this->statusChanges($changes['status']);
            }

            if (isset($changes['priority']) && $changes['priority'] !== $ticket->priority) {
                $attributes['priority'] = $changes['priority'];
                $opened = Carbon::instance($ticket->created_at);
                if ($ticket->first_responded_at === null) {
                    $attributes['first_response_due_at'] = $opened->copy()->addHours($changes['priority']->firstResponseHours());
                }
                if ($ticket->resolved_at === null) {
                    $attributes['resolution_due_at'] = $opened->copy()->addHours($changes['priority']->resolutionHours());
                }
            }

            if (isset($changes['category'])) {
                $attributes['category'] = $changes['category'];
            }

            if (array_key_exists('assignee_id', $changes)) {
                $attributes['assignee_id'] = $changes['assignee_id'];
            }

            if ($attributes === []) {
                return $ticket;
            }

            $ticket->update($attributes);

            app(AuditLogger::class)->record('support.ticket_updated', [
                'ticket' => $ticket->number, 'desk' => $ticket->desk, 'before' => $before, 'after' => $this->snapshot($ticket),
            ], $ticket, $ticket->store_id, $agent);

            if (isset($attributes['status'])) {
                $this->event($ticket, 'support.ticket_status_changed', ['status' => $ticket->status->value]);
            }

            return $ticket;
        });
    }

    /** The requester says the problem is solved. */
    public function resolveByRequester(SupportTicket $ticket): SupportTicket
    {
        return DB::transaction(function () use ($ticket) {
            $ticket = $this->lock($ticket);

            if (! $ticket->status->isActive()) {
                throw new SupportActionRefusedException('not_active', 'This ticket is already resolved or closed.');
            }

            $ticket->update([...$this->statusChanges(SupportStatus::Resolved), 'last_requester_activity_at' => now()]);
            $this->event($ticket, 'support.ticket_status_changed', ['status' => SupportStatus::Resolved->value]);

            return $ticket;
        });
    }

    /** Customer satisfaction, once per ticket, after it is resolved. */
    public function rate(SupportTicket $ticket, int $rating, ?string $comment): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $rating, $comment) {
            $ticket = $this->lock($ticket);

            if ($ticket->status->isActive()) {
                throw new SupportActionRefusedException('not_resolved', 'A ticket can be rated once it is resolved.');
            }
            if ($ticket->satisfaction_rating !== null) {
                throw new SupportActionRefusedException('already_rated', 'This ticket has already been rated.');
            }

            $ticket->update(['satisfaction_rating' => $rating, 'satisfaction_comment' => $comment]);

            return $ticket;
        });
    }

    /**
     * `support:maintain` (hourly): flags tickets that missed a service
     * level, and closes resolved tickets once the reopen window ends.
     *
     * @return array{breached: int, closed: int}
     */
    public function maintain(): array
    {
        $now = now();
        $breached = 0;

        $overdue = SupportTicket::query()->withoutTenantScope()
            ->whereNull('sla_breached_at')
            ->whereIn('status', [SupportStatus::Open->value, SupportStatus::AwaitingCustomer->value, SupportStatus::OnHold->value])
            ->where(fn ($q) => $q->where(fn ($f) => $f->whereNull('first_responded_at')->where('first_response_due_at', '<=', $now))
                ->orWhere('resolution_due_at', '<=', $now))
            ->pluck('id');

        foreach ($overdue as $id) {
            DB::transaction(function () use ($id, $now, &$breached) {
                $ticket = SupportTicket::query()->withoutTenantScope()->lockForUpdate()->find($id);
                if ($ticket === null || $ticket->sla_breached_at !== null) {
                    return;
                }
                $ticket->update(['sla_breached_at' => $now]);
                $this->event($ticket, 'support.sla_breached', [
                    'kind' => $ticket->first_responded_at === null ? 'first_response' : 'resolution',
                ]);
                $breached++;
            });
        }

        $closed = SupportTicket::query()->withoutTenantScope()
            ->where('status', SupportStatus::Resolved->value)
            ->where('resolved_at', '<=', $now->copy()->subDays((int) config('support.reopen_days')))
            ->update(['status' => SupportStatus::Closed->value, 'closed_at' => $now, 'updated_at' => $now]);

        return ['breached' => $breached, 'closed' => $closed];
    }

    public function guestTokenMatches(SupportTicket $ticket, string $token): bool
    {
        return $ticket->guest_token_hash !== null && hash_equals($ticket->guest_token_hash, hash('sha256', $token));
    }

    /** @throws SupportActionRefusedException */
    public function assertRequesterMayReply(SupportTicket $ticket): void
    {
        $expired = $ticket->status === SupportStatus::Resolved && $ticket->resolved_at !== null
            && $ticket->resolved_at->lessThan(now()->subDays((int) config('support.reopen_days')));

        if ($ticket->status === SupportStatus::Closed || $expired) {
            throw new SupportActionRefusedException('ticket_closed', 'This request is closed. Please open a new one.');
        }
    }

    /** @return array<string, mixed> */
    private function statusChanges(SupportStatus $status): array
    {
        return [
            'status' => $status,
            'resolved_at' => $status === SupportStatus::Resolved ? now() : null,
            'closed_at' => $status === SupportStatus::Closed ? now() : null,
        ];
    }

    private function addMessage(SupportTicket $ticket, string $authorType, ?int $authorId, string $authorName, string $body, bool $internal): SupportMessage
    {
        return SupportMessage::query()->withoutTenantScope()->create([
            'store_id' => $ticket->store_id,
            'ticket_id' => $ticket->id,
            'author_type' => $authorType,
            'author_id' => $authorId,
            'author_name' => $authorName,
            'body' => trim($body),
            'is_internal' => $internal,
        ]);
    }

    private function lock(SupportTicket $ticket): SupportTicket
    {
        return SupportTicket::query()->withoutTenantScope()->lockForUpdate()->findOrFail($ticket->id);
    }

    /** @param array<string, mixed> $extra */
    private function event(SupportTicket $ticket, string $type, array $extra = []): void
    {
        $suffix = isset($extra['message_id']) ? "message:{$extra['message_id']}" : $type.':'.now()->format('U.u');

        $this->outbox->recordEventFor($ticket->store_id, $type, [
            'ticket_id' => $ticket->id,
            'ticket_public_id' => $ticket->public_id,
            'number' => $ticket->number,
            'desk' => $ticket->desk->value,
            ...$extra,
        ], "support:{$ticket->id}:{$suffix}");
    }

    /** @return array<string, mixed> */
    private function snapshot(SupportTicket $ticket): array
    {
        return [
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'category' => $ticket->category->value,
            'assignee_id' => $ticket->assignee_id,
        ];
    }
}
