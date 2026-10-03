<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Customers\Exceptions\CustomerActionRefusedException;
use App\Domain\Customers\Models\CustomerGroup;
use App\Domain\Customers\Models\CustomerNote;
use App\Domain\Customers\Models\CustomerSource;
use App\Domain\Customers\Models\CustomerStatus;
use App\Domain\Customers\Models\CustomerTag;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 10 §24–25, §29–32, §58, §96: what staff may change about a
 * customer. Every change is audited with the customer as the subject,
 * which is also what the customer's activity timeline (§33) reads.
 *
 * What staff may NOT change: a registered customer's email or phone
 * (§67: identity changes need the customer's own verification) and
 * anything about an erased customer (Module 32: the personal data is
 * gone on purpose).
 */
final class CustomerManagement
{
    public const MAX_TAGS_PER_CUSTOMER = 20;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * A customer added by staff (§7 "Staff-created Customer"). Such a
     * customer has no password: they cannot sign in until they register
     * themselves, which proves their email.
     *
     * @param array{name: string, email: string, phone?: ?string} $data
     */
    public function create(array $data, User $actor, CustomerSource $source = CustomerSource::Staff): Customer
    {
        $this->assertEmailFree($data['email']);

        $customer = new Customer(['name' => $data['name'], 'email' => mb_strtolower(trim($data['email'])), 'phone' => self::phone($data['phone'] ?? null)]);
        $customer->forceFill(['status' => CustomerStatus::Active, 'source' => $source])->save();

        $this->audit->record('customer.created', ['source' => $source->value], $customer, actor: $actor);

        return $customer;
    }

    /**
     * Name always; email and phone only while the customer has no account
     * of their own (no password), because for them staff are correcting
     * their own data entry, not taking over someone's sign-in.
     *
     * @param array{name?: string, email?: string, phone?: ?string} $data
     */
    public function update(Customer $customer, array $data, User $actor): Customer
    {
        $this->assertNotErased($customer);
        $changed = [];

        if (array_key_exists('name', $data) && $data['name'] !== $customer->name) {
            $customer->name = $data['name'];
            $changed[] = 'name';
        }

        foreach (['email', 'phone'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $value = $field === 'email' ? mb_strtolower(trim((string) $data['email'])) : self::phone($data['phone']);
            if ($value === $customer->{$field}) {
                continue;
            }
            if ($customer->isRegistered()) {
                throw ValidationException::withMessages([$field => 'This customer has an account. Only they can change their '.$field.', and they must confirm it.']);
            }
            if ($field === 'email') {
                $this->assertEmailFree((string) $value, $customer);
            }
            $customer->{$field} = $value;
            $changed[] = $field;
        }

        if ($changed !== []) {
            $customer->save();
            $this->audit->record('customer.updated', ['fields' => $changed], $customer, actor: $actor);
        }

        return $customer;
    }

    /** §30–31: no sign-in, no new orders; the sessions end now. History stays. */
    public function block(Customer $customer, string $reason, User $actor): Customer
    {
        return $this->changeStatus($customer, CustomerStatus::Blocked, $reason, $actor, 'customer.blocked');
    }

    /** §58: no sign-in, no marketing; restorable. */
    public function archive(Customer $customer, ?string $reason, User $actor): Customer
    {
        return $this->changeStatus($customer, CustomerStatus::Archived, $reason, $actor, 'customer.archived');
    }

    /** Back to active from blocked (unblock) or archived (restore). */
    public function reactivate(Customer $customer, User $actor): Customer
    {
        $from = $customer->standing();

        return $this->changeStatus($customer, CustomerStatus::Active, null, $actor, $from === CustomerStatus::Blocked ? 'customer.unblocked' : 'customer.restored');
    }

    public function setGroup(Customer $customer, ?CustomerGroup $group, User $actor): Customer
    {
        $this->assertNotErased($customer);
        if ($customer->customer_group_id === $group?->id) {
            return $customer;
        }

        $customer->forceFill(['customer_group_id' => $group?->id])->save();
        $this->audit->record('customer.group_changed', ['group' => $group?->name], $customer, actor: $actor);

        return $customer->load('group');
    }

    /**
     * Replaces the customer's tags with these names. A name the store does
     * not have yet becomes a new tag; "VIP" and "vip" are the same tag.
     *
     * @param list<string> $names
     */
    public function setTags(Customer $customer, array $names, User $actor): Customer
    {
        $this->assertNotErased($customer);

        $clean = collect($names)->map(fn ($name) => trim((string) preg_replace('/\s+/u', ' ', (string) $name)))->filter(fn ($name) => $name !== '');
        $unique = $clean->unique(fn ($name) => CustomerTag::normalize($name))->values();
        if ($unique->count() > self::MAX_TAGS_PER_CUSTOMER) {
            throw ValidationException::withMessages(['tags' => 'A customer can have at most '.self::MAX_TAGS_PER_CUSTOMER.' tags.']);
        }

        DB::transaction(function () use ($customer, $unique) {
            $ids = $unique->map(fn (string $name) => $this->tag($name)->id)->all();
            $customer->tags()->syncWithPivotValues($ids, ['store_id' => $customer->store_id, 'created_at' => now()]);
        });

        $this->audit->record('customer.tags_changed', ['tags' => $unique->all()], $customer, actor: $actor);

        return $customer->load('tags');
    }

    public function addNote(Customer $customer, string $body, User $actor): CustomerNote
    {
        $this->assertNotErased($customer);

        $note = CustomerNote::query()->create(['customer_id' => $customer->id, 'author_user_id' => $actor->id, 'body' => $body]);
        // The text stays out of the audit trail: it may hold personal details that erasure must be able to remove.
        $this->audit->record('customer.note_added', ['note' => $note->public_id], $customer, actor: $actor);

        return $note->load('author');
    }

    public function deleteNote(CustomerNote $note, User $actor): void
    {
        $customer = $note->customer;
        $note->delete();
        $this->audit->record('customer.note_deleted', ['note' => $note->public_id], $customer, actor: $actor);
    }

    /** The store's tag of this name, created if it does not exist yet. */
    public function tag(string $name): CustomerTag
    {
        return CustomerTag::query()->firstOrCreate(['normalized_name' => CustomerTag::normalize($name)], ['name' => mb_substr($name, 0, 60)]);
    }

    /** A phone as typed, without spaces at either end; empty is no phone. */
    public static function phone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        return $phone === '' ? null : $phone;
    }

    private function changeStatus(Customer $customer, CustomerStatus $to, ?string $reason, User $actor, string $action): Customer
    {
        $this->assertNotErased($customer);
        $from = $customer->standing();

        if ($from === $to) {
            throw new CustomerActionRefusedException("This customer is already {$to->value}.", 'no_change');
        }
        if ($to === CustomerStatus::Archived && $from === CustomerStatus::Blocked) {
            throw new CustomerActionRefusedException('Unblock this customer before archiving them.', 'blocked');
        }

        DB::transaction(function () use ($customer, $to, $reason) {
            $customer->forceFill(['status' => $to, 'status_reason' => $reason, 'status_changed_at' => now()])->save();

            // A customer who may not sign in loses the sessions they have.
            if (! $to->maySignIn()) {
                $customer->tokens()->delete();
            }
        });

        $this->audit->record($action, ['from' => $from->value, 'to' => $to->value, 'reason' => $reason], $customer, actor: $actor);

        return $customer;
    }

    private function assertNotErased(Customer $customer): void
    {
        if ($customer->erased_at !== null) {
            throw new CustomerActionRefusedException('This customer\'s personal data was erased. The record can no longer be changed.', 'erased');
        }
        // Module 10 §56: a merged record is history; its customer lives on in the target.
        if ($customer->merged_into_customer_id !== null) {
            throw new CustomerActionRefusedException('This record was merged into another customer. Make changes there.', 'merged');
        }
    }

    private function assertEmailFree(string $email, ?Customer $except = null): void
    {
        $taken = Customer::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->whereNull('erased_at')
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['email' => 'A customer with this email already exists in your store.']);
        }
    }
}
