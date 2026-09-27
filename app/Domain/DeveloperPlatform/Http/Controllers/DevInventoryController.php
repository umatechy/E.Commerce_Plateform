<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Http\Controllers;

use App\Domain\DeveloperPlatform\Http\Resources\DevInventoryResource;
use App\Domain\Inventory\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class DevInventoryController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(100, max(1, (int) $request->integer('per_page', 25)));

        return DevInventoryResource::collection(Inventory::query()->paginate($perPage));
    }
}
