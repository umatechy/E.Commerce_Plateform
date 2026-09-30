<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccount\Http\Controllers;

use App\Domain\CustomerAccount\Models\CustomerAddress;
use App\Domain\CustomerAccount\Services\AddressBook;
use App\Domain\Orders\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase B25 — the signed-in customer's address book. {address} binds
 * under the tenant scope and must belong to this customer (else 404).
 */
final class CustomerAddressController
{
    public function __construct(private readonly AddressBook $book) {}

    public function index(Request $request): JsonResponse
    {
        $addresses = CustomerAddress::query()->where('customer_id', $this->customer($request)->id)
            ->orderByDesc('is_default')->orderByDesc('id')->get();

        return response()->json(['data' => $addresses->map(fn (CustomerAddress $a) => $this->present($a))]);
    }

    public function store(Request $request): JsonResponse
    {
        $address = $this->book->add($this->customer($request), $request->validate(AddressBook::rules()));

        return response()->json(['data' => $this->present($address)], 201);
    }

    public function update(Request $request, CustomerAddress $address): JsonResponse
    {
        $this->assertOwned($request, $address);

        return response()->json(['data' => $this->present($this->book->update($address, $request->validate(AddressBook::rules(partial: true))))]);
    }

    public function destroy(Request $request, CustomerAddress $address): JsonResponse
    {
        $this->assertOwned($request, $address);
        $this->book->remove($address);

        return response()->json(status: 204);
    }

    public function makeDefault(Request $request, CustomerAddress $address): JsonResponse
    {
        $this->assertOwned($request, $address);
        $this->book->makeDefault($address);

        return response()->json(['data' => $this->present($address->refresh())]);
    }

    /** @return array<string, mixed> */
    private function present(CustomerAddress $address): array
    {
        return ['id' => $address->public_id, 'label' => $address->label, 'is_default' => $address->is_default, ...$address->snapshot()];
    }

    private function assertOwned(Request $request, CustomerAddress $address): void
    {
        abort_unless($address->customer_id === $this->customer($request)->id, 404);
    }

    private function customer(Request $request): Customer
    {
        return $request->user();
    }
}
