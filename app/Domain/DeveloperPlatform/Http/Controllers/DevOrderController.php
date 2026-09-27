<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Http\Controllers;

use App\Domain\DeveloperPlatform\Http\Resources\DevOrderResource;
use App\Domain\Orders\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class DevOrderController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(100, max(1, (int) $request->integer('per_page', 25)));

        return DevOrderResource::collection(Order::query()->orderByDesc('created_at')->paginate($perPage));
    }

    public function show(Order $order): DevOrderResource
    {
        return new DevOrderResource($order);
    }
}
