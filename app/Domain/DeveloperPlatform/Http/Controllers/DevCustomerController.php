<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Http\Controllers;

use App\Domain\DeveloperPlatform\Http\Resources\DevCustomerResource;
use App\Domain\Orders\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class DevCustomerController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(100, max(1, (int) $request->integer('per_page', 25)));

        return DevCustomerResource::collection(Customer::query()->paginate($perPage));
    }

    public function show(Customer $customer): DevCustomerResource
    {
        return new DevCustomerResource($customer);
    }
}
