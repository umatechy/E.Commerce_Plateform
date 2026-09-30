<?php

declare(strict_types=1);

namespace App\Domain\Support\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Support\Models\SupportTicket;

/** Who opened (or is replying to) a ticket, as the ticket records them. */
final class SupportRequester
{
    public function __construct(
        public readonly string $type,
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $email,
    ) {}

    public static function customer(Customer $customer): self
    {
        return new self(SupportTicket::REQUESTER_CUSTOMER, $customer->id, $customer->name, $customer->email);
    }

    public static function guest(string $name, string $email): self
    {
        return new self(SupportTicket::REQUESTER_GUEST, null, $name, $email);
    }

    public static function user(User $user): self
    {
        return new self(SupportTicket::REQUESTER_USER, $user->id, $user->name, $user->email);
    }

    /** Whether this is the ticket's own requester (guests are checked by token instead). */
    public function owns(SupportTicket $ticket): bool
    {
        return $ticket->requester_type === $this->type && $this->id !== null && $ticket->requester_id === $this->id;
    }
}
