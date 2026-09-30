<?php

declare(strict_types=1);

namespace App\Domain\Support\Services;

use App\Domain\Support\Models\SupportDesk;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Support\Models\SupportStatus;
use App\Domain\Support\Models\SupportTicket;

/**
 * Module 34 — the two views of a ticket. The requester's view never
 * contains internal notes, the assignee, SLA data or staff emails, and
 * names agents by first name only; the team's view has everything.
 */
final class SupportPresenter
{
    public function __construct(private readonly SupportDeskService $desk) {}

    /** @return array<string, mixed> */
    public function summary(SupportTicket $ticket, bool $forAgent): array
    {
        $base = [
            'id' => $ticket->public_id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'category' => $ticket->category->value,
            'status' => $ticket->status->value,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
        ];

        if (! $forAgent) {
            return $base;
        }

        return [
            ...$base,
            'desk' => $ticket->desk->value,
            'store' => $ticket->desk === SupportDesk::Platform && $ticket->store !== null
                ? ['id' => $ticket->store->public_id, 'name' => $ticket->store->name] : null,
            'priority' => $ticket->priority->value,
            'channel' => $ticket->channel,
            'requester' => ['type' => $ticket->requester_type, 'name' => $ticket->requester_name, 'email' => $ticket->requester_email],
            'assignee' => $ticket->assignee !== null ? ['id' => $ticket->assignee->public_id, 'name' => $ticket->assignee->name] : null,
            'sla' => [
                'first_response_due_at' => $ticket->first_response_due_at?->toIso8601String(),
                'first_responded_at' => $ticket->first_responded_at?->toIso8601String(),
                'resolution_due_at' => $ticket->resolution_due_at?->toIso8601String(),
                'breached' => $ticket->sla_breached_at !== null,
            ],
            'satisfaction_rating' => $ticket->satisfaction_rating,
            'last_requester_activity_at' => $ticket->last_requester_activity_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(SupportTicket $ticket, bool $forAgent): array
    {
        $messages = $ticket->messages->filter(fn (SupportMessage $m) => $forAgent || ! $m->is_internal);
        $order = $ticket->order;
        $canReply = true;

        try {
            $this->desk->assertRequesterMayReply($ticket);
        } catch (SupportActionRefusedException) {
            $canReply = false;
        }

        return [
            ...$this->summary($ticket, $forAgent),
            'order' => $order === null ? null : array_filter([
                'id' => $order->public_id,
                'number' => $order->order_number,
                'status' => $forAgent ? $order->status->value : null,
                'grand_total_minor' => $forAgent ? $order->grand_total_minor : null,
                'currency' => $forAgent ? $order->currency : null,
            ], fn ($v) => $v !== null),
            'messages' => $messages->map(fn (SupportMessage $m) => [
                'id' => $m->public_id,
                'author_type' => $m->author_type,
                'author_name' => $forAgent || $m->author_type !== 'agent' ? $m->author_name : strtok($m->author_name, ' '),
                'body' => $m->body,
                'internal' => $forAgent ? $m->is_internal : null,
                'created_at' => $m->created_at->toIso8601String(),
            ])->map(fn (array $m) => $forAgent ? $m : array_diff_key($m, ['internal' => true]))->values()->all(),
            'can_reply' => $ticket->status === SupportStatus::Closed ? false : ($forAgent || $canReply),
            'can_resolve' => $ticket->status->isActive(),
            'can_rate' => ! $ticket->status->isActive() && $ticket->satisfaction_rating === null,
            'satisfaction' => $ticket->satisfaction_rating === null ? null : [
                'rating' => $ticket->satisfaction_rating,
                'comment' => $ticket->satisfaction_comment,
            ],
        ];
    }
}
