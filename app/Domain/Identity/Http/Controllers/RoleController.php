<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Identity\Http\Requests\StoreRoleRequest;
use App\Domain\Identity\Http\Resources\RoleResource;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * First concrete tenant-owned resource CRUD controller (this milestone's
 * "Role API" section). Every method is tenant-safe by construction:
 * BelongsToTenant's global scope means `Role::query()` and route-model
 * binding can only ever resolve the authenticated user's own store's
 * rows; RolePolicy is the second, independent authorization layer.
 *
 * This is deliberately the route the B0 TenantIsolationTest suite was
 * written test-first against — those tests now have real routes to
 * exercise once a runtime is available.
 */
final class RoleController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAbility($request, 'viewAny', Role::class);

        return RoleResource::collection(Role::query()->with('permissions')->get());
    }

    public function show(Request $request, Role $role): RoleResource
    {
        $this->authorizeAbility($request, 'view', $role);

        return new RoleResource($role->load('permissions'));
    }

    public function store(StoreRoleRequest $request): RoleResource
    {
        $this->authorizeAbility($request, 'create', Role::class);

        $role = Role::query()->create([
            // store_id intentionally NOT read from $request — BelongsToTenant
            // auto-fills it from TenantContext on creation (ADR-001 Layer 3).
            'name' => $request->string('name'),
            'slug' => \Illuminate\Support\Str::slug($request->string('name')),
            'is_system' => false,
        ]);

        $permissionIds = Permission::query()
            ->whereIn('key', $request->input('permission_keys', []))
            ->pluck('id');
        $role->permissions()->attach($permissionIds);

        return new RoleResource($role->load('permissions'));
    }

    public function update(StoreRoleRequest $request, Role $role): RoleResource
    {
        $this->authorizeAbility($request, 'update', $role);

        $role->update(['name' => $request->string('name')]);

        if ($request->has('permission_keys')) {
            $permissionIds = Permission::query()
                ->whereIn('key', $request->input('permission_keys', []))
                ->pluck('id');
            $role->permissions()->sync($permissionIds);
        }

        return new RoleResource($role->load('permissions'));
    }

    public function destroy(Request $request, Role $role): \Illuminate\Http\Response
    {
        $this->authorizeAbility($request, 'delete', $role);

        $role->delete();

        return response()->noContent();
    }

    /**
     * Thin wrapper so this controller stays framework-idiomatic
     * (Gate::authorize) without pulling in the full AuthorizesRequests
     * trait's extra surface for this first controller — kept explicit
     * and easy to audit per this milestone's "Every operation must
     * verify ... authorization" requirement.
     */
    private function authorizeAbility(Request $request, string $ability, Role|string $arg): void
    {
        \Illuminate\Support\Facades\Gate::forUser($request->user())->authorize($ability, $arg);
    }
}
