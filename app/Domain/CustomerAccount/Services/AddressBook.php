<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccount\Services;

use App\Domain\CustomerAccount\Models\CustomerAddress;
use App\Domain\Orders\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase B25 — a customer's saved addresses. There is always exactly one
 * default while any address exists: the first address becomes the
 * default, and deleting the default promotes the most recent one.
 */
final class AddressBook
{
    public const MAX_ADDRESSES = 20;

    /** @return array<string, list<string>> */
    public static function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'label' => ['nullable', 'string', 'max:50'],
            'name' => [$required, 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s]{4,32}$/'],
            'line1' => [$required, 'string', 'max:190'],
            'line2' => ['nullable', 'string', 'max:190'],
            'city' => [$required, 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => [$required, 'string', 'size:2', 'alpha'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /** @param array<string, mixed> $data validated */
    public function add(Customer $customer, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($customer, $data) {
            $this->lockCustomer($customer);
            $count = CustomerAddress::query()->where('customer_id', $customer->id)->count();

            if ($count >= self::MAX_ADDRESSES) {
                throw ValidationException::withMessages(['address' => 'You can save up to '.self::MAX_ADDRESSES.' addresses.']);
            }

            $address = CustomerAddress::query()->create([
                ...$this->clean($data),
                'store_id' => $customer->store_id,
                'customer_id' => $customer->id,
                'is_default' => false,
            ]);

            if ($count === 0 || ($data['is_default'] ?? false)) {
                $this->makeDefault($address);
            }

            return $address->refresh();
        });
    }

    /** @param array<string, mixed> $data validated */
    public function update(CustomerAddress $address, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($address, $data) {
            $address->update($this->clean($data));

            if ($data['is_default'] ?? false) {
                $this->makeDefault($address);
            }

            return $address->refresh();
        });
    }

    public function remove(CustomerAddress $address): void
    {
        DB::transaction(function () use ($address) {
            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {
                $next = CustomerAddress::query()->where('customer_id', $address->customer_id)->latest('id')->first();
                $next?->update(['is_default' => true]);
            }
        });
    }

    public function makeDefault(CustomerAddress $address): void
    {
        DB::transaction(function () use ($address) {
            CustomerAddress::query()->where('customer_id', $address->customer_id)->where('id', '!=', $address->id)->update(['is_default' => false]);
            $address->update(['is_default' => true]);
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        unset($data['is_default']);

        if (isset($data['country'])) {
            $data['country'] = strtoupper((string) $data['country']);
        }

        return array_map(fn ($value) => is_string($value) ? trim($value) : $value, $data);
    }

    /** Serializes concurrent adds, so the address limit and the single default hold. */
    private function lockCustomer(Customer $customer): void
    {
        Customer::query()->whereKey($customer->id)->lockForUpdate()->value('id');
    }
}
