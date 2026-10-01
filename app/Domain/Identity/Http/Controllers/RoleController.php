<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Http\Requests\StoreRoleRequest;
use App\Domain\Identity\Http\Resources\RoleResource;
use App\Domain\Identity\Models\InvitationStatus;
use App\Domain\Identity\Models\MembershipStatus;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\StoreInvitation;
use App\Domain\Identity\Models\StoreMembership;
use App\Domain\Packages\Services\EntitlementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Store roles. Every method is tenant-safe by construction:
 * BelongsToTenant's global scope means `Role::query()` and route-model
 * binding can only ever resolve the authenticated user's own store's
 * rows; RolePolicy is the second, independent authorization layer.
 *
 * Phase G1 (Module 02 §17, Module 04 §7): custom roles are a package
 * feature (`custom_roles.enabled`), so creating or editing one needs it;
 * viewing and deleting do not, so a downgraded store keeps control of its
 * existing roles. A role someone holds cannot be deleted (their access
 * would silently disappear), names are unique per store, and every change
 * is audited.
 */
final class RoleController
{
    // Resolved per call: these services hold the request's TenantContext (see TeamController).
    private function entitlements(): EntitlementService
    {
        return app(EntitlementService::class);
    }

    private function audit(): AuditLogger
    {
        return app(AuditLogger::class);
    }

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
        $this->entitlements()->assertFeatureEntitled('custom_roles.enabled');
        $slug = $this->uniqueSlug($request->string('name')->toString());

        $role = Role::query()->create([
            // store_id intentionally NOT read from $request — BelongsToTenant
            // auto-fills it from TenantContext on creation (ADR-001 Layer 3).
            'name' => $request->string('name')->toString(),
            'slug' => $slug,
            'is_system' => false,
        ]);

        $role->permissions()->attach(Permission::query()->whereIn('key', $request->input('permission_keys', []))->pluck('id'));
        $this->audit()->record('role.created', ['slug' => $slug, 'permissions' => $request->input('permission_keys', [])], $role);

        return new RoleResource($role->load('permissions'));
    }

    public function update(StoreRoleRequest $request, Role $role): RoleResource
    {
        $this->authorizeAbility($request, 'update', $role);
        $this->entitlements()->assertFeatureEntitled('custom_roles.enabled');
        $this->uniqueSlug($request->string('name')->toString(), $role);

        $role->update(['name' => $request->string('name')->toString()]);

        if ($request->has('permission_keys')) {
            $role->permissions()->sync(Permission::query()->whereIn('key', $request->input('permission_keys', []))->pluck('id'));
        }
        $this->audit()->record('role.updated', ['slug' => $role->slug, 'permissions' => $role->permissions()->pluck('key')->all()], $role);

        return new RoleResource($role->load('permissions'));
    }

    public function destroy(Request $request, Role $role): \Illuminate\Http\Response
    {
        $this->authorizeAbility($request, 'delete', $role);

        $inUse = StoreMembership::query()->where('role_id', $role->id)->where('status', '!=', MembershipStatus::Revoked)->exists()
            || StoreInvitation::query()->where('role_id', $role->id)->where('status', InvitationStatus::Pending)->exists();
        if ($inUse) {
            throw ValidationException::withMessages(['role' => 'Give the people with this role (or its open invitations) another role first.']);
        }

        // Past invitations keep the role they offered, as history.
        if (StoreInvitation::query()->where('role_id', $role->id)->exists()) {
            throw ValidationException::withMessages(['role' => 'This role appears in past invitations and cannot be deleted. Rename it instead.']);
        }

        // Removed members keep their history row without a role.
        StoreMembership::query()->where('role_id', $role->id)->update(['role_id' => null]);
        $role->delete();
        $this->audit()->record('role.deleted', ['slug' => $role->slug], $role);

        return response()->noContent();
    }

    /** Name → slug, refused if another role in this store already uses it. */
    private function uniqueSlug(string $name, ?Role $except = null): string
    {
        $slug = Str::slug($name);
        $taken = Role::query()->where('slug', $slug)->when($except, fn ($q) => $q->whereKeyNot($except->id))->exists();

        if ($slug === '' || $taken) {
            throw ValidationException::withMessages(['name' => $slug === '' ? 'Use letters or numbers in the role name.' : 'Your store already has a role with this name.']);
        }

        return $slug;
    }

    /**
     * Thin wrapper so this controller stays framework-idiomatic
     * (Gate::authorize) without pulling in the full AuthorizesRequests
     * trait's extra surface.
     */
    private function authorizeAbility(Request $request, string $ability, Role|string $arg): void
    {
        \Illuminate\Support\Facades\Gate::forUser($request->user())->authorize($ability, $arg);
    }
}
