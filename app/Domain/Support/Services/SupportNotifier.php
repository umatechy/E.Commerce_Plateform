<?php

declare(strict_types=1);

namespace App\Domain\Support\Services;

use App\Domain\Billing\Services\BillingContact;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Seo\Services\SeoResolver;
use App\Domain\Support\Models\SupportDesk;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Tenancy\Models\Store;

/**
 * Module 34 — support emails. Called by NotificationEventRouter for the
 * support outbox events (in the ticket's store context), and directly
 * for a guest's acknowledgement, whose access link must never sit in an
 * outbox payload.
 *
 * Message bodies are plain text; NotificationService escapes every
 * variable in email output.
 */
final class SupportNotifier
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SeoResolver $seo,
        private readonly BillingContact $contacts,
    ) {}

    /** @param array<string, mixed> $payload */
    public function route(string $eventType, array $payload): void
    {
        $ticket = SupportTicket::query()->withoutTenantScope()->find($payload['ticket_id'] ?? 0);

        if ($ticket === null) {
            return;
        }

        match ($eventType) {
            'support.ticket_created' => $this->created($ticket),
            'support.agent_replied' => $this->agentReplied($ticket, SupportMessage::query()->withoutTenantScope()->find($payload['message_id'] ?? 0)),
            'support.requester_replied' => $this->requesterReplied($ticket, SupportMessage::query()->withoutTenantScope()->find($payload['message_id'] ?? 0)),
            'support.sla_breached' => $this->slaBreached($ticket, (string) ($payload['kind'] ?? 'first_response')),
            default => null,
        };
    }

    /** The guest's receipt, with the private link to follow the ticket. */
    public function guestAcknowledgement(SupportTicket $ticket, string $token): void
    {
        // In the #fragment: browsers never send it, so it stays out of requests, logs and Referer headers.
        $link = $this->storefrontUrl($ticket, "support/tickets/{$ticket->public_id}#token={$token}");

        $this->toRequester($ticket, "ticket:{$ticket->id}:created",
            'We received your request {{ticket.number}}',
            'Hi {{requester.name}}, thanks for contacting {{store.name}}. Your request "{{ticket.subject}}" has the reference {{ticket.number}}. You can follow it and reply here: {{ticket.link}} Keep this link private: anyone with it can read the conversation.',
            [], secrets: ['ticket.link' => $link]); // the token never sits readable in notification_messages (Phase G1)
    }

    private function created(SupportTicket $ticket): void
    {
        if ($ticket->requester_type !== SupportTicket::REQUESTER_GUEST) {
            $this->toRequester($ticket, "ticket:{$ticket->id}:created",
                'We received your request {{ticket.number}}',
                'Hi {{requester.name}}, thanks for contacting {{store.name}}. Your request "{{ticket.subject}}" has the reference {{ticket.number}}. You can follow it here: {{ticket.link}}',
                ['ticket.link' => $this->requesterLink($ticket)]);
        }

        // The store's team hears about new shopper requests.
        if ($ticket->desk === SupportDesk::Store && ($owner = $this->contacts->ownerOf($ticket->store_id)) !== null) {
            $this->toUser($ticket, $owner, "ticket:{$ticket->id}:created:team",
                'New support request {{ticket.number}}: {{ticket.subject}}',
                'A new support request {{ticket.number}} from {{requester.name}} is waiting: {{ticket.link}}',
                ['ticket.link' => $this->adminLink($ticket)]);
        }
    }

    private function agentReplied(SupportTicket $ticket, ?SupportMessage $message): void
    {
        if ($message === null || $message->is_internal) {
            return;
        }

        $this->toRequester($ticket, "ticket:{$ticket->id}:message:{$message->id}",
            'Re: {{ticket.subject}} [{{ticket.number}}]',
            '{{agent.name}} replied to your request {{ticket.number}}: {{message.body}} {{ticket.follow}}',
            [
                'agent.name' => (string) strtok($message->author_name, ' '),
                'message.body' => $message->body,
                'ticket.follow' => $ticket->requester_type === SupportTicket::REQUESTER_GUEST
                    ? 'To reply, use the private link from your first email.'
                    : 'Reply here: '.$this->requesterLink($ticket),
            ]);
    }

    private function requesterReplied(SupportTicket $ticket, ?SupportMessage $message): void
    {
        $recipient = $ticket->assignee
            ?? ($ticket->desk === SupportDesk::Store ? $this->contacts->ownerOf($ticket->store_id) : null);

        if ($message === null || $recipient === null) {
            return;
        }

        $this->toUser($ticket, $recipient, "ticket:{$ticket->id}:message:{$message->id}:team",
            'New reply on {{ticket.number}}: {{ticket.subject}}',
            '{{requester.name}} replied: {{message.body}} Open the ticket: {{ticket.link}}',
            ['message.body' => $message->body, 'ticket.link' => $this->adminLink($ticket)]);
    }

    private function slaBreached(SupportTicket $ticket, string $kind): void
    {
        $recipient = $ticket->assignee ?? ($ticket->desk === SupportDesk::Store ? $this->contacts->ownerOf($ticket->store_id) : null);

        if ($recipient === null) {
            return;
        }

        $this->toUser($ticket, $recipient, "ticket:{$ticket->id}:sla",
            'Overdue: {{ticket.number}} {{ticket.subject}}',
            'Support request {{ticket.number}} has missed its {{sla.kind}} target. Open it: {{ticket.link}}',
            ['sla.kind' => $kind === 'first_response' ? 'first-response' : 'resolution', 'ticket.link' => $this->adminLink($ticket)]);
    }

    /** @param array<string, scalar> $variables */
    private function toRequester(SupportTicket $ticket, string $key, string $subject, string $body, array $variables, array $secrets = []): void
    {
        $this->notifications->send(
            NotificationMessageType::Transactional, NotificationChannel::Email,
            $ticket->requester_type === SupportTicket::REQUESTER_USER ? RecipientType::User : RecipientType::Customer,
            $ticket->requester_id, $ticket->requester_email,
            $subject, $body, [...$this->common($ticket), ...$variables], "support:{$key}", 'support', $secrets,
        );
    }

    /** @param array<string, scalar> $variables */
    private function toUser(SupportTicket $ticket, User $user, string $key, string $subject, string $body, array $variables): void
    {
        $this->notifications->send(
            NotificationMessageType::Transactional, NotificationChannel::Email,
            RecipientType::User, $user->id, $user->email,
            $subject, $body, [...$this->common($ticket), ...$variables], "support:{$key}:user:{$user->id}", 'support',
        );
    }

    /** @return array<string, scalar> */
    private function common(SupportTicket $ticket): array
    {
        return [
            'store.name' => (string) Store::query()->withTrashed()->whereKey($ticket->store_id)->value('name'),
            'requester.name' => $ticket->requester_name,
            'ticket.number' => $ticket->number,
            'ticket.subject' => $ticket->subject,
        ];
    }

    private function requesterLink(SupportTicket $ticket): string
    {
        return $ticket->desk === SupportDesk::Platform
            ? url("/support/platform?ticket={$ticket->public_id}")
            : $this->storefrontUrl($ticket, "account/support/{$ticket->public_id}");
    }

    private function adminLink(SupportTicket $ticket): string
    {
        return $ticket->desk === SupportDesk::Platform
            ? url("/super-admin/support?ticket={$ticket->public_id}")
            : url("/support?ticket={$ticket->public_id}");
    }

    private function storefrontUrl(SupportTicket $ticket, string $path): string
    {
        $store = Store::query()->withTrashed()->findOrFail($ticket->store_id);

        return rtrim($this->seo->forStoreHome($store)->canonicalUrl, '/').'/'.$path;
    }
}
