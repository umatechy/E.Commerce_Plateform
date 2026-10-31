<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\StoreRequest;
use App\Domain\Tenancy\Services\StoreRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase S1 — Super Admin → Store requests: businesses who asked for a store.
 * Reading needs "view"; following up, removing and creating the store need
 * the `stores` capability (PlatformRoles::ROUTES).
 */
final class SuperAdminStoreRequestController
{
    public function __construct(private readonly StoreRequestService $requests) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(StoreRequest::STATUSES)]]);
        $rows = StoreRequest::query()->with(['store:id,public_id,name,slug', 'handler:id,name'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("FIELD(status, 'new', 'contacted', 'store_created', 'declined')")->orderByDesc('id')->limit(200)->get();

        return response()->json([
            'data' => $rows->map(fn (StoreRequest $r) => [
                'id' => $r->public_id, 'business_name' => $r->business_name, 'contact_name' => $r->contact_name, 'email' => $r->email, 'phone' => $r->phone,
                'city' => $r->city, 'business_category' => $r->business_category, 'package_code' => $r->package_code, 'domain' => $r->domain, 'hosting' => $r->hosting,
                'message' => $r->message, 'status' => $r->status, 'staff_note' => $r->staff_note, 'handled_by' => $r->handler?->name,
                'store' => $r->store === null ? null : ['id' => $r->store->id, 'name' => $r->store->name, 'slug' => $r->store->slug],
                'created_at' => $r->created_at?->toIso8601String(),
            ])->all(),
            'meta' => ['new' => StoreRequest::query()->where('status', 'new')->count()],
        ]);
    }

    public function update(Request $request, StoreRequest $storeRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['new', 'contacted', 'declined'])], // "store created" comes only from creating the store
            'staff_note' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_if($storeRequest->status === 'store_created', 422, 'A store was already created from this request.');
        $this->requests->update($this->user($request), $storeRequest, $data['status'], $data['staff_note'] ?? null);

        return response()->json(['message' => 'Saved.']);
    }

    public function destroy(Request $request, StoreRequest $storeRequest): JsonResponse
    {
        $this->requests->forget($this->user($request), $storeRequest);

        return response()->json(['message' => 'The request and its contact details were removed.']);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
